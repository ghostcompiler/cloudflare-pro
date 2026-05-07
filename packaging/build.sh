#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
VERSION=$(php -r '$xml = simplexml_load_file("meta.xml"); echo trim((string) $xml->version);')
RELEASE=$(php -r '$xml = simplexml_load_file("meta.xml"); echo trim((string) $xml->release);')
OUT="${ROOT_DIR}/cloudflare-pro-${VERSION}-${RELEASE}.zip"

cd "$ROOT_DIR"

if command -v npm >/dev/null 2>&1; then
  if [ ! -d node_modules ]; then
    if [ -f package-lock.json ]; then
      npm ci --ignore-scripts --legacy-peer-deps
    else
      npm install --ignore-scripts --legacy-peer-deps
    fi
  fi
  npm run build
else
  printf '%s\n' "npm is required to build the React UI bundle." >&2
  exit 1
fi

rm -f "$OUT"
COPYFILE_DISABLE=1 zip -r "$OUT" \
  meta.xml DESCRIPTION.md CHANGES.md README.md \
  htdocs plib _meta docs packaging \
  -x ".git/*" "node_modules/*" "*.DS_Store" "__MACOSX/*" "*.zip"

printf '%s\n' "$OUT"
