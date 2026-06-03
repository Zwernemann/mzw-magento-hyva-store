#!/usr/bin/env bash
#
# build-bundle.sh — Run this INSIDE the Codespace.
# Produces a single self-contained migration tarball that you copy to the EC2
# instance and unpack there. See README-EC2.md for the EC2 side.
#
# Output: /tmp/magento-migrate-<timestamp>.tar.gz
#
set -euo pipefail

MAGENTO_ROOT="${MAGENTO_ROOT:-/workspace}"
STAMP="$(date +%Y%m%d-%H%M%S)"
WORK="$(mktemp -d /tmp/mag-bundle.XXXXXX)"
BUNDLE_DIR="$WORK/magento-migrate"
OUT="/tmp/magento-migrate-$STAMP.tar.gz"

cd "$MAGENTO_ROOT"
mkdir -p "$BUNDLE_DIR"

echo "==> Reading DB credentials from app/etc/env.php"
read -r DB_HOST DB_NAME DB_USER DB_PASS < <(php -r '
  $e = include "app/etc/env.php";
  $c = $e["db"]["connection"]["default"];
  echo $c["host"]." ".$c["dbname"]." ".$c["username"]." ".$c["password"]."\n";
')

echo "==> Dumping database '$DB_NAME' (this captures ALL your shop data)"
# --single-transaction: consistent snapshot without locking
# --no-tablespaces: avoid needing PROCESS privilege
# --routines --triggers --events: keep stored programs
mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" \
  --single-transaction --no-tablespaces \
  --routines --triggers --events \
  --default-character-set=utf8mb4 \
  "$DB_NAME" | gzip -6 > "$BUNDLE_DIR/db.sql.gz"
echo "    db.sql.gz: $(du -h "$BUNDLE_DIR/db.sql.gz" | cut -f1)"

echo "==> Saving original env.php (needed for the crypt key + table prefix)"
cp app/etc/env.php "$BUNDLE_DIR/env.source.php"

echo "==> Archiving code + media (excluding regenerable + secrets)"
# Excluded:
#   var/, generated/, pub/static/   -> regenerated on the target
#   .git/                           -> not needed to run
#   app/etc/env.php                 -> rewritten on the target (shipped as env.source.php)
#   auth.json                       -> Composer/Hyva secrets, not needed (vendor/ is shipped)
#   node_modules                    -> build-only
tar \
  --exclude='./var' \
  --exclude='./generated' \
  --exclude='./pub/static' \
  --exclude='./.git' \
  --exclude='./app/etc/env.php' \
  --exclude='./auth.json' \
  --exclude='*/node_modules' \
  -czf "$BUNDLE_DIR/code.tar.gz" -C "$MAGENTO_ROOT" .
echo "    code.tar.gz: $(du -h "$BUNDLE_DIR/code.tar.gz" | cut -f1)"

echo "==> Adding installer + README"
cp "$MAGENTO_ROOT/migration/install-on-ec2.sh" "$BUNDLE_DIR/install-on-ec2.sh"
cp "$MAGENTO_ROOT/migration/README-EC2.md"     "$BUNDLE_DIR/README-EC2.md"
chmod +x "$BUNDLE_DIR/install-on-ec2.sh"

echo "==> Packing final bundle"
tar -czf "$OUT" -C "$WORK" magento-migrate
rm -rf "$WORK"

echo
echo "=================================================================="
echo " Bundle ready:  $OUT"
echo " Size:          $(du -h "$OUT" | cut -f1)"
echo "=================================================================="
echo
echo " Next: copy it to your EC2 box, e.g."
echo "   scp -i your-key.pem $OUT ec2-user@<EC2_IP>:~/"
echo " Then on the EC2 box:"
echo "   tar xzf $(basename "$OUT") && cd magento-migrate && ./install-on-ec2.sh"
echo
echo " NOTE: this bundle contains your full database and the Magento crypt"
echo "       key. Treat it as a secret. Delete it after the migration."
