#!/usr/bin/env bash
#
# install-on-ec2.sh — Run this ON the Amazon Linux 2023 EC2 instance,
# from inside the unpacked "magento-migrate" directory.
#
# It will:
#   1. Pre-flight checks (PHP 8.4, MariaDB, OpenSearch)
#   2. Extract the Magento code + media into APP_DIR
#   3. Create the database + user, import the dump (auto-fixes collations
#      if your MariaDB is older than 11.4)
#   4. Write a fresh app/etc/env.php for this box (localhost DB, no Redis,
#      no RabbitMQ, local OpenSearch) while PRESERVING the crypt key
#   5. Rewrite the store base URL
#   6. Fix permissions, run setup:upgrade, reindex, flush cache
#
# Everything is configurable via env vars or flags — see usage() below.
#
set -euo pipefail

# ---------------------------------------------------------------- config ----
APP_DIR="${APP_DIR:-/var/www/magento}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_NAME="${DB_NAME:-magento}"
DB_USER="${DB_USER:-magento}"
DB_PASS="${DB_PASS:-}"                       # if empty, a strong one is generated
BASE_URL="${BASE_URL:-}"                     # e.g. http://1.2.3.4/  (required)
SEARCH_ENGINE="${SEARCH_ENGINE:-opensearch}" # opensearch | elasticsearch8
SEARCH_HOST="${SEARCH_HOST:-localhost}"      # hostname only, no scheme (e.g. xxx.aivencloud.com)
SEARCH_PORT="${SEARCH_PORT:-9200}"
SEARCH_SCHEME="${SEARCH_SCHEME:-http}"       # http | https  (managed/Aiven = https)
SEARCH_USER="${SEARCH_USER:-}"               # auth username (managed OpenSearch)
SEARCH_PASS="${SEARCH_PASS:-}"               # auth password
SEARCH_CA_CERT="${SEARCH_CA_CERT:-}"         # path to a CA cert (e.g. Aiven ca.pem) to add to the trust store
SEARCH_INDEX_PREFIX="${SEARCH_INDEX_PREFIX:-}" # optional OpenSearch index prefix
SEARCH_TLS_INSECURE="${SEARCH_TLS_INSECURE:-0}" # 1 = skip TLS verify in the reachability check ONLY
RUN_USER="${RUN_USER:-apache}"               # OS user that OWNS the files & runs bin/magento (apache/nginx/magento)
FILE_GROUP="${FILE_GROUP:-$RUN_USER}"        # group owner of the files (e.g. apache)

usage() {
  cat <<EOF
Usage: BASE_URL=http://<ip>/ ./install-on-ec2.sh [options]

Environment variables (all optional except BASE_URL):
  APP_DIR=/var/www/magento     Target install directory
  BASE_URL=http://1.2.3.4/     Public URL of the shop (REQUIRED)
  DB_HOST=127.0.0.1            MariaDB host
  DB_NAME=magento             Database name (created if missing)
  DB_USER=magento             Database user (created if missing)
  DB_PASS=...                 DB user password (auto-generated if unset)
  RUN_USER=apache             OS user that owns the files & runs bin/magento (apache/nginx/magento)
  FILE_GROUP=apache           Group owner of the files (defaults to RUN_USER)
  SEARCH_ENGINE=opensearch    External search engine: opensearch | elasticsearch8
  SEARCH_HOST=localhost       Search engine host (hostname only, no scheme)
  SEARCH_PORT=9200            Search engine port
  SEARCH_SCHEME=http          http | https  (managed services like Aiven use https)
  SEARCH_USER=                Auth username (managed OpenSearch)
  SEARCH_PASS=                Auth password
  SEARCH_CA_CERT=             Path to a CA cert (e.g. Aiven ca.pem) added to the trust store
  SEARCH_INDEX_PREFIX=        Optional OpenSearch index prefix

This installer NEVER installs a search engine — it points Magento at your
external OpenSearch/Elasticsearch and only verifies it is reachable.

Example — managed OpenSearch on Aiven (files owned by magento:apache):
  BASE_URL=http://<ip>/ \\
    RUN_USER=magento FILE_GROUP=apache \\
    SEARCH_ENGINE=opensearch SEARCH_SCHEME=https \\
    SEARCH_HOST=xxx.aivencloud.com SEARCH_PORT=12345 \\
    SEARCH_USER=avnadmin SEARCH_PASS=secret SEARCH_CA_CERT=./aiven-ca.pem \\
    ./install-on-ec2.sh
EOF
}

[[ "${1:-}" == "-h" || "${1:-}" == "--help" ]] && { usage; exit 0; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SUDO=""; [[ "$(id -u)" -ne 0 ]] && SUDO="sudo"

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m    ✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m    ! %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m    ✗ %s\033[0m\n' "$*" >&2; exit 1; }

[[ -z "$BASE_URL" ]] && { usage; die "BASE_URL is required (e.g. BASE_URL=http://<ec2-ip>/)"; }
[[ "$BASE_URL" != */ ]] && BASE_URL="$BASE_URL/"
case "$SEARCH_ENGINE" in
  opensearch|elasticsearch8) ;;
  *) die "SEARCH_ENGINE must be 'opensearch' or 'elasticsearch8' (Magento 2.4.9 dropped Elasticsearch 7)." ;;
esac
case "$SEARCH_SCHEME" in http|https) ;; *) die "SEARCH_SCHEME must be 'http' or 'https'." ;; esac
[[ -n "$SEARCH_CA_CERT" && ! -f "$SEARCH_CA_CERT" ]] && die "SEARCH_CA_CERT='$SEARCH_CA_CERT' not found."
{ [[ -n "$SEARCH_USER" && -z "$SEARCH_PASS" ]] || [[ -z "$SEARCH_USER" && -n "$SEARCH_PASS" ]]; } \
  && die "Set BOTH SEARCH_USER and SEARCH_PASS, or neither."
[[ -f "$SCRIPT_DIR/code.tar.gz"    ]] || die "code.tar.gz not found next to this script."
[[ -f "$SCRIPT_DIR/db.sql.gz"      ]] || die "db.sql.gz not found next to this script."
[[ -f "$SCRIPT_DIR/env.source.php" ]] || die "env.source.php not found next to this script."

# -------------------------------------------------------------- preflight ----
say "Pre-flight checks"
command -v php >/dev/null || die "php not found. Install PHP 8.4 first."
PHP_V="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
case "$PHP_V" in
  8.3|8.4) ok "PHP $PHP_V" ;;
  *) warn "PHP $PHP_V detected — Magento 2.4.9 targets PHP 8.3/8.4. Continuing anyway." ;;
esac
for ext in bcmath ctype curl dom gd hash iconv intl mbstring openssl pdo_mysql simplexml soap sodium xml xsl zip; do
  php -m | grep -qi "^$ext$" || warn "PHP extension '$ext' appears missing (Magento needs it)."
done
command -v mysql >/dev/null || die "mysql/mariadb client not found."
command -v composer >/dev/null && ok "composer present" || warn "composer not found — not required (vendor/ is bundled)."

# ------------------------------------------------------- search engine up? ----
# Both OpenSearch and Elasticsearch answer a GET on / with a JSON banner that
# includes version.number — we use it to verify reachability AND that the major
# version matches what Magento 2.4.9 supports.
SEARCH_URL="$SEARCH_SCHEME://$SEARCH_HOST:$SEARCH_PORT/"
CURL_OPTS=(-s -m 6)
[[ -n "$SEARCH_USER" ]] && CURL_OPTS+=(-u "$SEARCH_USER:$SEARCH_PASS")
[[ "$SEARCH_TLS_INSECURE" == "1" ]] && CURL_OPTS+=(-k)
search_banner()    { curl "${CURL_OPTS[@]}" "$SEARCH_URL" 2>/dev/null; }
search_reachable() { curl "${CURL_OPTS[@]}" -o /dev/null "$SEARCH_URL" 2>/dev/null; }

# Add a CA cert (e.g. Aiven's project CA) to the OS trust store so BOTH curl
# and Magento's PHP/curl client validate the managed endpoint's TLS cert.
if [[ -n "$SEARCH_CA_CERT" ]]; then
  say "Adding CA cert to system trust store ($SEARCH_CA_CERT)"
  $SUDO cp "$SEARCH_CA_CERT" /etc/pki/ca-trust/source/anchors/magento-search-ca.pem
  $SUDO update-ca-trust
  ok "CA cert trusted system-wide"
fi

# NOTE: this installer never installs a search engine. It expects an external,
# already-running OpenSearch/Elasticsearch (e.g. Aiven) and only verifies it.
say "Checking external search engine ($SEARCH_ENGINE) at $SEARCH_URL${SEARCH_USER:+ (auth: $SEARCH_USER)}"
if search_reachable; then
  SEARCH_VER="$(search_banner | sed -nE 's/.*"number"[[:space:]]*:[[:space:]]*"([0-9]+)\.[0-9].*/\1/p' | head -1)"
  if [[ "$SEARCH_ENGINE" == "elasticsearch8" ]]; then
    if [[ -n "$SEARCH_VER" && "$SEARCH_VER" -ge 8 ]]; then
      ok "Elasticsearch $SEARCH_VER.x reachable — compatible with Magento 2.4.9"
    elif [[ -n "$SEARCH_VER" ]]; then
      die "Found Elasticsearch $SEARCH_VER.x, but Magento 2.4.9 needs Elasticsearch 8.x. Upgrade ES, or use OpenSearch (SEARCH_ENGINE=opensearch)."
    else
      warn "Reachable but could not read version — assuming Elasticsearch 8.x. Verify with: curl $SEARCH_URL"
    fi
  else
    ok "OpenSearch reachable${SEARCH_VER:+ (v$SEARCH_VER)}"
  fi
else
  warn "Search engine NOT reachable at $SEARCH_URL — Magento 2.4 cannot run without it."
  echo "    Check: host/port, SEARCH_USER/PASS, firewall/security-group, and TLS."
  [[ "$SEARCH_SCHEME" == "https" && -z "$SEARCH_CA_CERT" ]] && \
    echo "    HTTPS without SEARCH_CA_CERT — if the cert is from a private CA (Aiven), pass SEARCH_CA_CERT=./ca.pem."
  die "Aborting: make the external search endpoint reachable, then re-run."
fi

# ---------------------------------------------------------- extract code ----
say "Extracting code + media into $APP_DIR"
$SUDO mkdir -p "$APP_DIR"
$SUDO tar xzf "$SCRIPT_DIR/code.tar.gz" -C "$APP_DIR"
$SUDO mkdir -p "$APP_DIR/var" "$APP_DIR/generated" "$APP_DIR/pub/static"
ok "Code extracted"

# --------------------------------------------------------------- database ----
say "Setting up database '$DB_NAME'"
if [[ -z "$DB_PASS" ]]; then
  DB_PASS="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)"
  warn "No DB_PASS given — generated one (saved into env.php below)."
fi
# Use socket/root auth for DDL. On a fresh AL2023 MariaDB, root uses unix_socket.
MYSQL_ROOT=( $SUDO mysql )
"${MYSQL_ROOT[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
ok "Database + user ready"

# Collation compatibility: the dump came from MariaDB 11.4 (utf8mb4_uca1400_*).
# Older MariaDB (e.g. 10.5 on stock AL2023) does not know those collations.
TARGET_VER="$("${MYSQL_ROOT[@]}" -N -e 'SELECT VERSION();')"
say "Importing dump into MariaDB $TARGET_VER"
if printf '%s\n11.4\n' "$TARGET_VER" | sort -V | head -1 | grep -q '^11\.4'; then
  ok "Target supports modern collations — importing as-is"
  gzip -dc "$SCRIPT_DIR/db.sql.gz" | "${MYSQL_ROOT[@]}" "$DB_NAME"
else
  warn "Target older than MariaDB 11.4 — rewriting uca1400 collations to utf8mb4_general_ci on the fly"
  gzip -dc "$SCRIPT_DIR/db.sql.gz" \
    | sed -E 's/utf8mb4_uca1400_ai_ci/utf8mb4_general_ci/g; s/COLLATE=utf8mb4_uca1400[a-z_]*/COLLATE=utf8mb4_general_ci/g' \
    | "${MYSQL_ROOT[@]}" "$DB_NAME"
fi
ok "Database imported"

# ----------------------------------------------------------- write env.php ----
say "Writing app/etc/env.php for this box (preserving crypt key)"
# Reuse crypt key + table prefix from the source so encrypted DB values still decrypt.
TMP_ENV="$(mktemp)"
php -r '
  $src = include $argv[1];
  $crypt  = $src["crypt"]["key"] ?? "";
  $prefix = $src["db"]["table_prefix"] ?? "";
  $cfg = [
    "backend" => ["frontName" => "admin"],
    "remote_storage" => ["driver" => "file"],
    "crypt" => ["key" => $crypt],
    "db" => [
      "table_prefix" => $prefix,
      "connection" => ["default" => [
        "host" => $argv[2], "dbname" => $argv[3],
        "username" => $argv[4], "password" => $argv[5],
        "model" => "mysql4", "engine" => "innodb", "active" => "1",
        "driver_options" => [1014 => false],
      ]],
    ],
    "resource" => ["default_setup" => ["connection" => "default"]],
    // No Redis on this box: file cache + file sessions.
    "session" => ["save" => "files"],
    // No RabbitMQ on this box: run async ops through the DB queue.
    "queue" => ["consumers_wait_for_messages" => 0],
    "search" => [], // search engine itself is configured in core_config_data (carried over in the dump)
    "x-frame-options" => "SAMEORIGIN",
    "MAGE_MODE" => "default",
    "cache_types" => [
      "config"=>1,"layout"=>1,"block_html"=>1,"collections"=>1,"reflection"=>1,
      "db_ddl"=>1,"compiled_config"=>1,"eav"=>1,"customer_notification"=>1,
      "config_integration"=>1,"config_integration_api"=>1,
      "graphql_query_resolver_result"=>1,"full_page"=>1,"config_webservice"=>1,"translate"=>1,
    ],
    "lock" => ["provider" => "db"],
    "directories" => ["document_root_is_pub" => true],
    "install" => ["date" => date("r")],
  ];
  $out = "<?php\nreturn " . var_export($cfg, true) . ";\n";
  // tidy var_export array() -> [] style is optional; Magento reads either fine.
  file_put_contents($argv[6], $out);
' "$SCRIPT_DIR/env.source.php" "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASS" "$TMP_ENV"
# $APP_DIR/app/etc is owned by root after the tar extraction, so place env.php
# with sudo; the chown -R below then hands the whole tree to RUN_USER:FILE_GROUP.
$SUDO install -m 0644 "$TMP_ENV" "$APP_DIR/app/etc/env.php"
rm -f "$TMP_ENV"
ok "env.php written (Redis & RabbitMQ removed, external search kept)"

# How to run bin/magento as the web user — works whether this script is invoked
# as root or as a sudo-capable user (e.g. ec2-user). If we already ARE the web
# user, run directly; otherwise drop privileges via sudo -u.
set_run_as() {
  if [[ "$(id -un)" == "$RUN_USER" ]]; then RUN_AS=(); else RUN_AS=(sudo -u "$RUN_USER"); fi
}
set_run_as
php_bin() { ( cd "$APP_DIR" && "${RUN_AS[@]}" php bin/magento "$@" ); }

# ----------------------------------------------------------- permissions ----
say "Setting ownership to '$RUN_USER:$FILE_GROUP' and permissions on the whole tree"
id "$RUN_USER" >/dev/null 2>&1 || die "OS user '$RUN_USER' does not exist. Create it (e.g. 'sudo useradd -M -s /sbin/nologin $RUN_USER') or pass RUN_USER=<existing user>."
getent group "$FILE_GROUP" >/dev/null 2>&1 || die "Group '$FILE_GROUP' does not exist. Create it or pass FILE_GROUP=<existing group>."
set_run_as
# Hand EVERY extracted file (incl. env.php, var/, generated/, pub/) to the
# Magento owner + web group. Group-writable so PHP-FPM can write var/, pub/static,
# generated/ and pub/media; setgid on dirs keeps new files in the right group.
$SUDO chown -R "$RUN_USER":"$FILE_GROUP" "$APP_DIR"
$SUDO find "$APP_DIR" -type d -exec chmod 2775 {} \; 2>/dev/null || true
$SUDO find "$APP_DIR" -type f -exec chmod 0664 {} \; 2>/dev/null || true
$SUDO chmod +x "$APP_DIR/bin/magento"
ok "Ownership set to $RUN_USER:$FILE_GROUP (verified below after Magento runs)"

# --------------------------------------------------- base url + search cfg ----
say "Configuring base URL and search engine ($SEARCH_ENGINE @ $SEARCH_URL)"
# Magento derives the protocol from the hostname, so store the scheme with it
# (e.g. https://xxx.aivencloud.com). For plain local http this is harmless.
SEARCH_HOST_CFG="$SEARCH_SCHEME://$SEARCH_HOST"
ENABLE_AUTH=0; [[ -n "$SEARCH_USER" ]] && ENABLE_AUTH=1
# SQL-escape single quotes in the password.
ESC_PASS="${SEARCH_PASS//\'/\'\'}"
"${MYSQL_ROOT[@]}" "$DB_NAME" <<SQL
UPDATE core_config_data SET value='$BASE_URL' WHERE path IN ('web/unsecure/base_url','web/secure/base_url');
DELETE FROM core_config_data WHERE path IN ('web/cookie/cookie_domain','web/secure/use_in_frontend','web/secure/use_in_adminhtml');
INSERT INTO core_config_data (scope,scope_id,path,value) VALUES
  ('default',0,'catalog/search/engine','$SEARCH_ENGINE'),
  ('default',0,'catalog/search/${SEARCH_ENGINE}_server_hostname','$SEARCH_HOST_CFG'),
  ('default',0,'catalog/search/${SEARCH_ENGINE}_server_port','$SEARCH_PORT'),
  ('default',0,'catalog/search/${SEARCH_ENGINE}_enable_auth','$ENABLE_AUTH'),
  ('default',0,'catalog/search/${SEARCH_ENGINE}_username','$SEARCH_USER'),
  ('default',0,'catalog/search/${SEARCH_ENGINE}_password','$ESC_PASS')
ON DUPLICATE KEY UPDATE value=VALUES(value);
SQL
if [[ -n "$SEARCH_INDEX_PREFIX" ]]; then
  "${MYSQL_ROOT[@]}" "$DB_NAME" -e "INSERT INTO core_config_data (scope,scope_id,path,value) VALUES ('default',0,'catalog/search/${SEARCH_ENGINE}_index_prefix','$SEARCH_INDEX_PREFIX') ON DUPLICATE KEY UPDATE value=VALUES(value);"
fi
ok "Base URL set to $BASE_URL; search engine set to $SEARCH_ENGINE (auth=$ENABLE_AUTH)"

# ------------------------------------------------------------ magento init ----
say "Running setup:upgrade (schema + DI for the migrated modules)"
php_bin setup:upgrade
say "Compiling DI is skipped (MAGE_MODE=default generates on demand)."
say "Reindexing"
php_bin indexer:reindex || warn "Reindex reported issues — re-run after confirming the search engine is reachable."
say "Flushing cache"
php_bin cache:flush

# ------------------------------------------------- re-assert ownership ----
# setup:upgrade / reindex (run as $RUN_USER) created files in var/, generated/,
# pub/static, app/etc/config.php. Re-apply ownership so the ENTIRE tree is
# guaranteed $RUN_USER:$FILE_GROUP, then verify nothing slipped through.
say "Re-asserting ownership on files Magento just generated"
$SUDO chown -R "$RUN_USER":"$FILE_GROUP" "$APP_DIR"
STRAY="$($SUDO find "$APP_DIR" \( ! -user "$RUN_USER" -o ! -group "$FILE_GROUP" \) -print -quit 2>/dev/null)"
if [[ -z "$STRAY" ]]; then
  ok "Verified: every file under $APP_DIR is owned by $RUN_USER:$FILE_GROUP"
else
  warn "Some files are NOT $RUN_USER:$FILE_GROUP (first: $STRAY). Inspect with: find $APP_DIR ! -user $RUN_USER -o ! -group $FILE_GROUP"
fi

# --------------------------------------------------------------- finish ----
cat <<DONE

==================================================================
 Migration finished.
==================================================================
 App dir:     $APP_DIR
 Base URL:    $BASE_URL
 DB:          $DB_NAME @ $DB_HOST  (user: $DB_USER)
 DB password: $DB_PASS
              ^ stored in $APP_DIR/app/etc/env.php — note it down.

 Still to do on this box (outside this script's scope):
  1. Web server: point nginx/apache docroot to $APP_DIR/pub
     (Hyvä/Magento needs the standard nginx.conf.sample include).
  2. PHP-FPM: ensure it runs as '$RUN_USER' and is started.
  3. Cron (recommended): add Magento cron, e.g.
       * * * * * php $APP_DIR/bin/magento cron:run >> $APP_DIR/var/log/cron.log 2>&1
  4. Open the firewall / security group for HTTP(S).
  5. Security: delete the migration bundle — it holds the DB + crypt key.

 If the storefront 500s on first hit, check $APP_DIR/var/log/ and
 confirm the search engine ($SEARCH_ENGINE @ $SEARCH_URL) is reachable.
==================================================================
DONE
