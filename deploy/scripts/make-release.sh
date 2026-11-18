#!/usr/bin/env bash
# Buat paket rilis (zip) dari folder proyek — tanpa vendor/, .env, database lokal, log, atau tes.
#   bash deploy/scripts/make-release.sh            → ../mypresence-release-YYYYmmdd-HHMM.zip
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="${1:-$(dirname "$ROOT")/mypresence-release-$(date +%Y%m%d-%H%M).zip}"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
DST="$TMP/mypresence"
mkdir -p "$DST"
tar -C "$ROOT" -cf - \
  --exclude=./vendor --exclude=./node_modules --exclude=./.env --exclude='./.env.*.bak' --exclude=./.git \
  --exclude=./database/database.sqlite --exclude=./.phpunit.result.cache --exclude=./.phpunit.cache \
  --exclude=./composer.dev.json --exclude=./composer.dev.lock --exclude=./bootstrap/cache/*.php \
  --exclude='./storage/logs/*' --exclude='./storage/framework/cache/data/*' --exclude='./storage/framework/sessions/*' \
  --exclude='./storage/framework/views/*' --exclude='./storage/app/private/*' --exclude='./storage/app/public/*' \
  --exclude='./storage/app/backups' --exclude='./storage/framework/testing' --exclude='./storage/app/school_*' --exclude='./public/hot' --exclude='./public/storage' \
  . | tar -C "$DST" -xf -
rm -f "$OUT"   # zip menambah ke arsip lama bila sudah ada
(cd "$TMP" && zip -qr9 "$OUT" mypresence)
echo "$OUT ($(du -h "$OUT" | cut -f1), $(unzip -l "$OUT" | tail -1 | awk '{print $2}') berkas)"
