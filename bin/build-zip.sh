#!/usr/bin/env bash
# Builds an installable plugin zip: dist/paydarpress-<version>.zip
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN="$ROOT/plugin/paydarpress"
VERSION="$(grep -m1 "define( 'PAYDARPRESS_VERSION'" "$PLUGIN/paydarpress.php" | sed -E "s/.*'([0-9.]+)'.*/\1/")"
cd "$PLUGIN"
composer dump-autoload -o --no-interaction --quiet
npm run build --silent
mkdir -p "$ROOT/dist"
STAGE="$(mktemp -d)"
mkdir "$STAGE/paydarpress"
tar --exclude=node_modules --exclude=assets/src --exclude=tests --exclude=package.json --exclude=package-lock.json --exclude=composer.json --exclude=composer.lock --exclude=webpack.config.js -cf - . | tar -xf - -C "$STAGE/paydarpress"
rm -f "$ROOT/dist/paydarpress-$VERSION.zip"
(cd "$STAGE" && zip -qr "$ROOT/dist/paydarpress-$VERSION.zip" paydarpress)
rm -rf "$STAGE"
echo "$ROOT/dist/paydarpress-$VERSION.zip"
