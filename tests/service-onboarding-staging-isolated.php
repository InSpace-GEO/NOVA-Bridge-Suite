<?php
/**
 * Real WordPress onboarding checks in connection-local MySQL TEMPORARY tables.
 *
 * MUST be loaded twice, in one fresh WP-CLI process:
 * wp --skip-plugins --skip-themes --require=THIS_FILE eval-file THIS_FILE
 *
 * The first load installs a fail-closed SQL guard before WordPress bootstrap.
 * The second load redirects core tables and the object cache before loading the
 * Service module. It never invokes activation, flushes rewrites, uploads media,
 * deletes client rows, or temporarily installs client options in real tables.
 * Temporary tables are deliberately not DROPped; closing the connection removes
 * them. Inspect before executing. Local parser checks: php THIS_FILE --self-test.
 */

use SEORAI\ServicePageCPT\ServiceOnboarding as Onboarding;
use SEORAI\ServicePageCPT\ServiceDocument as Document;
use SEORAI\ServicePageCPT\ServiceShared as Shared;
use SEORAI\ServicePageCPT\ServiceSharedAdmin as SharedAdmin;
use SEORAI\ServicePageCPT\ServiceLanguages as Languages;
use SEORAI\ServicePageCPT\ServiceRenderer as Renderer;

if ( ! function_exists( 'nova_obt_sql_structure' ) ) {
	/** Mask string literals, normalize quoted identifiers, reject executable SQL. */
	function nova_obt_sql_structure( string $sql ): string {
		if ( false !== strpos( $sql, "\0" ) ) { throw new RuntimeException( 'Isolation rejected a NUL-containing SQL query.' ); }
		$out = ''; $length = strlen( $sql );
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $sql[ $i ];
			if ( "'" === $char || '"' === $char ) {
				$quote = $char; $closed = false; $out .= ' ? ';
				while ( ++$i < $length ) {
					if ( '\\' === $sql[ $i ] ) { ++$i; continue; }
					if ( $quote === $sql[ $i ] ) {
						if ( $i + 1 < $length && $quote === $sql[ $i + 1 ] ) { ++$i; continue; }
						$closed = true; break;
					}
				}
				if ( ! $closed ) { throw new RuntimeException( 'Isolation rejected an unterminated SQL string.' ); }
				continue;
			}
			if ( '`' === $char ) {
				$end = strpos( $sql, '`', $i + 1 );
				if ( false === $end ) { throw new RuntimeException( 'Isolation rejected an unterminated SQL identifier.' ); }
				$name = substr( $sql, $i + 1, $end - $i - 1 );
				if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $name ) ) { throw new RuntimeException( 'Isolation rejected a complex SQL identifier.' ); }
				$out .= $name; $i = $end; continue;
			}
			if ( '/' === $char && $i + 1 < $length && '*' === $sql[ $i + 1 ] ) {
				if ( $i + 2 < $length && ( '!' === $sql[ $i + 2 ] || ( $i + 3 < $length && 'M' === strtoupper( $sql[ $i + 2 ] ) && '!' === $sql[ $i + 3 ] ) ) ) { throw new RuntimeException( 'Isolation rejected executable SQL comments.' ); }
				$end = strpos( $sql, '*/', $i + 2 );
				if ( false === $end ) { throw new RuntimeException( 'Isolation rejected an unterminated SQL comment.' ); }
				$out .= ' '; $i = $end + 1; continue;
			}
			if ( '#' === $char || ( '-' === $char && $i + 2 < $length && '-' === $sql[ $i + 1 ] && ctype_space( $sql[ $i + 2 ] ) ) ) {
				$end = strpos( $sql, "\n", $i );
				if ( false === $end ) { break; }
				$out .= ' '; $i = $end; continue;
			}
			$out .= $char;
		}
		$out = trim( $out );
		// A trailing delimiter is harmless; any other delimiter means two statements.
		if ( str_ends_with( $out, ';' ) ) { $out = rtrim( substr( $out, 0, -1 ) ); }
		if ( false !== strpos( $out, ';' ) ) { throw new RuntimeException( 'Isolation rejected multiple SQL statements.' ); }
		return $out;
	}

	/** Every mutation must name one exact owned TEMP table, never a prefix match. */
	function nova_obt_guard_query( string $sql, bool $record = true ): string {
		$structure = nova_obt_sql_structure( $sql );
		if ( preg_match( '/^(SELECT|SHOW|DESCRIBE|DESC)\b/i', $structure ) ) {
			if ( preg_match( '/\b(?:INTO|FOR\s+UPDATE|LOCK\s+IN\s+SHARE|GET_LOCK|RELEASE_LOCK|SLEEP|BENCHMARK)\b/i', $structure ) ) { throw new RuntimeException( 'Isolation rejected a read query with side effects.' ); }
			return $sql;
		}
		$owned = $GLOBALS['nova_obt_owned_tables'] ?? [];
		if ( preg_match( '/^CREATE\s+TEMPORARY\s+TABLE\s+([A-Za-z0-9_]+)\s+LIKE\s+([A-Za-z0-9_]+)$/iD', $structure, $match ) ) {
			if ( isset( $owned[ $match[1] ] ) && $owned[ $match[1] ] === $match[2] ) { if ( $record ) { ++$GLOBALS['nova_obt_sql_counts']['temporary_create']; } return $sql; }
			throw new RuntimeException( 'Isolation rejected a temporary-table creation outside the exact whitelist.' );
		}
		$target = '';
		if ( preg_match( '/^(?:INSERT|REPLACE)(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*\s+INTO\s+([A-Za-z0-9_]+)(?=\s|\()/i', $structure, $match ) ) { $target = $match[1]; }
		elseif ( preg_match( '/^UPDATE(?:\s+(?:LOW_PRIORITY|IGNORE))*\s+([A-Za-z0-9_]+)\s+SET\b/i', $structure, $match ) ) { $target = $match[1]; }
		elseif ( preg_match( '/^DELETE\s+FROM\s+([A-Za-z0-9_]+)(?:\s+WHERE\b|\s*$)/i', $structure, $match ) ) { $target = $match[1]; }
		if ( $target && isset( $owned[ $target ] ) && preg_match( '/^nova_obt_[a-f0-9]{12}_[a-z_]+$/D', $target ) ) {
			if ( $record ) { ++$GLOBALS['nova_obt_sql_counts']['temporary_write']; } return $sql;
		}
		// Unknown syntax is a failure. Never relax this to allow a database prefix.
		throw new RuntimeException( 'Isolation blocked a non-read query outside an owned temporary table.' );
	}
	/** Revalidate after other query filters too; count each query only once. */
	function nova_obt_guard_query_last( string $sql ): string { return nova_obt_guard_query( $sql, false ); }

	/** Compare the externally observable JSON contract, not PHP object identity. */
	function nova_obt_canonical_json( $value ): string {
		$normalize = static function ( $value ) use ( &$normalize ) {
			if ( is_object( $value ) ) { $fields = (array) $value; ksort( $fields ); foreach ( $fields as &$field ) { $field = $normalize( $field ); } unset( $field ); return (object) $fields; }
			if ( is_array( $value ) ) { if ( ! array_is_list( $value ) ) { ksort( $value ); } foreach ( $value as &$field ) { $field = $normalize( $field ); } unset( $field ); }
			return $value;
		};
		return json_encode( $normalize( $value ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	}

	/** First unequal JSON path and types only; never print copied private values. */
	function nova_obt_json_difference( $expected, $actual ): ?array {
		$expected = json_decode( nova_obt_canonical_json( $expected ), false, 512, JSON_THROW_ON_ERROR );
		$actual = json_decode( nova_obt_canonical_json( $actual ), false, 512, JSON_THROW_ON_ERROR );
		$compare = static function ( $expected, $actual, string $path ) use ( &$compare ): ?array {
			$expected_type = get_debug_type( $expected ); $actual_type = get_debug_type( $actual );
			if ( $expected_type !== $actual_type ) { return [ 'path' => $path, 'expected_type' => $expected_type, 'actual_type' => $actual_type, 'difference' => 'type' ]; }
			if ( is_object( $expected ) || is_array( $expected ) ) {
				$left = (array) $expected; $right = (array) $actual;
				foreach ( array_unique( array_merge( array_keys( $left ), array_keys( $right ) ) ) as $key ) {
					$child = $path . ( is_array( $expected ) ? '[' . $key . ']' : '.' . $key );
					if ( ! array_key_exists( $key, $left ) || ! array_key_exists( $key, $right ) ) { return [ 'path' => $child, 'expected_type' => array_key_exists( $key, $left ) ? get_debug_type( $left[ $key ] ) : 'missing', 'actual_type' => array_key_exists( $key, $right ) ? get_debug_type( $right[ $key ] ) : 'missing', 'difference' => 'field' ]; }
					$difference = $compare( $left[ $key ], $right[ $key ], $child ); if ( $difference ) { return $difference; }
				}
				return null;
			}
			return $expected === $actual ? null : [ 'path' => $path, 'expected_type' => $expected_type, 'actual_type' => $actual_type, 'difference' => 'value' ];
		};
		return $compare( $expected, $actual, '$' );
	}

	function nova_obt_guard_self_test(): void {
		if ( class_exists( 'NovaOnboardingMemoryCache', false ) ) { throw new RuntimeException( 'The cache class must not be declared by the prebootstrap load.' ); }
		$temp = 'nova_obt_012345abcdef_options';
		$GLOBALS['nova_obt_owned_tables'] = [ $temp => 'wp_options' ];
		$GLOBALS['nova_obt_sql_counts'] = [ 'temporary_create' => 0, 'temporary_write' => 0 ];
		$checks = 0;
		foreach ( [ 'SELECT option_name FROM wp_options', "SELECT 'DROP TABLE wp_options; literal'", "CREATE TEMPORARY TABLE `{$temp}` LIKE `wp_options`", "INSERT IGNORE INTO `{$temp}` (`option_name`,`option_value`) VALUES ('x','UPDATE wp_options;') ON DUPLICATE KEY UPDATE option_value='x'", "UPDATE {$temp} SET option_value='x' WHERE option_name='x'", "DELETE FROM `{$temp}` WHERE option_name='x'", "REPLACE INTO {$temp} (option_name) VALUES ('x')" ] as $query ) { nova_obt_guard_query( $query ); ++$checks; }
		foreach ( [ 'UPDATE wp_options SET option_value=1', 'INSERT INTO wp_posts (ID) VALUES (1)', 'DELETE FROM wp_postmeta WHERE post_id=1', "UPDATE {$temp}_fake SET option_value=1", "UPDATE {$temp} t JOIN wp_options o ON 1=1 SET o.option_value=1", "DELETE FROM {$temp} USING {$temp} JOIN wp_options ON 1=1", "INSERT INTO {$temp} VALUES (1); DELETE FROM wp_options", 'DROP TABLE wp_posts', "DROP TEMPORARY TABLE {$temp}", "CREATE TABLE {$temp} LIKE wp_options", "CREATE TEMPORARY TABLE {$temp} LIKE wp_posts", "CREATE TEMPORARY TABLE nova_obt_012345abcdef_fake LIKE wp_options", "SELECT * INTO OUTFILE '/tmp/test' FROM wp_options", 'SELECT GET_LOCK(1,1)', '/*!50000 UPDATE wp_options SET option_value=1 */', '/*M! UPDATE wp_options SET option_value=1 */ SELECT 1', "UPDATE `wp_options` SET option_value='x'", "INSERT INTO `real_db`.`{$temp}` VALUES (1)", 'CALL unexpected_procedure()', 'SET @x=1', 'TRUNCATE TABLE wp_posts' ] as $query ) {
			$blocked = false; try { nova_obt_guard_query( $query ); } catch ( RuntimeException $error ) { $blocked = true; }
			if ( ! $blocked ) { throw new RuntimeException( 'An unsafe SQL guard fixture was accepted.' ); } ++$checks;
		}
		// Real MariaDB multi-table UPDATE syntax hidden in an executable comment.
		$blocked = false;
		try { nova_obt_guard_query( "UPDATE {$temp} /*M! , wp_options */ SET wp_options.option_value='changed' WHERE wp_options.option_name='home'" ); } catch ( RuntimeException $error ) { $blocked = true; }
		if ( ! $blocked ) { throw new RuntimeException( 'The MariaDB executable-comment regression was accepted.' ); } ++$checks;
		$contract = [ 'draft' => [ 'section' => (object) [ 'body' => 'Saved copy', 'empty' => (object) [] ] ], 'revision' => 1 ];
		$restored_contract = unserialize( serialize( $contract ) );
		if ( $contract === $restored_contract || nova_obt_canonical_json( $contract ) !== nova_obt_canonical_json( $restored_contract ) || null !== nova_obt_json_difference( $contract, $restored_contract ) ) { throw new RuntimeException( 'The JSON equality fixture did not preserve the serialized REST contract.' ); }
		$restored_contract['draft']['section']->body = 'Changed copy';
		if ( '$.draft.section.body' !== ( nova_obt_json_difference( $contract, $restored_contract )['path'] ?? '' ) ) { throw new RuntimeException( 'The JSON field diagnostic missed changed content.' ); }
		if ( null === nova_obt_json_difference( [ 'data' => (object) [] ], [ 'data' => [] ] ) ) { throw new RuntimeException( 'The JSON contract comparator conflated an empty object and array.' ); }
		echo json_encode( [ 'ok' => true, 'guard_checks' => $checks, 'json_contract_checks' => 3, 'prebootstrap_class_deferred' => true, 'wordpress_loaded' => false ], JSON_PRETTY_PRINT ) . "\n";
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	if ( in_array( '--self-test', $_SERVER['argv'] ?? [], true ) ) { nova_obt_guard_self_test(); return; }
	// Never let a bootstrap warning or denied TEMP privilege append to the site's
	// debug.log. WP-CLI also has its own debug setup, so retain a false log constant.
	ini_set( 'error_log', 'php://stderr' ); ini_set( 'log_errors', '1' ); ini_set( 'display_errors', 'stderr' );
	if ( defined( 'WP_DEBUG_LOG' ) && false !== WP_DEBUG_LOG ) { throw new RuntimeException( 'Isolation must start before file-based WordPress debug logging is configured.' ); }
	if ( ! defined( 'WP_DEBUG_LOG' ) ) { define( 'WP_DEBUG_LOG', false ); }
	if ( ! defined( 'NOVA_SERVICE_ISOLATED_BOOTSTRAP' ) ) { define( 'NOVA_SERVICE_ISOLATED_BOOTSTRAP', true ); }
	if ( ! class_exists( 'WP_CLI', false ) || true !== WP_CLI::get_runner()->config['skip-plugins'] || true !== WP_CLI::get_runner()->config['skip-themes'] ) { throw new RuntimeException( 'Isolation requires --skip-plugins and --skip-themes for all plugins and themes.' ); }
	// WP-CLI --skip-plugins still loads MU plugins. Prevent those and cache drop-ins.
	if ( defined( 'WPMU_PLUGIN_DIR' ) ) { throw new RuntimeException( 'Isolated bootstrap must run before the MU plugin path is configured.' ); }
	define( 'WPMU_PLUGIN_DIR', __DIR__ . '/nova-onboarding-no-mu-plugins' );
	// WordPress loads db.php without a suppression filter. Check the actual
	// configured content path after config evaluation and before wp-settings.
	WP_CLI::add_hook( 'after_wp_config_load', static function () {
		ini_set( 'error_log', 'php://stderr' ); ini_set( 'log_errors', '1' );
		$content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		if ( file_exists( $content . '/db.php' ) || ( defined( 'MULTISITE' ) && MULTISITE ) || ( defined( 'SUNRISE' ) && SUNRISE ) ) { throw new RuntimeException( 'Isolated bootstrap refuses custom database drop-ins or multisite before WordPress loads.' ); }
	} );
	$GLOBALS['nova_obt_owned_tables'] = [];
	$GLOBALS['nova_obt_sql_counts'] = [ 'temporary_create' => 0, 'temporary_write' => 0 ];
	$GLOBALS['wp_filter']['query'][PHP_INT_MIN][] = [ 'function' => 'nova_obt_guard_query', 'accepted_args' => 1 ];
	$GLOBALS['wp_filter']['query'][PHP_INT_MAX][] = [ 'function' => 'nova_obt_guard_query_last', 'accepted_args' => 1 ];
	$GLOBALS['wp_filter']['enable_wp_debug_mode_checks'][PHP_INT_MIN][] = [ 'function' => static function () { return false; }, 'accepted_args' => 1 ];
	$GLOBALS['wp_filter']['enable_wp_debug_mode_checks'][PHP_INT_MAX][] = [ 'function' => static function () { return false; }, 'accepted_args' => 1 ];
	$GLOBALS['wp_filter']['enable_loading_advanced_cache_dropin'][PHP_INT_MIN][] = [ 'function' => static function () { return false; }, 'accepted_args' => 1 ];
	$GLOBALS['wp_filter']['enable_loading_advanced_cache_dropin'][PHP_INT_MAX][] = [ 'function' => static function () { return false; }, 'accepted_args' => 1 ];
	$GLOBALS['wp_filter']['enable_loading_object_cache_dropin'][PHP_INT_MIN][] = [ 'function' => static function () { return false; }, 'accepted_args' => 1 ];
	$GLOBALS['wp_filter']['enable_loading_object_cache_dropin'][PHP_INT_MAX][] = [ 'function' => static function () { return false; }, 'accepted_args' => 1 ];
	$GLOBALS['wp_filter']['pre_http_request'][PHP_INT_MIN][] = [ 'function' => static function () { return new WP_Error( 'nova_isolated_http_blocked', 'Network access is disabled for isolated onboarding checks.' ); }, 'accepted_args' => 3 ];
	$GLOBALS['wp_filter']['pre_http_request'][PHP_INT_MAX][] = [ 'function' => static function () { return new WP_Error( 'nova_isolated_http_blocked', 'Network access is disabled for isolated onboarding checks.' ); }, 'accepted_args' => 3 ];
	$GLOBALS['wp_filter']['pre_wp_mail'][PHP_INT_MIN][] = [ 'function' => static function () { return true; }, 'accepted_args' => 2 ];
	$GLOBALS['wp_filter']['pre_wp_mail'][PHP_INT_MAX][] = [ 'function' => static function () { return true; }, 'accepted_args' => 2 ];
	return;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'NOVA_SERVICE_ISOLATED_BOOTSTRAP' ) || ! NOVA_SERVICE_ISOLATED_BOOTSTRAP ) { throw new RuntimeException( 'Use the required isolated WP-CLI prebootstrap command; no test ran.' ); }
ini_set( 'error_log', 'php://stderr' ); ini_set( 'log_errors', '1' );
if ( 'php://stderr' !== ini_get( 'error_log' ) || false !== WP_DEBUG_LOG ) { throw new RuntimeException( 'Isolated process error logging is not confined to stderr.' ); }
if ( is_multisite() || 'wpdb' !== get_class( $GLOBALS['wpdb'] ) ) { throw new RuntimeException( 'This isolation helper supports single-site WordPress with its standard wpdb only.' ); }
if ( class_exists( Onboarding::class, false ) || ! has_filter( 'query', 'nova_obt_guard_query' ) || ! has_filter( 'query', 'nova_obt_guard_query_last' ) ) { throw new RuntimeException( 'Service code was loaded before isolation, or the bootstrap SQL guards are absent.' ); }
if ( ! defined( 'WP_PLUGIN_DIR' ) || ! is_file( WP_PLUGIN_DIR . '/nova-bridge-suite/modules/service-page-cpt/service-page-cpt.php' ) ) { throw new RuntimeException( 'The installed Service CPT module could not be located.' ); }

/** A separate cache implementation: never construct a potentially replaced drop-in class. */
// Conditional declaration is essential: PHP otherwise registers a top-level
// class even when the first --require invocation returns before this line.
if ( ! class_exists( 'NovaOnboardingMemoryCache', false ) ) {
final class NovaOnboardingMemoryCache {
	private $values = []; private $global_groups = []; private $blog_id = 1;
	public $cache_hits = 0; public $cache_misses = 0;
	private function bucket( $group ): string { $group = $group ?: 'default'; return ( isset( $this->global_groups[ $group ] ) ? 'global:' : $this->blog_id . ':' ) . $group; }
	public function get( $key, $group = 'default', $force = false, &$found = null ) { $bucket = $this->bucket( $group ); $found = array_key_exists( $key, $this->values[ $bucket ] ?? [] ); if ( ! $found ) { ++$this->cache_misses; return false; } ++$this->cache_hits; $value = $this->values[ $bucket ][ $key ]; return is_object( $value ) ? clone $value : $value; }
	public function set( $key, $value, $group = 'default', $expire = 0 ): bool { $this->values[ $this->bucket( $group ) ][ $key ] = is_object( $value ) ? clone $value : $value; return true; }
	public function add( $key, $value, $group = 'default', $expire = 0 ): bool { $this->get( $key, $group, false, $found ); return ! $found && $this->set( $key, $value, $group, $expire ); }
	public function replace( $key, $value, $group = 'default', $expire = 0 ): bool { $this->get( $key, $group, false, $found ); return $found && $this->set( $key, $value, $group, $expire ); }
	public function delete( $key, $group = 'default', $deprecated = false ): bool { $bucket = $this->bucket( $group ); $found = array_key_exists( $key, $this->values[ $bucket ] ?? [] ); unset( $this->values[ $bucket ][ $key ] ); return $found; }
	public function incr( $key, $offset = 1, $group = 'default' ) { $value = $this->get( $key, $group, false, $found ); if ( ! $found || ! is_numeric( $value ) ) { return false; } $value = max( 0, (int) $value + (int) $offset ); $this->set( $key, $value, $group ); return $value; }
	public function decr( $key, $offset = 1, $group = 'default' ) { return $this->incr( $key, -(int) $offset, $group ); }
	public function flush(): bool { $this->values = []; return true; }
	public function flush_runtime(): bool { return $this->flush(); }
	public function flush_group( $group ): bool { unset( $this->values[ $this->bucket( $group ) ] ); return true; }
	public function close(): bool { return true; }
	public function switch_to_blog( $id ): void { $this->blog_id = (int) $id; }
	public function add_global_groups( $groups ): void { foreach ( (array) $groups as $group ) { $this->global_groups[ $group ] = true; } }
	public function add_non_persistent_groups( $groups ): void {}
	public function supports( $feature ): bool { return in_array( $feature, [ 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ], true ); }
	public function get_multiple( $keys, $group = 'default', $force = false ): array { $result = []; foreach ( $keys as $key ) { $result[ $key ] = $this->get( $key, $group, $force ); } return $result; }
	public function set_multiple( $values, $group = 'default', $expire = 0 ): array { $result = []; foreach ( $values as $key => $value ) { $result[ $key ] = $this->set( $key, $value, $group, $expire ); } return $result; }
	public function add_multiple( $values, $group = 'default', $expire = 0 ): array { $result = []; foreach ( $values as $key => $value ) { $result[ $key ] = $this->add( $key, $value, $group, $expire ); } return $result; }
	public function delete_multiple( $keys, $group = 'default' ): array { $result = []; foreach ( $keys as $key ) { $result[ $key ] = $this->delete( $key, $group ); } return $result; }
}
}

$wpdb = $GLOBALS['wpdb'];
$checks = []; $failure = null; $restored = false;
$assert = static function ( $condition, string $label ) use ( &$checks ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$logical_tables = [ 'options', 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'comments', 'commentmeta', 'links', 'users', 'usermeta' ];
$old_db = [ 'prefix' => $wpdb->prefix, 'base_prefix' => $wpdb->base_prefix ];
foreach ( $logical_tables as $logical ) {
	if ( ! isset( $wpdb->$logical ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->$logical ) ) { throw new RuntimeException( 'A core table has an unsupported identifier; isolation did not start.' ); }
	$old_db[ $logical ] = $wpdb->$logical;
}
$saved_globals = [];
foreach ( [ 'wp_object_cache', 'wp_roles', 'current_user', 'table_prefix', 'wp_rest_server', 'wp_post_types', '_wp_post_type_features', 'wp_taxonomies', 'wp_meta_keys', 'wp_rewrite', 'post', 'wp_query', 'wp_the_query', 'wp_rest_auth_cookie', 'wp_rest_auth_nonce', 'wp_actions', 'wp_filters', 'wp_current_filter', '_GET', '_POST', '_REQUEST' ] as $key ) {
	$saved_globals[ $key ] = [ 'exists' => array_key_exists( $key, $GLOBALS ), 'value' => $GLOBALS[ $key ] ?? null ];
}
$old_hooks = [];
foreach ( $GLOBALS['wp_filter'] as $key => $hook ) { $old_hooks[ $key ] = is_object( $hook ) ? clone $hook : $hook; }
$prefix = 'nova_obt_' . bin2hex( random_bytes( 6 ) ) . '_';
foreach ( $logical_tables as $logical ) { $GLOBALS['nova_obt_owned_tables'][ $prefix . $logical ] = $old_db[ $logical ]; }
$snapshot = static function () use ( $wpdb, $old_db ): string {
	$posts = $wpdb->get_results( "SELECT * FROM `{$old_db['posts']}` WHERE post_type='service_page' ORDER BY ID", ARRAY_A );
	$meta = $wpdb->get_results( "SELECT m.* FROM `{$old_db['postmeta']}` m INNER JOIN `{$old_db['posts']}` p ON p.ID=m.post_id WHERE p.post_type='service_page' ORDER BY m.meta_id", ARRAY_A );
	$options = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$old_db['options']}` WHERE option_name LIKE %s OR option_name LIKE %s OR option_name=%s ORDER BY option_name", $wpdb->esc_like( 'service_cpt_' ) . '%', $wpdb->esc_like( 'nova_service_' ) . '%', 'nova_bridge_settings' ), ARRAY_A );
	return hash( 'sha256', serialize( [ $posts, $meta, $options ] ) );
};
$original_snapshot = $snapshot();

try {
	// Copy only public site/locale/theme settings and the role map. No service,
	// connector, active-plugin, cron, upload, or arbitrary customer options.
	$option_names = [ 'siteurl', 'home', 'blogname', 'blogdescription', 'blog_charset', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'WPLANG', 'template', 'stylesheet', 'permalink_structure', 'default_role', $old_db['prefix'] . 'user_roles' ];
	$placeholders = implode( ',', array_fill( 0, count( $option_names ), '%s' ) );
	$site_options = $wpdb->get_results( $wpdb->prepare( "SELECT option_name,option_value,autoload FROM `{$old_db['options']}` WHERE option_name IN ({$placeholders})", $option_names ), ARRAY_A );
	$roles_option = null;
	foreach ( $site_options as $row ) { if ( $old_db['prefix'] . 'user_roles' === $row['option_name'] ) { $roles_option = $row; } }
	$assert( null !== $roles_option, 'The original role definition is available through a read-only query' );
	$cap_key = $old_db['prefix'] . 'capabilities'; $admin_id = 0;
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT user_id,meta_value FROM `{$old_db['usermeta']}` WHERE meta_key=%s ORDER BY user_id", $cap_key ), ARRAY_A ) as $row ) {
		$caps = maybe_unserialize( $row['meta_value'] );
		if ( is_array( $caps ) && ! empty( $caps['administrator'] ) ) { $admin_id = (int) $row['user_id']; break; }
	}
	$assert( $admin_id > 0, 'An existing administrator can be copied into the isolated user tables' );
	$admin_meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key,meta_value FROM `{$old_db['usermeta']}` WHERE user_id=%d AND meta_key IN (%s,%s)", $admin_id, $cap_key, $old_db['prefix'] . 'user_level' ), ARRAY_A );
	foreach ( $logical_tables as $logical ) {
		$temp = $prefix . $logical;
		if ( false === $wpdb->query( "CREATE TEMPORARY TABLE `{$temp}` LIKE `{$old_db[$logical]}`" ) ) { throw new RuntimeException( 'MySQL temporary-table isolation is unavailable; no client mutation was attempted.' ); }
	}
	$assert( count( $logical_tables ) === $GLOBALS['nova_obt_sql_counts']['temporary_create'], 'All twelve single-site core tables have exact connection-local copies of their schemas' );
	// A single authorized user row is copied without exposing authentication data.
	if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO `{$prefix}users` SELECT * FROM `{$old_db['users']}` WHERE ID=%d", $admin_id ) ) ) { throw new RuntimeException( 'The isolated administrator copy failed.' ); }
	foreach ( $logical_tables as $logical ) { $wpdb->$logical = $prefix . $logical; }
	$wpdb->prefix = $prefix; $wpdb->base_prefix = $prefix; $GLOBALS['table_prefix'] = $prefix;
	$GLOBALS['wp_object_cache'] = new NovaOnboardingMemoryCache();
	$GLOBALS['wp_object_cache']->add_global_groups( [ 'users', 'userlogins', 'usermeta', 'user_meta', 'site-options' ] );
	foreach ( $site_options as $row ) {
		if ( $old_db['prefix'] . 'user_roles' === $row['option_name'] ) { $row['option_name'] = $prefix . 'user_roles'; }
		if ( false === $wpdb->insert( $wpdb->options, $row ) ) { throw new RuntimeException( 'A whitelisted isolated site option could not be seeded.' ); }
	}
	foreach ( $admin_meta as $row ) {
		$row['meta_key'] = $prefix . substr( $row['meta_key'], strlen( $old_db['prefix'] ) ); $row['user_id'] = $admin_id;
		if ( false === $wpdb->insert( $wpdb->usermeta, $row ) ) { throw new RuntimeException( 'An isolated administrator capability could not be seeded.' ); }
	}
	$GLOBALS['wp_roles'] = new WP_Roles(); wp_set_current_user( $admin_id );
	$assert( current_user_can( 'manage_options' ) && $GLOBALS['wp_object_cache'] instanceof NovaOnboardingMemoryCache, 'Permissions use copied roles and a private memory-only cache' );
	$assert( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ), 'No client posts, media or taxonomy content was copied into isolation' );
	$assert( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'service_cpt_%' OR option_name LIKE 'nova_service_%'" ), 'No client Service options were copied into isolation' );

	// Clone mutable rewrite state. Registering a CPT changes this object in memory;
	// activation/flush is intentionally never called.
	if ( isset( $GLOBALS['wp_rewrite'] ) ) { $GLOBALS['wp_rewrite'] = clone $GLOBALS['wp_rewrite']; }
	unset( $GLOBALS['wp_rest_server'] );
	require_once WP_PLUGIN_DIR . '/nova-bridge-suite/modules/service-page-cpt/includes/class-service-onboarding.php';
	foreach ( Onboarding::factory_settings() as $key => $value ) { add_option( $key, is_array( $value ) ? $value[0] : $value, '', false ); }
	// Definitions only; do not bootstrap other suite modules or language plugins.
	require_once WP_PLUGIN_DIR . '/nova-bridge-suite/includes/class-nova-bridge-suite-wpml-support.php';
	require_once WP_PLUGIN_DIR . '/nova-bridge-suite/modules/service-page-cpt/service-page-cpt.php';
	$module = SEORAI\ServicePageCPT\Plugin::instance();
	$module->register_post_type(); $module->register_meta_fields(); Document::register_meta();
	// Keep the last guard last even if a CLI extension had added a query filter.
	remove_filter( 'query', 'nova_obt_guard_query_last', PHP_INT_MAX );
	add_filter( 'query', 'nova_obt_guard_query_last', PHP_INT_MAX );

	// Defense in depth if a WP-CLI extension registered lifecycle hooks: permit
	// only WordPress core or the Service module. Restore every hook in finally.
	$allowed_callback = static function ( $callback ): bool {
		try {
			$reflection = is_array( $callback ) ? new ReflectionMethod( $callback[0], $callback[1] ) : ( is_object( $callback ) && ! $callback instanceof Closure ? new ReflectionMethod( $callback, '__invoke' ) : new ReflectionFunction( $callback ) );
			$file = str_replace( '\\', '/', (string) $reflection->getFileName() );
			$root = str_replace( '\\', '/', ABSPATH );
			$service = str_replace( '\\', '/', WP_PLUGIN_DIR . '/nova-bridge-suite/modules/service-page-cpt/' );
			return str_starts_with( $file, $root . 'wp-includes/' ) || str_starts_with( $file, $root . 'wp-admin/' ) || str_starts_with( $file, $service );
		} catch ( ReflectionException $error ) { return false; }
	};
	foreach ( array_keys( $GLOBALS['wp_filter'] ) as $name ) {
		if ( ! preg_match( '/^(?:save_post|wp_insert_post|wp_after_insert_post|pre_post_update|post_updated|transition_post_status|(?:draft|pending|future|publish|private|trash|auto-draft)_to_|(?:draft|pending|future|publish|private|trash)_service_page|rest_(?:pre_insert|after_insert|insert|prepare)|add_post_meta|added_post_meta|update_post_meta|updated_post_meta|delete_post_meta|deleted_post_meta)/', $name ) ) { continue; }
		foreach ( $GLOBALS['wp_filter'][ $name ]->callbacks as $priority => $callbacks ) { foreach ( $callbacks as $entry ) { if ( ! $allowed_callback( $entry['function'] ) ) { remove_filter( $name, $entry['function'], $priority ); } } }
	}
	// These filters are kept at both edges so later callbacks cannot undo them.
	$http_block = static function () { return new WP_Error( 'nova_isolated_http_blocked', 'Network access is disabled for isolated onboarding checks.' ); };
	$mail_block = static function () { return true; };
	add_filter( 'pre_http_request', $http_block, PHP_INT_MAX, 3 ); add_filter( 'pre_wp_mail', $mail_block, PHP_INT_MAX, 2 );
	$assert( is_wp_error( apply_filters( 'pre_http_request', false, [], 'https://example.test' ) ) && true === apply_filters( 'pre_wp_mail', null, [] ), 'HTTP and mail actions are disabled without sending a request' );
	$assert( false === apply_filters( 'enable_wp_debug_mode_checks', true ) && 'php://stderr' === ini_get( 'error_log' ), 'Database and bootstrap errors cannot append to the site debug log' );
	$assert( $original_snapshot === $snapshot(), 'Loading the module and factory seeds leaves real client posts and settings unchanged' );

	$request = static function ( string $method, string $route, array $params = [] ) {
		$r = new WP_REST_Request( $method, $route ); $r->set_body_params( $params ); return rest_do_request( $r );
	};
	$setup = static function ( string $action, int $revision, array $draft = [], string $step = 'review' ) use ( $request ) { return $request( 'POST', '/nova-service/v1/onboarding', [ 'action' => $action, 'expected_revision' => $revision, 'draft' => $draft, 'step' => $step ] ); };
	$setup_settings = static function ( array $draft, ?string $language = null, ?array $state = null ) use ( $request ) {
		$state = $state ?? Onboarding::state( $language ); $params = [ 'action' => 'save_settings', 'expected_revision' => $state['revision'], 'expected_fingerprint' => $state['fingerprint'], 'draft' => $draft, 'step' => 'review' ];
		if ( null !== $language ) { $params['language'] = $language; }
		return $request( 'POST', '/nova-service/v1/onboarding', $params );
	};
	$count_services = static function () use ( $wpdb ): int { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='service_page'" ); };
	$state_response = $request( 'GET', '/nova-service/v1/onboarding' ); $state = $state_response->get_data();
	$initial_blogname = get_bloginfo( 'name' );
	$assert( 200 === $state_response->get_status() && true === $state['required'] && false === $state['complete'] && 'fresh' === $state['classification'] && 'fresh_install' === $state['reason'] && 0 === $state['revision'], 'Factory activation seeds alone are classified as fresh and require setup' );
	$assert( ! Onboarding::active_settings() && 0 === $count_services(), 'Fresh setup creates neither an active client bundle nor automatic service posts' );
	$pending_settings_before = get_option( Onboarding::OPTION_STATE ); $pending_settings = $setup_settings( $state['draft'] );
	$assert( 409 === $pending_settings->get_status() && nova_obt_canonical_json( $pending_settings_before ) === nova_obt_canonical_json( get_option( Onboarding::OPTION_STATE ) ) && Onboarding::is_required(), 'Editable setup saves cannot bypass required fresh-install activation' );
	wp_set_current_user( 0 ); $anonymous = $request( 'GET', '/nova-service/v1/onboarding' );
	$assert( 403 === $anonymous->get_status(), 'Anonymous requests cannot read private onboarding drafts' );
	wp_set_current_user( $admin_id );
	$rest_base = get_post_type_object( 'service_page' )->rest_base;
	$native = $request( 'POST', '/wp/v2/' . $rest_base, [ 'title' => 'Blocked native publish', 'status' => 'publish', 'nova_service_content' => [ 'rich_text_1' => [ 'body' => '<p>Blocked copy.</p>' ] ] ] );
	$legacy = $request( 'POST', '/wp/v2/' . $rest_base, [ 'title' => 'Blocked legacy publish', 'status' => 'publish', 'content' => '<p>Legacy content.</p>', 'meta' => [ 'sp_intro' => 'Legacy offer' ] ] );
	$assert( 409 === $native->get_status() && 409 === $legacy->get_status() && 0 === $count_services(), 'Pending setup rejects native and legacy REST publishing before any post insertion' );
	$assert( is_wp_error( Shared::save_settings( $state['draft']['presets'], $state['draft']['template'] ) ) && ! get_option( Shared::OPTION_PRESETS, false ), 'Ordinary shared settings cannot bypass required fresh setup' );
	$progress = $setup( 'save', 0, [ 'business' => [ 'name' => 'Isolated onboarding business', 'website' => 'https://example.test/' ] ], 'cta' );
	$progress_state = $progress->get_data();
	$assert( 200 === $progress->get_status() && 1 === $progress_state['revision'] && 'cta' === $progress_state['step'] && 'Isolated onboarding business' === $progress_state['draft']['business']['name'], 'Actual REST progress saves a sanitized identity and next-step revision' );
	$resumed = $request( 'GET', '/nova-service/v1/onboarding' )->get_data();
	$resume_difference = nova_obt_json_difference( $progress_state, $resumed );
	$assert( null === $resume_difference && ! Onboarding::active_settings(), 'Resuming setup returns the saved draft without activating defaults' . ( $resume_difference ? ' (JSON mismatch: ' . json_encode( $resume_difference ) . ')' : '' ) );
	$state_hash = hash( 'sha256', serialize( get_option( Onboarding::OPTION_STATE ) ) );
	$stale = $setup( 'save', 0, [ 'business' => [ 'name' => 'Stale overwrite' ] ] );
	$assert( 409 === $stale->get_status() && $state_hash === hash( 'sha256', serialize( get_option( Onboarding::OPTION_STATE ) ) ), 'A stale REST revision cannot overwrite saved onboarding progress' );
	$missing_identity = $setup( 'finish', 1, [ 'business' => [ 'name' => '' ] ] ); $missing_cta = $setup( 'finish', 1 );
	$assert( 400 === $missing_identity->get_status() && 400 === $missing_cta->get_status() && ! Onboarding::active_settings() && $state_hash === hash( 'sha256', serialize( get_option( Onboarding::OPTION_STATE ) ) ), 'Incomplete identity or action cannot activate the bundle or alter the saved draft' );

	$draft_id = wp_insert_post( [ 'post_type' => 'service_page', 'post_status' => 'publish', 'post_title' => 'Isolated manual setup draft', 'post_content' => '', 'post_author' => $admin_id ], true );
	$assert( ! is_wp_error( $draft_id ) && $draft_id > 0 && 'draft' === get_post_status( $draft_id ) && Document::is_v2( $draft_id ), 'Manual publishing becomes an editable native draft while setup is pending' );
	$factory_render = Renderer::render( $draft_id );
	$assert( ! str_contains( $factory_render, '--nova-service-primary:#0F172A' ) && ! str_contains( $factory_render, '--nova-service-accent:#2563EB' ), 'Fresh factory colors yield to the site brand instead of overriding it' );
	$finish = $resumed['draft'];
	$form = '<form action="/isolated-booking/" method="post"><label>Name<input name="name" required></label><button type="submit">Request appointment</button></form>';
	foreach ( $finish['presets']['sections'] as &$preset ) {
		if ( 'default_hero' === $preset['id'] ) { $preset['data']['buttons'] = [ [ 'label' => 'Book an appointment', 'url' => '#nova-service-booking' ] ]; }
		if ( 'default_custom_cta' === $preset['id'] ) { $preset['data']['html'] = $form; }
	} unset( $preset );
	foreach ( $finish['template']['sections'] as &$section ) { if ( 'custom_cta' === $section['type'] ) { $section['id'] = 'booking'; $section['enabled'] = true; } } unset( $section );
	$finish['ui']['cta_type'] = 'form'; $finish['ui']['cta_label'] = 'Book an appointment'; $finish['ui']['cta_target'] = $form;
	$bad_anchor = $finish;
	foreach ( $bad_anchor['presets']['sections'] as &$preset ) { if ( 'default_hero' === $preset['id'] ) { $preset['data']['buttons'][0]['url'] = '#booking'; } } unset( $preset );
	$anchor_error = $setup( 'finish', 1, $bad_anchor );
	$assert( 400 === $anchor_error->get_status() && ! Onboarding::active_settings(), 'An anchor must use the actual rendered form section ID before activation' );
	$before_finish_count = $count_services();
	$rival = [ 'version' => 2, 'sections' => [ [ 'id' => 'rival', 'type' => 'cta', 'enabled' => true, 'data' => [ 'title' => 'Concurrent temporary-only defaults' ] ] ] ];
	add_option( Shared::OPTION_PRESETS, $rival, '', false );
	$conflict = $setup( 'finish', 1, $finish );
	$assert( 409 === $conflict->get_status() && $rival === get_option( Shared::OPTION_PRESETS ) && ! Onboarding::active_settings(), 'Finishing detects a concurrent defaults change and preserves that temporary-only configuration' );
	delete_option( Shared::OPTION_PRESETS );
	$completed = $setup( 'finish', 1, $finish ); $completed_state = $completed->get_data();
	$assert( 200 === $completed->get_status() && true === $completed_state['complete'] && false === $completed_state['required'] && 2 === $completed_state['revision'], 'A real form CTA completes onboarding through the actual REST route' );
	$active = Onboarding::active_settings();
	$assert( $active && 'inherit_site' === $active['brand']['mode'] && 'Isolated onboarding business' === $active['business']['name'] && ! get_option( Shared::OPTION_PRESETS, false ) && ! get_option( Shared::OPTION_TEMPLATE, false ), 'Activation installs one canonical bundle without partially active separate settings' );
	$assert( $initial_blogname === get_bloginfo( 'name' ), 'Service setup preserves the WordPress site identity' );
	$assert( true === Shared::validate_settings( $active['presets'], $active['template'] ) && [] === Shared::warnings( $active['template'] ), 'Every activated layout reference resolves to a valid shared section' );
	$assert( $before_finish_count === $count_services(), 'Finishing setup never creates demonstration or service posts automatically' );
	$active_hash = hash( 'sha256', serialize( $active ) );
	$repeat = $setup( 'finish', 2, $finish );
	$assert( 409 === $repeat->get_status() && $active_hash === hash( 'sha256', serialize( Onboarding::active_settings() ) ), 'A completed bundle cannot be activated a second time' );
	$new_page = $request( 'POST', '/wp/v2/' . $rest_base, [ 'title' => 'Isolated native service', 'status' => 'publish', 'nova_service_content' => [ 'rich_text_1' => [ 'title' => 'Actual offer', 'body' => '<p>Isolated service copy.</p>' ] ] ] );
	$new_page_data = $new_page->get_data(); $published_id = (int) ( $new_page_data['id'] ?? 0 );
	$assert( 201 === $new_page->get_status() && $published_id > 0 && 'publish' === get_post_status( $published_id ) && Document::is_v2( $published_id ), 'Routine native REST publication succeeds after setup is complete' );
	$rendered = Renderer::render( $published_id );
	$assert( str_contains( $rendered, 'href="#nova-service-booking"' ) && str_contains( $rendered, 'id="nova-service-booking"' ) && str_contains( $rendered, '<form' ) && str_contains( $rendered, 'Isolated service copy.' ), 'The actual native page renders its shared action, working form target and supplied copy' );
	$assert( ! str_contains( $rendered, '--nova-service-primary:#0F172A' ) && ! str_contains( $rendered, '--nova-service-accent:#2563EB' ), 'Completed site-brand mode still disregards untouched factory color seeds' );
	$invalid_template = $active['template']; $invalid_template['sections'][0]['shared_key'] = 'missing_shared_preset';
	$bad_shared = Shared::save_settings( $active['presets'], $invalid_template );
	$assert( is_wp_error( $bad_shared ) && $active_hash === hash( 'sha256', serialize( Onboarding::active_settings() ) ), 'A bad subsequent template cannot partially alter the active canonical bundle' );
	$updated_presets = $active['presets'];
	foreach ( $updated_presets['sections'] as &$preset ) { if ( 'default_hero' === $preset['id'] ) { $preset['data']['buttons'][0]['label'] = 'Updated shared booking'; } } unset( $preset );
	$save_shared = Shared::save_settings( $updated_presets, $active['template'] ); $updated_active = Onboarding::active_settings();
	$assert( true === $save_shared && nova_obt_canonical_json( $active['business'] ) === nova_obt_canonical_json( $updated_active['business'] ) && nova_obt_canonical_json( $active['template'] ) === nova_obt_canonical_json( $updated_active['template'] ) && nova_obt_canonical_json( $active['presets'] ) !== nova_obt_canonical_json( $updated_active['presets'] ) && ! get_option( Shared::OPTION_PRESETS, false ), 'Later shared defaults update the same bundle atomically and preserve business identity' );
	$assert( str_contains( Renderer::render( $published_id ), 'Updated shared booking' ), 'Existing linked service pages use the updated canonical shared action' );
	update_option( 'service_cpt_color_accent', '#123456', false );
	$custom_render = Renderer::render( $published_id );
	$assert( str_contains( $custom_render, '--nova-service-accent:#123456' ) && ! str_contains( $custom_render, '--nova-service-primary:#0F172A' ), 'A customized color is retained while untouched factory colors continue inheriting the site' );
	update_option( 'service_cpt_color_accent', '#2563EB', false );

	// Business settings are an explicit identity-only save, including on sites
	// whose required initial setup has already been completed.
	$business_state = $request( 'GET', '/nova-service/v1/onboarding' )->get_data();
	$business_before_active = Onboarding::active_settings(); $business_before_draft = get_option( Onboarding::OPTION_STATE );
	$business_before_posts = hash( 'sha256', serialize( [ $wpdb->get_results( "SELECT * FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ), $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_A ) ] ) );
	$business_saved = $request( 'POST', '/nova-service/v1/onboarding', [ 'action' => 'save_business', 'expected_revision' => $business_state['revision'], 'expected_fingerprint' => $business_state['fingerprint'], 'draft' => [ 'business' => [ 'phone' => '+31 40 1234567' ] ] ] );
	$business_after_state = $business_saved->get_data(); $expected_active = $business_before_active; $expected_active['business']['phone'] = '+31 40 1234567'; $expected_active['onboarding_revision']++;
	$assert( 200 === $business_saved->get_status() && nova_obt_canonical_json( $expected_active ) === nova_obt_canonical_json( Onboarding::active_settings() ) && nova_obt_canonical_json( $business_before_draft ) === nova_obt_canonical_json( get_option( Onboarding::OPTION_STATE ) ), 'Completed setup business save changes only identity and revision while preserving the canonical proof, template and saved draft' );
	$assert( $business_before_posts === hash( 'sha256', serialize( [ $wpdb->get_results( "SELECT * FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ), $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_A ) ] ) ), 'Business settings do not modify any service content or metadata' );
	$business_stale = $request( 'POST', '/nova-service/v1/onboarding', [ 'action' => 'save_business', 'expected_revision' => $business_state['revision'], 'expected_fingerprint' => $business_state['fingerprint'], 'draft' => [ 'business' => [ 'name' => 'Stale identity' ] ] ] );
	$assert( 409 === $business_stale->get_status() && nova_obt_canonical_json( $expected_active ) === nova_obt_canonical_json( Onboarding::active_settings() ), 'An old business settings revision cannot overwrite a completed setup identity' );
	$business_scope = $request( 'POST', '/nova-service/v1/onboarding', [ 'action' => 'save_business', 'expected_revision' => $business_after_state['revision'], 'expected_fingerprint' => $business_after_state['fingerprint'], 'draft' => [ 'business' => [ 'name' => 'Wrong scope' ], 'presets' => [ 'version' => 2, 'sections' => [] ] ] ] );
	$assert( 400 === $business_scope->get_status() && nova_obt_canonical_json( $expected_active ) === nova_obt_canonical_json( Onboarding::active_settings() ), 'Business settings reject attempts to submit or clear shared content' );
	$reopened_state = Onboarding::state(); $reopened_active = Onboarding::active_settings(); $reopened_lifecycle = get_option( Onboarding::OPTION_STATE );
	$reopened_noop = $setup_settings( $reopened_state['draft'] );
	$assert( 200 === $reopened_noop->get_status() && 'business' === $reopened_state['step'] && nova_obt_canonical_json( $reopened_active ) === nova_obt_canonical_json( Onboarding::active_settings() ) && nova_obt_canonical_json( $reopened_lifecycle ) === nova_obt_canonical_json( get_option( Onboarding::OPTION_STATE ) ), 'Completed fresh setup reopens the same five screens and a no-op final save preserves every canonical bundle field and revision' );
	$reopened_edit = $reopened_state['draft'];
	foreach ( $reopened_edit['presets']['sections'] as &$preset ) { if ( 'default_people' === $preset['id'] ) { $preset['data']['body'] = '<p>Actual team introduction from reopened setup.</p>'; } if ( 'default_reviews' === $preset['id'] ) { $preset['data']['layout'] = 'compact_rail'; } } unset( $preset );
	$reopened_saved = $setup_settings( $reopened_edit ); $reopened_expected = $reopened_active;
	foreach ( $reopened_expected['presets']['sections'] as &$preset ) { if ( 'default_people' === $preset['id'] ) { $preset['data']['body'] = '<p>Actual team introduction from reopened setup.</p>'; } if ( 'default_reviews' === $preset['id'] ) { $preset['data']['layout'] = 'compact_rail'; } } unset( $preset ); $reopened_expected['onboarding_revision']++;
	$assert( 200 === $reopened_saved->get_status() && nova_obt_canonical_json( $reopened_expected ) === nova_obt_canonical_json( Onboarding::active_settings() ) && ! get_option( Shared::OPTION_PRESETS, false ) && ! get_option( Shared::OPTION_TEMPLATE, false ) && nova_obt_canonical_json( $reopened_lifecycle ) === nova_obt_canonical_json( get_option( Onboarding::OPTION_STATE ) ), 'Explicit reopened setup saves chosen proof and team fields in the single active bundle without touching fresh lifecycle progress or creating duplicate raw defaults' );

	// The new settings information architecture hides the legacy tab when all
	// saved services use native documents and keeps old bookmarks useful.
	require_once ABSPATH . 'wp-admin/includes/template.php';
	$settings_mode = new ReflectionMethod( SharedAdmin::class, 'mode' );
	$render_settings = static function ( string $tab ): string { $_GET = [ 'page' => 'nova-service-shared', 'post_type' => 'service_page', 'tab' => $tab ]; ob_start(); SharedAdmin::render(); return (string) ob_get_clean(); };
	$assert( ! SharedAdmin::has_legacy_pages(), 'Native-only service installations do not expose legacy settings' );
	$defaults_html = $render_settings( 'defaults' );
	$assert( ! str_contains( $defaults_html, 'tab=legacy' ) && str_contains( $defaults_html, 'tab=setup' ) && ! str_contains( $defaults_html, 'id="nova-service-setup-business"' ), 'Page defaults show one starting layout and a Setup settings link without duplicating setup identity fields' );
	$setup_html = $render_settings( 'setup' );
	$assert( str_contains( $setup_html, 'id="nova-service-onboarding"' ) && ! str_contains( $setup_html, 'id="nova-service-setup-business"' ) && ! str_contains( $setup_html, 'id="nova-service-editor"' ), 'Completed Setup settings use the same five-screen wizard instead of a different shared-editor settings screen' );
	$_GET = [ 'page' => 'nova-service-shared', 'post_type' => 'service_page', 'tab' => 'setup', 'view' => 'advanced' ]; ob_start(); SharedAdmin::render(); $advanced_setup_html = (string) ob_get_clean();
	$assert( str_contains( $advanced_setup_html, 'id="nova-service-editor"' ) && ! str_contains( $advanced_setup_html, 'id="nova-service-onboarding"' ), 'Optional review imports and reusable variants stay in advanced Setup settings rather than replacing the five screens' );
	foreach ( [ 'library' => 'setup', 'template' => 'defaults', 'legacy' => 'defaults' ] as $old_tab => $new_mode ) { $_GET['tab'] = $old_tab; $assert( $new_mode === $settings_mode->invoke( null ), 'Existing ' . $old_tab . ' bookmarks resolve to the consolidated ' . $new_mode . ' screen' ); }

	// Reset only the temporary lifecycle rows for upgrade classification checks.
	$reset_setup = static function (): void { foreach ( [ Onboarding::OPTION_STATE, Onboarding::OPTION_CONFIGURATION, Shared::OPTION_PRESETS, Shared::OPTION_TEMPLATE, 'nova_service_shared_translations', 'nova_service_onboarding_lock' ] as $key ) { delete_option( $key ); } };
	$reset_setup();
	// Use raw, guarded TEMP mutations so this fixture represents an old client
	// whose orphaned status is not registered in this fresh process.
	$wpdb->delete( $wpdb->posts, [ 'ID' => $published_id ], [ '%d' ] );
	$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $published_id ], [ '%d' ] );
	update_post_meta( $draft_id, Document::META_MODE, 'legacy' ); delete_post_meta( $draft_id, Document::META_DOCUMENT );
	update_post_meta( $draft_id, 'sp_intro', 'Preserve this legacy offer exactly.' );
	foreach ( [ 'draft', 'trash', 'obsolete_custom_status' ] as $status ) {
		$reset_setup(); $wpdb->update( $wpdb->posts, [ 'post_status' => $status, 'post_content' => '<p>Unchanged old client content.</p>' ], [ 'ID' => $draft_id ], [ '%s', '%s' ], [ '%d' ] );
		$GLOBALS['wp_object_cache']->flush();
		$before_legacy = hash( 'sha256', serialize( [ $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d", $draft_id ), ARRAY_A ), $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id", $draft_id ), ARRAY_A ) ] ) );
		Onboarding::initialize(); $upgrade = $request( 'GET', '/nova-service/v1/onboarding' )->get_data();
		$guard_request = new WP_REST_Request( 'POST', '/wp/v2/' . $rest_base ); $guard_request->set_body_params( [ 'status' => 'publish' ] );
		$assert( 'upgrade' === $upgrade['classification'] && 'existing_services' === $upgrade['reason'] && false === $upgrade['required'] && true === Onboarding::guard_rest_request( $guard_request, $draft_id ), 'An existing ' . $status . ' service bypasses new-install setup' );
		$after_legacy = hash( 'sha256', serialize( [ $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d", $draft_id ), ARRAY_A ), $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id", $draft_id ), ARRAY_A ) ] ) );
		$assert( $before_legacy === $after_legacy && ! Document::is_v2( $draft_id ) && ! Onboarding::active_settings(), 'Upgrade classification preserves all legacy content, metadata and renderer choice for ' . $status );
		$assert( SharedAdmin::has_legacy_pages(), 'An existing ' . $status . ' legacy service retains access to legacy settings' );
		if ( 'draft' === $status ) {
			$upgrade_presets = [ 'version' => 2, 'sections' => [] ];
			foreach ( [ 'hero', 'cta', 'custom_cta', 'credentials', 'reviews', 'location', 'map', 'people', 'video' ] as $type ) { $upgrade_presets['sections'][] = [ 'id' => 'default_' . $type, 'type' => $type, 'enabled' => true, 'data' => [ 'title' => 'Real shared ' . $type, 'body' => '<p>Existing shared content.</p>' ] ]; }
			for ( $i = 1; $i <= 13; ++$i ) { $upgrade_presets['sections'][] = [ 'id' => 'client_variant_' . $i, 'type' => 'reviews', 'enabled' => true, 'data' => [ 'title' => 'Client variant ' . $i, 'items' => [ [ 'author' => 'Existing customer', 'body' => 'Preserve existing quotation.', 'source' => 'Google' ] ] ] ]; }
			$upgrade_template = [ 'version' => 2, 'sections' => [ [ 'id' => 'rich_text_1', 'type' => 'rich_text', 'enabled' => true, 'content_slot' => 'rich_text_1', 'data' => [ 'title' => '', 'body' => '' ] ], [ 'id' => 'proof', 'type' => 'reviews', 'enabled' => true, 'shared_key' => 'default_reviews', 'data' => (object) [] ] ] ];
			$assert( true === Shared::save_settings( $upgrade_presets, $upgrade_template ) && 22 === count( get_option( Shared::OPTION_PRESETS )['sections'] ), 'An upgrade retains its established raw storage with all twenty-two existing reusable sections' );
			$upgrade_state = $request( 'GET', '/nova-service/v1/onboarding' )->get_data();
			$upgrade_raw_presets = get_option( Shared::OPTION_PRESETS ); $upgrade_raw_template = get_option( Shared::OPTION_TEMPLATE ); $upgrade_lifecycle = get_option( Onboarding::OPTION_STATE );
			$assert( true === $upgrade_state['complete'] && true === $upgrade_state['editable'] && 22 === count( $upgrade_state['draft']['presets']['sections'] ) && nova_obt_canonical_json( $upgrade_raw_template ) === nova_obt_canonical_json( $upgrade_state['draft']['template'] ), 'Existing Setup settings load actual saved variants and an arbitrary non-hero-first layout' );
			$upgrade_business = $request( 'POST', '/nova-service/v1/onboarding', [ 'action' => 'save_business', 'expected_revision' => $upgrade_state['revision'], 'expected_fingerprint' => $upgrade_state['fingerprint'], 'draft' => [ 'business' => [ 'name' => 'Updated upgrade business' ] ] ] );
			$upgrade_expected_lifecycle = $upgrade_lifecycle; $upgrade_expected_lifecycle['business'] = $upgrade_state['draft']['business']; $upgrade_expected_lifecycle['business']['name'] = 'Updated upgrade business'; $upgrade_expected_lifecycle['revision']++;
			$assert( 200 === $upgrade_business->get_status() && nova_obt_canonical_json( $upgrade_expected_lifecycle ) === nova_obt_canonical_json( get_option( Onboarding::OPTION_STATE ) ) && ! Onboarding::active_settings() && nova_obt_canonical_json( $upgrade_raw_presets ) === nova_obt_canonical_json( get_option( Shared::OPTION_PRESETS ) ) && nova_obt_canonical_json( $upgrade_raw_template ) === nova_obt_canonical_json( get_option( Shared::OPTION_TEMPLATE ) ), 'Upgrade business save updates only identity and revision without activating a competing defaults bundle or changing twenty-two saved variants' );
			$upgrade_latest = $upgrade_business->get_data(); $upgrade_noop = $setup_settings( $upgrade_latest['draft'] );
			$assert( 200 === $upgrade_noop->get_status() && nova_obt_canonical_json( $upgrade_raw_presets ) === nova_obt_canonical_json( get_option( Shared::OPTION_PRESETS ) ) && nova_obt_canonical_json( $upgrade_raw_template ) === nova_obt_canonical_json( get_option( Shared::OPTION_TEMPLATE ) ) && nova_obt_canonical_json( $upgrade_expected_lifecycle ) === nova_obt_canonical_json( get_option( Onboarding::OPTION_STATE ) ), 'A no-op upgrade walkthrough preserves all twenty-two actual preset variants and its arbitrary content-first layout' );
			$upgrade_edit = $upgrade_latest['draft']; foreach ( $upgrade_edit['presets']['sections'] as &$preset ) { if ( 'default_people' === $preset['id'] ) { $preset['data']['body'] = '<p>Updated real team content.</p>'; } } unset( $preset ); $upgrade_edit['business']['phone'] = '+31 40 2222222';
			$upgrade_saved = $setup_settings( $upgrade_edit ); $upgrade_expected_raw = $upgrade_raw_presets;
			foreach ( $upgrade_expected_raw['sections'] as &$preset ) { if ( 'default_people' === $preset['id'] ) { $preset['data']['body'] = '<p>Updated real team content.</p>'; } } unset( $preset ); $upgrade_expected_lifecycle['business']['phone'] = '+31 40 2222222'; $upgrade_expected_lifecycle['revision']++;
			$assert( 200 === $upgrade_saved->get_status() && nova_obt_canonical_json( $upgrade_expected_raw ) === nova_obt_canonical_json( get_option( Shared::OPTION_PRESETS ) ) && nova_obt_canonical_json( $upgrade_raw_template ) === nova_obt_canonical_json( get_option( Shared::OPTION_TEMPLATE ) ) && nova_obt_canonical_json( $upgrade_expected_lifecycle ) === nova_obt_canonical_json( get_option( Onboarding::OPTION_STATE ) ) && ! Onboarding::active_settings(), 'Explicit upgrade wizard saving changes only edited team and identity fields while keeping its original raw storage, twenty-two variants and existing layout' );
			$upgrade_latest = $upgrade_saved->get_data(); $upgrade_changed = $upgrade_expected_raw; $upgrade_changed['sections'][0]['data']['title'] = 'Another administrator changed defaults'; update_option( Shared::OPTION_PRESETS, $upgrade_changed, false );
			$upgrade_stale = $request( 'POST', '/nova-service/v1/onboarding', [ 'action' => 'save_business', 'expected_revision' => $upgrade_latest['revision'], 'expected_fingerprint' => $upgrade_latest['fingerprint'], 'draft' => [ 'business' => [ 'name' => 'Do not overwrite' ] ] ] );
			$assert( 409 === $upgrade_stale->get_status() && nova_obt_canonical_json( $upgrade_changed ) === nova_obt_canonical_json( get_option( Shared::OPTION_PRESETS ) ) && nova_obt_canonical_json( $upgrade_expected_lifecycle ) === nova_obt_canonical_json( get_option( Onboarding::OPTION_STATE ) ), 'Raw upgrade defaults changed elsewhere reject a stale identity fingerprint even with the current revision' );
			$assert( $before_legacy === hash( 'sha256', serialize( [ $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d", $draft_id ), ARRAY_A ), $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id", $draft_id ), ARRAY_A ) ] ) ), 'Upgrade settings editing leaves legacy post rows and all metadata intact' );
			$legacy_defaults_html = $render_settings( 'defaults' ); $_GET['tab'] = 'legacy';
			$assert( str_contains( $legacy_defaults_html, 'tab=legacy' ) && 'legacy' === $settings_mode->invoke( null ), 'A legacy settings tab is visible and selectable only while legacy pages exist' );
		}
	}
	$wpdb->update( $wpdb->posts, [ 'post_status' => 'auto-draft' ], [ 'ID' => $draft_id ], [ '%s' ], [ '%d' ] ); $GLOBALS['wp_object_cache']->flush();
	$assert( ! SharedAdmin::has_legacy_pages(), 'An unsaved auto-draft alone does not expose legacy settings' );
	$wpdb->delete( $wpdb->posts, [ 'ID' => $draft_id ], [ '%d' ] ); $wpdb->delete( $wpdb->postmeta, [ 'post_id' => $draft_id ], [ '%d' ] );
	$reset_setup(); $GLOBALS['wp_object_cache']->flush();
	update_option( 'service_cpt_color_accent', '#345678', false ); Onboarding::initialize();
	$customized_upgrade = Onboarding::state();
	$assert( 'upgrade' === $customized_upgrade['classification'] && 'existing_configuration' === $customized_upgrade['reason'] && false === $customized_upgrade['required'], 'Customized existing settings bypass new-install setup even without a service post' );

	// Provider-shaped WPML filters exercise native language selection without
	// loading a multilingual plugin or mutating any real translations/config.
	$english_page_id = 0;
	add_filter( 'wpml_object_id', static function ( $id ) { return $id; }, 10, 4 );
	add_filter( 'wpml_default_language', static function () { return 'nl'; } );
	add_filter( 'wpml_current_language', static function () { return 'nl'; } );
	add_filter( 'wpml_active_languages', static function () { return [ 'nl' => [ 'language_code' => 'nl', 'native_name' => 'Nederlands' ], 'en' => [ 'language_code' => 'en', 'native_name' => 'English' ], 'fr' => [ 'language_code' => 'fr', 'native_name' => 'Français' ] ]; }, 10, 2 );
	add_filter( 'wpml_post_language_details', static function ( $details, $id ) use ( &$english_page_id ) { return [ 'language_code' => $english_page_id && (int) $id === $english_page_id ? 'en' : 'nl' ]; }, 10, 2 );
	$_GET = [];
	$assert( true === Languages::context()['active'] && 'nl' === Languages::default_language() && 3 === count( Languages::context()['languages'] ), 'Native settings recognize enabled provider languages without requiring a change to the posting system' );
	$assert( true === Shared::save_settings( $upgrade_presets, $upgrade_template, 'nl' ), 'Default-language shared setup uses the existing global library and layout storage' );
	$language_base = get_option( Shared::OPTION_PRESETS ); $language_template = get_option( Shared::OPTION_TEMPLATE ); $language_lifecycle = get_option( Onboarding::OPTION_STATE );
	$english_presets = Shared::get_presets( 0, 'en' );
	foreach ( $english_presets['sections'] as &$preset ) {
		if ( 'default_reviews' === $preset['id'] ) { $preset['data']['title'] = 'Customers about us'; $preset['data']['items'] = [ [ 'author' => 'Example customer', 'body' => 'English language testimonial.', 'source' => 'Google' ] ]; }
	} unset( $preset );
	$assert( true === Shared::save_settings( $english_presets, Shared::get_template(), 'en' ) && nova_obt_canonical_json( $language_base ) === nova_obt_canonical_json( get_option( Shared::OPTION_PRESETS ) ) && nova_obt_canonical_json( $language_template ) === nova_obt_canonical_json( get_option( Shared::OPTION_TEMPLATE ) ) && nova_obt_canonical_json( $language_lifecycle ) === nova_obt_canonical_json( get_option( Onboarding::OPTION_STATE ) ) && ! Onboarding::active_settings(), 'Translated shared content saves separately and cannot replace base proof, global layout or upgrade identity' );
	$english_state = Onboarding::state( 'en' ); $french_state = Onboarding::state( 'fr' );
	$english_by_id = array_column( $english_state['draft']['presets']['sections'], null, 'id' ); $french_by_id = array_column( $french_state['draft']['presets']['sections'], null, 'id' );
	$assert( 'en' === $english_state['language'] && 'Customers about us' === $english_by_id['default_reviews']['data']['title'] && 'Real shared reviews' === $french_by_id['default_reviews']['data']['title'], 'Setup reads translated proof for the selected language and falls back to default content where no translation exists' );
	$translations_before_business = get_option( 'nova_service_shared_translations' );
	$english_business = $request( 'POST', '/nova-service/v1/onboarding', [ 'action' => 'save_business', 'language' => 'en', 'expected_revision' => $english_state['revision'], 'expected_fingerprint' => $english_state['fingerprint'], 'draft' => [ 'business' => [ 'name' => 'One multilingual business' ] ] ] );
	$assert( 200 === $english_business->get_status() && 'en' === $english_business->get_data()['language'] && 'One multilingual business' === Onboarding::state( 'nl' )['draft']['business']['name'] && nova_obt_canonical_json( $translations_before_business ) === nova_obt_canonical_json( get_option( 'nova_service_shared_translations' ) ) && nova_obt_canonical_json( $language_base ) === nova_obt_canonical_json( get_option( Shared::OPTION_PRESETS ) ), 'Localized business settings keep one global identity and preserve base and translated shared content exactly' );
	$english_wizard_state = Onboarding::state( 'en' ); $english_wizard_lifecycle = get_option( Onboarding::OPTION_STATE ); $english_wizard_before = get_option( Shared::OPTION_TRANSLATIONS );
	$english_noop = $setup_settings( $english_wizard_state['draft'], 'en' );
	$assert( 200 === $english_noop->get_status() && nova_obt_canonical_json( $english_wizard_before ) === nova_obt_canonical_json( get_option( Shared::OPTION_TRANSLATIONS ) ) && nova_obt_canonical_json( $english_wizard_lifecycle ) === nova_obt_canonical_json( get_option( Onboarding::OPTION_STATE ) ), 'Reopened multilingual wizard no-op saves preserve sparse language fallback and revision' );
	$rollback_edit = $english_wizard_state['draft']; foreach ( $rollback_edit['presets']['sections'] as &$preset ) { if ( 'default_people' === $preset['id'] ) { $preset['data']['body'] = '<p>This failed save must be rolled back.</p>'; } } unset( $preset ); $rollback_edit['business']['phone'] = '+31 40 4444444';
	$reject_lifecycle_save = static function ( $value, $old_value ) { return $old_value; };
	add_filter( 'pre_update_option_' . Onboarding::OPTION_STATE, $reject_lifecycle_save, 10, 2 );
	try { $rollback_result = $setup_settings( $rollback_edit, 'en' ); } finally { remove_filter( 'pre_update_option_' . Onboarding::OPTION_STATE, $reject_lifecycle_save, 10 ); }
	$assert( 500 === $rollback_result->get_status() && nova_obt_canonical_json( $english_wizard_before ) === nova_obt_canonical_json( get_option( Shared::OPTION_TRANSLATIONS ) ) && nova_obt_canonical_json( $english_wizard_lifecycle ) === nova_obt_canonical_json( get_option( Onboarding::OPTION_STATE ) ) && nova_obt_canonical_json( $language_base ) === nova_obt_canonical_json( get_option( Shared::OPTION_PRESETS ) ), 'If the identity/revision option write fails, the earlier translated-content patch is rolled back and all original temporary settings are restored' );
	$english_wizard_edit = $english_wizard_state['draft']; foreach ( $english_wizard_edit['presets']['sections'] as &$preset ) { if ( 'default_people' === $preset['id'] ) { $preset['data']['body'] = '<p>Meet the actual English-speaking team.</p>'; } } unset( $preset ); $english_wizard_edit['business']['phone'] = '+31 40 3333333';
	$english_wizard_saved = $setup_settings( $english_wizard_edit, 'en' ); $english_wizard_expected = $english_wizard_before;
	$english_wizard_expected['en']['sections'][] = [ 'id' => 'default_people', 'type' => 'people', 'enabled' => true, 'data' => [ 'body' => '<p>Meet the actual English-speaking team.</p>' ] ];
	$english_wizard_lifecycle['business']['phone'] = '+31 40 3333333'; $english_wizard_lifecycle['revision']++;
	$assert( 200 === $english_wizard_saved->get_status() && 'en' === $english_wizard_saved->get_data()['language'] && nova_obt_canonical_json( $english_wizard_expected ) === nova_obt_canonical_json( get_option( Shared::OPTION_TRANSLATIONS ) ) && nova_obt_canonical_json( $english_wizard_lifecycle ) === nova_obt_canonical_json( get_option( Onboarding::OPTION_STATE ) ) && nova_obt_canonical_json( $language_base ) === nova_obt_canonical_json( get_option( Shared::OPTION_PRESETS ) ) && nova_obt_canonical_json( $language_template ) === nova_obt_canonical_json( get_option( Shared::OPTION_TEMPLATE ) ), 'One translated wizard save updates just its selected content overlay plus global business while preserving base proof, presentation and page layout' );
	$english_global_bad = Onboarding::state( 'en' )['draft']; foreach ( $english_global_bad['presets']['sections'] as &$preset ) { if ( 'default_reviews' === $preset['id'] ) { $preset['data']['layout'] = 'compact_rail'; } } unset( $preset ); $english_global_bad['business']['name'] = 'Never save partial identity';
	$english_style_rejected = $setup_settings( $english_global_bad, 'en' );
	$assert( 400 === $english_style_rejected->get_status() && nova_obt_canonical_json( $english_wizard_expected ) === nova_obt_canonical_json( get_option( Shared::OPTION_TRANSLATIONS ) ) && nova_obt_canonical_json( $english_wizard_lifecycle ) === nova_obt_canonical_json( get_option( Onboarding::OPTION_STATE ) ) && nova_obt_canonical_json( $language_base ) === nova_obt_canonical_json( get_option( Shared::OPTION_PRESETS ) ), 'A translated wizard cannot overwrite global visual style or partially save a mixed business edit' );
	$english_page_id = wp_insert_post( [ 'post_type' => 'service_page', 'post_status' => 'draft', 'post_title' => 'English isolated service', 'post_author' => $admin_id ], true );
	$assert( ! is_wp_error( $english_page_id ) && Document::is_v2( $english_page_id ) && 'en' === Languages::current( $english_page_id ), 'A native target page uses its assigned language even when the current admin language is different' );
	$resolved_english = array_column( Document::resolve_document( $english_page_id )['sections'], null, 'id' );
	$language_preview = SEORAI\ServicePageCPT\ServiceLivePreview::candidate( 'page', $english_page_id, Document::get_document( $english_page_id ) );
	$preview_english = array_column( $language_preview['document']['sections'], null, 'id' );
	$language_context = Shared::context( $english_page_id );
	$context_presets = array_column( $language_context['presets'], null, 'key' );
	$assert( 'Customers about us' === $resolved_english['proof']['data']['title'] && 'Customers about us' === $preview_english['proof']['data']['title'] && 'en' === $language_context['languages']['current'] && 'Customers about us' === $context_presets['default_reviews']['title'], 'Public document resolution, live editor preview and normal REST context use the same target-language shared defaults' );
	$assert( str_contains( Renderer::render( $english_page_id ), 'English language testimonial.' ), 'The rendered native service uses its translated testimonial content' );
	$blocked_language = $request( 'POST', '/nova-service/v1/onboarding', [ 'action' => 'save_business', 'language' => 'unknown', 'expected_revision' => Onboarding::state( 'en' )['revision'], 'expected_fingerprint' => Onboarding::state( 'en' )['fingerprint'], 'draft' => [ 'business' => [ 'name' => 'Invalid scope' ] ] ] );
	$assert( 400 === $blocked_language->get_status() && 'One multilingual business' === Onboarding::state( 'nl' )['draft']['business']['name'], 'An unavailable language cannot silently save into default-language business settings' );
	$assert( $original_snapshot === $snapshot(), 'All isolated REST publication, progress, activation and upgrade checks leave original client rows unchanged' );
} catch ( Throwable $error ) {
	$failure = get_class( $error ) . ': ' . $error->getMessage();
} finally {
	// The bootstrap query guard remains installed after restoring the hooks.
	// Thus even a shutdown callback cannot write a client table in this process.
	$GLOBALS['wp_filter'] = $old_hooks;
	foreach ( $old_db as $key => $value ) { $wpdb->$key = $value; }
	foreach ( $saved_globals as $key => $saved ) { if ( $saved['exists'] ) { $GLOBALS[ $key ] = $saved['value']; } else { unset( $GLOBALS[ $key ] ); } }
	$restored = true;
	foreach ( $old_db as $key => $value ) { $restored = $restored && $value === $wpdb->$key; }
	$restored = $restored && $saved_globals['wp_object_cache']['value'] === $GLOBALS['wp_object_cache'];
	// Core registers _wp_cron during ordinary bootstrap, before the temporary
	// tables exist. Do not run that callback after restoring real table names:
	// it would try to take a real doing_cron transient lock. Suppress only these
	// request-local callbacks; the original cron option and event array remain
	// untouched and the SQL guard continues protecting every real-table write.
	foreach ( [ 'shutdown', 'wp_loaded' ] as $cron_hook ) {
		while ( false !== ( $cron_priority = has_action( $cron_hook, '_wp_cron' ) ) ) { remove_action( $cron_hook, '_wp_cron', $cron_priority ); }
	}
	$cron_suppressed = false === has_action( 'shutdown', '_wp_cron' ) && false === has_action( 'wp_loaded', '_wp_cron' );
	try {
		$assert( $restored && has_filter( 'query', 'nova_obt_guard_query' ) && has_filter( 'query', 'nova_obt_guard_query_last' ), 'All original table properties, global context and cache identity are restored with the SQL guards still active' );
		$assert( $cron_suppressed, 'Core cron callbacks are suppressed only for this isolated process without changing cron options or scheduled events' );
		$assert( $original_snapshot === $snapshot(), 'The final read-only original-table snapshot matches the pre-test client state' );
	} catch ( Throwable $error ) { $failure = $failure ?: get_class( $error ) . ': ' . $error->getMessage(); }
}

echo wp_json_encode( [ 'ok' => null === $failure, 'checks' => count( $checks ), 'passed' => $checks, 'failure' => $failure, 'original_state_unchanged' => $original_snapshot === $snapshot(), 'restored' => $restored, 'temporary_tables' => count( $logical_tables ), 'sql' => $GLOBALS['nova_obt_sql_counts'], 'network_and_mail' => 'blocked', 'core_cron_callbacks_suppressed' => $cron_suppressed, 'cron_storage' => 'never modified', 'activation_or_rewrite_flush' => 'never invoked' ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
// Do not DROP even our temporary tables: connection close is their cleanup.
if ( null !== $failure ) { WP_CLI::halt( 1 ); }
