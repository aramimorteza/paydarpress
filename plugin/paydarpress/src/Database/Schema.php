<?php
/**
 * Database schema (dbDelta format). See docs/phase1-spec.md section 4.
 *
 * @package PaydarPress
 */

declare(strict_types=1);

namespace PaydarWP\PaydarPress\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table names and CREATE TABLE statements.
 *
 * All tables use the `{$wpdb->prefix}paydarpress_` prefix so they never collide with
 * other PaydarWP products (architecture section 2.11).
 */
final class Schema {

	public const VERSION = '1.5.0';

	public const TABLES = array( 'inventory', 'links', 'audit_findings', 'scans', 'activity', 'imports', 'gsc', 'topics', 'link_suggestions', 'ai_usage' );

	/**
	 * Full table name for a short name, e.g. `inventory` → `wp_paydarpress_inventory`.
	 *
	 * @param string $name Short table name.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'paydarpress_' . $name;
	}

	/**
	 * CREATE TABLE statements for dbDelta.
	 *
	 * dbDelta rules: two spaces after PRIMARY KEY, one field per line, KEY instead of INDEX.
	 *
	 * @return string[]
	 */
	public static function statements(): array {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$inventory      = self::table( 'inventory' );
		$links          = self::table( 'links' );
		$audit_findings = self::table( 'audit_findings' );
		$scans          = self::table( 'scans' );
		$activity       = self::table( 'activity' );
		$imports        = self::table( 'imports' );
		$gsc            = self::table( 'gsc' );
		$topics         = self::table( 'topics' );
		$ai_usage       = self::table( 'ai_usage' );
		$suggestions    = self::table( 'link_suggestions' );

		return array(
			"CREATE TABLE {$inventory} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_id bigint(20) unsigned NOT NULL,
  object_type varchar(40) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT '',
  title_norm varchar(255) NOT NULL DEFAULT '',
  url varchar(500) NOT NULL DEFAULT '',
  primary_term_id bigint(20) unsigned DEFAULT NULL,
  focus_keyword varchar(255) DEFAULT NULL,
  focus_keyword_norm varchar(191) DEFAULT NULL,
  seo_title varchar(255) DEFAULT NULL,
  meta_description text DEFAULT NULL,
  is_noindex tinyint(1) NOT NULL DEFAULT 0,
  is_cornerstone tinyint(1) NOT NULL DEFAULT 0,
  canonical varchar(500) DEFAULT NULL,
  word_count int(10) unsigned NOT NULL DEFAULT 0,
  h2_count smallint(5) unsigned NOT NULL DEFAULT 0,
  outline longtext DEFAULT NULL,
  excerpt text DEFAULT NULL,
  years_mentioned varchar(255) DEFAULT NULL,
  has_direct_answer tinyint(1) NOT NULL DEFAULT 0,
  has_featured_image tinyint(1) NOT NULL DEFAULT 0,
  featured_alt_ok tinyint(1) NOT NULL DEFAULT 0,
  inline_images smallint(5) unsigned NOT NULL DEFAULT 0,
  inline_images_no_alt smallint(5) unsigned NOT NULL DEFAULT 0,
  images_generic_alt smallint(5) unsigned NOT NULL DEFAULT 0,
  internal_out smallint(5) unsigned NOT NULL DEFAULT 0,
  internal_in smallint(5) unsigned NOT NULL DEFAULT 0,
  menu_in smallint(5) unsigned NOT NULL DEFAULT 0,
  external_out smallint(5) unsigned NOT NULL DEFAULT 0,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  refresh_score tinyint(3) unsigned NOT NULL DEFAULT 0,
  content_hash char(32) NOT NULL DEFAULT '',
  published_at datetime DEFAULT NULL,
  modified_at datetime DEFAULT NULL,
  scanned_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY object_id (object_id),
  KEY type_status (object_type,status),
  KEY focus_keyword_norm (focus_keyword_norm),
  KEY refresh_score (refresh_score),
  KEY internal_in (internal_in)
) {$charset};",

			"CREATE TABLE {$links} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_type varchar(20) NOT NULL DEFAULT 'content',
  target_id bigint(20) unsigned DEFAULT NULL,
  target_url varchar(500) NOT NULL DEFAULT '',
  anchor varchar(255) NOT NULL DEFAULT '',
  link_type varchar(20) NOT NULL DEFAULT 'internal',
  target_state varchar(20) NOT NULL DEFAULT 'unknown',
  PRIMARY KEY  (id),
  KEY source_id (source_id),
  KEY target_id (target_id),
  KEY type_state (link_type,target_state)
) {$charset};",

			"CREATE TABLE {$audit_findings} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(50) NOT NULL,
  severity varchar(10) NOT NULL DEFAULT 'low',
  object_kind varchar(20) NOT NULL DEFAULT 'content',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  data longtext DEFAULT NULL,
  state varchar(20) NOT NULL DEFAULT 'open',
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY finding (code,object_kind,object_id),
  KEY state_severity (state,severity)
) {$charset};",

			"CREATE TABLE {$scans} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL DEFAULT 'full',
  status varchar(20) NOT NULL DEFAULT 'running',
  total int(10) unsigned NOT NULL DEFAULT 0,
  processed int(10) unsigned NOT NULL DEFAULT 0,
  started_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  error text DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) {$charset};",

			"CREATE TABLE {$activity} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event varchar(60) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  object_kind varchar(20) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  message text DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY event (event)
) {$charset};",

			"CREATE TABLE {$imports} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL,
  file_name varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'completed',
  summary longtext DEFAULT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type (type)
) {$charset};",

			"CREATE TABLE {$gsc} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  import_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(10) NOT NULL,
  item varchar(1000) NOT NULL DEFAULT '',
  item_hash char(32) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned DEFAULT NULL,
  match_state varchar(20) NOT NULL DEFAULT '',
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  impressions int(10) unsigned NOT NULL DEFAULT 0,
  ctr float NOT NULL DEFAULT 0,
  position float NOT NULL DEFAULT 0,
  prev_clicks int(10) unsigned DEFAULT NULL,
  prev_impressions int(10) unsigned DEFAULT NULL,
  prev_position float DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY kind_object (kind,object_id),
  KEY item_hash (item_hash),
  KEY kind_impressions (kind,impressions)
) {$charset};",

			"CREATE TABLE {$topics} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL,
  title_norm varchar(255) NOT NULL DEFAULT '',
  category_id bigint(20) unsigned DEFAULT NULL,
  focus_keyword varchar(255) DEFAULT NULL,
  intent varchar(30) NOT NULL DEFAULT '',
  notes text DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'new',
  post_id bigint(20) unsigned DEFAULT NULL,
  import_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY title_norm (title_norm(191))
) {$charset};",

			"CREATE TABLE {$suggestions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL,
  target_id bigint(20) unsigned NOT NULL,
  anchor varchar(255) NOT NULL DEFAULT '',
  sentence text DEFAULT NULL,
  origin varchar(20) NOT NULL DEFAULT 'contentmaster',
  status varchar(20) NOT NULL DEFAULT 'pending',
  note text DEFAULT NULL,
  inserted text DEFAULT NULL,
  matched_text varchar(255) DEFAULT NULL,
  applied_at datetime DEFAULT NULL,
  import_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY source_target (source_id,target_id),
  KEY target_status (target_id,status)
) {$charset};",

			"CREATE TABLE {$ai_usage} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  task varchar(30) NOT NULL DEFAULT '',
  provider varchar(20) NOT NULL DEFAULT '',
  model varchar(100) NOT NULL DEFAULT '',
  input_tokens int(10) unsigned NOT NULL DEFAULT 0,
  output_tokens int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(10) NOT NULL DEFAULT 'ok',
  error varchar(255) DEFAULT NULL,
  object_id bigint(20) unsigned DEFAULT NULL,
  duration_ms int(10) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_task (created_at,task)
) {$charset};",
		);
	}
}
