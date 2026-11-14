#!/usr/bin/env bash
# Usage: setup-site.sh <wp-source-dir> <site-dir> <port>
set -euo pipefail
SRC="$1"; SITE="$2"; PORT="$3"
rm -rf "$SITE"; mkdir -p "$SITE"
cp -r "$SRC"/. "$SITE"/ && rm -rf "$SITE/.git"
PLUG="$SITE/wp-content/plugins/sqlite-database-integration"
mkdir -p "$PLUG"
cp -rL /tmp/wp/sqlite/packages/plugin-sqlite-database-integration/. "$PLUG"/
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$PLUG#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
  "$PLUG/db.copy" > "$SITE/wp-content/db.php"
cat > "$SITE/wp-config.php" <<CFG
<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'DB_DIR', '$SITE/wp-content/database/' ); define( 'DB_FILE', '.ht.sqlite' );
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', '$SITE/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HOME', 'http://localhost:$PORT' ); define( 'WP_SITEURL', 'http://localhost:$PORT' );
define( 'AUTH_KEY', 'a' ); define( 'SECURE_AUTH_KEY', 'b' ); define( 'LOGGED_IN_KEY', 'c' ); define( 'NONCE_KEY', 'd' );
define( 'AUTH_SALT', 'e' ); define( 'SECURE_AUTH_SALT', 'f' ); define( 'LOGGED_IN_SALT', 'g' ); define( 'NONCE_SALT', 'h' );
define( 'WPLANG', 'fa_IR' );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CFG
cat > "$SITE/install.php" <<'INS'
<?php
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$r = wp_install( 'سایت آزمایشی', 'admin', 'admin@example.com', true, '', 'password123' );
echo "installed user {$r['user_id']}\n";
INS
php "$SITE/install.php"
rm "$SITE/install.php"
echo "site ready: $SITE"
