#!/usr/bin/env bash
#
# gen-export.sh — Regenerate the portable, self-contained magento-export.sh.
#
# Single source of truth: install-on-ec2.sh + README-EC2.md (the canonical
# installer and its docs). This generator embeds both into ONE drop-in export
# script you can run in ANY Magento (Hyvä) Codespace. Run this after editing
# either source file:
#
#   ./migration/gen-export.sh
#
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_INSTALLER="$HERE/install-on-ec2.sh"
SRC_README="$HERE/README-EC2.md"
OUT="$HERE/magento-export.sh"

for f in "$SRC_INSTALLER" "$SRC_README"; do
  [[ -f "$f" ]] || { echo "missing source: $f" >&2; exit 1; }
done

# Guard against delimiter collisions (would corrupt the generated heredocs).
grep -qE '^MAGENTO_INSTALLER_EOF$' "$SRC_INSTALLER" && { echo "delimiter clash in installer" >&2; exit 1; }
grep -qE '^MAGENTO_README_EOF$'    "$SRC_README"    && { echo "delimiter clash in README" >&2; exit 1; }

{
  # ---- HEAD: the export logic (verbatim into the output) -------------------
  cat <<'HEAD'
#!/usr/bin/env bash
#
# magento-export.sh — Portable, self-contained Magento → EC2 export tool.
#
# Drop this single file into ANY Magento (Hyvä) Codespace and run it. It
# auto-detects the Magento root, dumps the database, and produces ONE tarball
# containing the code + media, the DB dump, and an EMBEDDED EC2 installer +
# README. No other files required.
#
#   ./magento-export.sh                 # auto-detect root, write bundle here
#   MAGENTO_ROOT=/path OUT_DIR=/tmp ./magento-export.sh
#
# GENERATED FILE — do not edit the embedded installer/README below by hand.
# Edit migration/install-on-ec2.sh + README-EC2.md and run gen-export.sh.
#
set -euo pipefail

say(){ printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
die(){ printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

# --- locate the Magento root -------------------------------------------------
MAGENTO_ROOT="${MAGENTO_ROOT:-}"
if [[ -z "$MAGENTO_ROOT" ]]; then
  d="$PWD"
  while [[ "$d" != / ]]; do
    if [[ -f "$d/bin/magento" && -f "$d/app/etc/env.php" ]]; then MAGENTO_ROOT="$d"; break; fi
    d="$(dirname "$d")"
  done
fi
[[ -z "$MAGENTO_ROOT" && -f /workspace/app/etc/env.php ]] && MAGENTO_ROOT=/workspace
[[ -n "$MAGENTO_ROOT" && -f "$MAGENTO_ROOT/app/etc/env.php" ]] \
  || die "Could not locate a Magento root (need bin/magento + app/etc/env.php). Set MAGENTO_ROOT=/path."
cd "$MAGENTO_ROOT"

for bin in php mysqldump tar gzip; do command -v "$bin" >/dev/null || die "'$bin' not found in PATH."; done

OUT_DIR="${OUT_DIR:-$MAGENTO_ROOT}"
STAMP="$(date +%Y%m%d-%H%M%S)"
WORK="$(mktemp -d /tmp/mag-export.XXXXXX)"
BUNDLE_DIR="$WORK/magento-migrate"
OUT="$OUT_DIR/magento-migrate-$STAMP.tar.gz"
mkdir -p "$BUNDLE_DIR"

say "Magento root: $MAGENTO_ROOT"
echo "    $(php -d memory_limit=-1 bin/magento --version 2>/dev/null || echo 'version unknown')"

# --- read DB credentials from env.php ----------------------------------------
say "Reading DB credentials from app/etc/env.php"
read -r DB_HOST DB_NAME DB_USER DB_PASS < <(php -d memory_limit=-1 -r '
  $e = include "app/etc/env.php"; $c = $e["db"]["connection"]["default"];
  echo $c["host"]." ".$c["dbname"]." ".$c["username"]." ".$c["password"]."\n";
')
[[ -n "$DB_NAME" ]] || die "Could not read DB config from env.php."

# --- dump database (consistent snapshot) -------------------------------------
say "Dumping database '$DB_NAME'"
mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" \
  --single-transaction --no-tablespaces --routines --triggers --events \
  --default-character-set=utf8mb4 "$DB_NAME" | gzip -6 > "$BUNDLE_DIR/db.sql.gz"
echo "    db.sql.gz: $(du -h "$BUNDLE_DIR/db.sql.gz" | cut -f1)"

# --- preserve the source env.php (crypt key + table prefix) ------------------
cp app/etc/env.php "$BUNDLE_DIR/env.source.php"

# --- write the embedded installer + README -----------------------------------
say "Writing installer + README into the bundle"
HEAD

  # ---- PAYLOAD: installer (verbatim, no expansion) -------------------------
  cat <<'MARK1'
cat > "$BUNDLE_DIR/install-on-ec2.sh" <<'MAGENTO_INSTALLER_EOF'
MARK1
  awk 1 "$SRC_INSTALLER"   # normalize to exactly one trailing newline
  printf 'MAGENTO_INSTALLER_EOF\n\n'

  # ---- PAYLOAD: README -----------------------------------------------------
  cat <<'MARK2'
cat > "$BUNDLE_DIR/README-EC2.md" <<'MAGENTO_README_EOF'
MARK2
  awk 1 "$SRC_README"
  printf 'MAGENTO_README_EOF\n\n'

  # ---- FOOT: archive + pack + summary (verbatim into the output) -----------
  cat <<'FOOT'
chmod +x "$BUNDLE_DIR/install-on-ec2.sh"

# --- archive code + media (exclude regenerable + secrets) --------------------
say "Archiving code + media (excluding var/, generated/, pub/static, .git, env.php, auth.json)"
tar \
  --exclude='./var' --exclude='./generated' --exclude='./pub/static' \
  --exclude='./.git' --exclude='./app/etc/env.php' --exclude='./auth.json' \
  --exclude='*/node_modules' --exclude='./magento-migrate-*.tar.gz' \
  -czf "$BUNDLE_DIR/code.tar.gz" -C "$MAGENTO_ROOT" .
echo "    code.tar.gz: $(du -h "$BUNDLE_DIR/code.tar.gz" | cut -f1)"

# --- pack final bundle -------------------------------------------------------
say "Packing final bundle"
tar -czf "$OUT" -C "$WORK" magento-migrate
rm -rf "$WORK"

cat <<DONE

==================================================================
 Bundle ready:  $OUT
 Size:          $(du -h "$OUT" | cut -f1)
==================================================================
 Copy to EC2:   scp -i key.pem "$OUT" ec2-user@<EC2_IP>:~/
 On the EC2:    tar xzf $(basename "$OUT") && cd magento-migrate && ./install-on-ec2.sh --help

 SECURITY: this bundle holds your full DB dump + the Magento crypt key.
 Treat it as a secret and delete it after the migration.
 If $OUT_DIR is a git repo, keep the bundle out of it:
   echo '/magento-migrate-*.tar.gz' >> .gitignore
==================================================================
DONE
FOOT
} > "$OUT"

chmod +x "$OUT"
echo "Generated $OUT ($(wc -l < "$OUT") lines)"
