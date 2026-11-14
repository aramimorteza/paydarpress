#!/usr/bin/env bash
# Usage: install-plugin.sh <site-dir>  — copies the plugin (without dev files) into the site.
set -euo pipefail
SITE="$1"; DEST="$SITE/wp-content/plugins/paydarpress"
rm -rf "$DEST"; mkdir -p "$DEST"
cd /home/claude/paydarpress/plugin/paydarpress
tar --exclude=node_modules --exclude=assets/src --exclude=tests -cf - . | tar -xf - -C "$DEST"
