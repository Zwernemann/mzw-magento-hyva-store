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
SEARCH_HOST="${SEARCH_HOST:-localhost}"
SEARCH_PORT="${SEARCH_PORT:-9200}"
RUN_USER="${RUN_USER:-nginx}"                # web-server/php-fpm user (nginx or apache)
INSTALL_OPENSEARCH="${INSTALL_OPENSEARCH:-ask}"   # yes | no | ask — only used when SEARCH_ENGINE=opensearch

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
  RUN_USER=nginx              OS user running PHP-FPM / web server
  SEARCH_ENGINE=opensearch    Search engine: opensearch | elasticsearch8
  SEARCH_HOST=localhost       Search engine host
  SEARCH_PORT=9200            Search engine port
  INSTALL_OPENSEARCH=ask      yes | no | ask — install OpenSearch if missing
                              (ignored when SEARCH_ENGINE=elasticsearch8)

Using an existing Elasticsearch 8.x on the box (no OpenSearch installed):
  BASE_URL=http://<ip>/ SEARCH_ENGINE=elasticsearch8 SEARCH_PORT=9200 ./install-on-ec2.sh
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
# Both OpenSearch and Elasticsearch answer a plain GET on :9200 with a JSON
# banner that includes version.number — we use it to verify reachability AND
# that the major version matches what Magento 2.4.9 supports.
search_banner() { curl -s -m 5 "http://$SEARCH_HOST:$SEARCH_PORT/" 2>/dev/null; }
search_reachable() { curl -s -o /dev/null -m 4 "http://$SEARCH_HOST:$SEARCH_PORT/" 2>/dev/null; }

install_opensearch() {
  say "Installing OpenSearch 2.x (single-node, security disabled, localhost only)"
  $SUDO tee /etc/yum.repos.d/opensearch-2.x.repo >/dev/null <<'REPO'
[opensearch-2.x]
name=OpenSearch 2.x
baseurl=https://artifacts.opensearch.org/releases/bundle/opensearch/2.x/yum
gpgcheck=1
gpgkey=https://artifacts.opensearch.org/publickeys/opensearch-release-public-key.pem
enabled=1
autorefresh=1
type=rpm-md
REPO
  # 2.12+ requires an initial admin password even though we disable security
  export OPENSEARCH_INITIAL_ADMIN_PASSWORD="Magento#$(date +%s)Aa1"
  $SUDO --preserve-env=OPENSEARCH_INITIAL_ADMIN_PASSWORD dnf install -y opensearch
  $SUDO tee /etc/opensearch/opensearch.yml >/dev/null <<'YML'
cluster.name: magento
node.name: magento-node
network.host: 127.0.0.1
http.port: 9200
discovery.type: single-node
plugins.security.disabled: true
bootstrap.memory_lock: false
YML
  # Keep heap modest for small EC2 boxes; adjust if you have more RAM.
  $SUDO sed -i -E 's/^-Xms.*/-Xms512m/; s/^-Xmx.*/-Xmx512m/' /etc/opensearch/jvm.options || true
  $SUDO systemctl daemon-reload
  $SUDO systemctl enable --now opensearch
  say "Waiting for OpenSearch to come up"
  for i in $(seq 1 60); do search_reachable && break; sleep 2; done
  search_reachable && ok "OpenSearch is up" || die "OpenSearch did not start — check: journalctl -u opensearch"
}

say "Checking search engine ($SEARCH_ENGINE) at $SEARCH_HOST:$SEARCH_PORT"
if search_reachable; then
  SEARCH_VER="$(search_banner | sed -nE 's/.*"number"[[:space:]]*:[[:space:]]*"([0-9]+)\.[0-9].*/\1/p' | head -1)"
  if [[ "$SEARCH_ENGINE" == "elasticsearch8" ]]; then
    if [[ -n "$SEARCH_VER" && "$SEARCH_VER" -ge 8 ]]; then
      ok "Elasticsearch $SEARCH_VER.x reachable — compatible with Magento 2.4.9"
    elif [[ -n "$SEARCH_VER" ]]; then
      die "Found Elasticsearch $SEARCH_VER.x, but Magento 2.4.9 needs Elasticsearch 8.x. Upgrade ES, or use OpenSearch (SEARCH_ENGINE=opensearch)."
    else
      warn "Reachable but could not read version — assuming Elasticsearch 8.x. Verify with: curl $SEARCH_HOST:$SEARCH_PORT"
    fi
  else
    ok "OpenSearch reachable${SEARCH_VER:+ (v$SEARCH_VER)}"
  fi
else
  warn "Search engine NOT reachable — Magento 2.4 cannot run without it."
  if [[ "$SEARCH_ENGINE" == "elasticsearch8" ]]; then
    die "Start your Elasticsearch (expected at $SEARCH_HOST:$SEARCH_PORT) and re-run. No OpenSearch will be installed for SEARCH_ENGINE=elasticsearch8."
  fi
  DO_INSTALL="$INSTALL_OPENSEARCH"
  if [[ "$DO_INSTALL" == "ask" ]]; then
    read -r -p "    Install a local single-node OpenSearch now? [y/N] " a
    [[ "$a" =~ ^[Yy]$ ]] && DO_INSTALL=yes || DO_INSTALL=no
  fi
  if [[ "$DO_INSTALL" == "yes" ]]; then
    [[ "$SEARCH_PORT" != "9200" ]] && warn "Installer configures OpenSearch on port 9200; SEARCH_PORT=$SEARCH_PORT will be ignored."
    install_opensearch
  else
    die "Aborting: install/start a search engine, then re-run (or set INSTALL_OPENSEARCH=yes)."
  fi
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
' "$SCRIPT_DIR/env.source.php" "$DB_HOST" "$DB_NAME" "$DB_USER" "$DB_PASS" "$APP_DIR/app/etc/env.php"
ok "env.php written (Redis & RabbitMQ removed, OpenSearch kept)"

# Point search engine config at this box's OpenSearch (overrides the dump values).
RUN_AS=( $SUDO -u "$RUN_USER" )
php_bin() { ( cd "$APP_DIR" && "${RUN_AS[@]}" php bin/magento "$@" ); }

# ----------------------------------------------------------- permissions ----
say "Setting ownership to '$RUN_USER' and permissions"
id "$RUN_USER" >/dev/null 2>&1 || { warn "User '$RUN_USER' not found — falling back to $(whoami)"; RUN_USER="$(whoami)"; RUN_AS=(); }
$SUDO chown -R "$RUN_USER":"$RUN_USER" "$APP_DIR"
$SUDO find "$APP_DIR" -type d -exec chmod 2775 {} \; 2>/dev/null || true
$SUDO find "$APP_DIR" -type f -exec chmod 0664 {} \; 2>/dev/null || true
$SUDO chmod +x "$APP_DIR/bin/magento"
ok "Permissions set"

# --------------------------------------------------- base url + search cfg ----
say "Configuring base URL and search engine ($SEARCH_ENGINE @ $SEARCH_HOST:$SEARCH_PORT)"
"${MYSQL_ROOT[@]}" "$DB_NAME" <<SQL
UPDATE core_config_data SET value='$BASE_URL' WHERE path IN ('web/unsecure/base_url','web/secure/base_url');
DELETE FROM core_config_data WHERE path IN ('web/cookie/cookie_domain','web/secure/use_in_frontend','web/secure/use_in_adminhtml');
INSERT INTO core_config_data (scope,scope_id,path,value) VALUES
  ('default',0,'catalog/search/engine','$SEARCH_ENGINE'),
  ('default',0,'catalog/search/${SEARCH_ENGINE}_server_hostname','$SEARCH_HOST'),
  ('default',0,'catalog/search/${SEARCH_ENGINE}_server_port','$SEARCH_PORT'),
  ('default',0,'catalog/search/${SEARCH_ENGINE}_enable_auth','0')
ON DUPLICATE KEY UPDATE value=VALUES(value);
DELETE FROM core_config_data WHERE path='catalog/search/${SEARCH_ENGINE}_server_password';
SQL
ok "Base URL set to $BASE_URL; search engine set to $SEARCH_ENGINE"

# ------------------------------------------------------------ magento init ----
say "Running setup:upgrade (schema + DI for the migrated modules)"
php_bin setup:upgrade
say "Compiling DI is skipped (MAGE_MODE=default generates on demand)."
say "Reindexing"
php_bin indexer:reindex || warn "Reindex reported issues — re-run after confirming OpenSearch is healthy."
say "Flushing cache"
php_bin cache:flush

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
 confirm the search engine ($SEARCH_ENGINE @ $SEARCH_HOST:$SEARCH_PORT) is reachable.
==================================================================
DONE
