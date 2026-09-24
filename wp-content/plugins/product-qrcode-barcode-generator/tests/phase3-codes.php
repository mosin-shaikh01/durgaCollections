<?php
/**
 * Phase 3 suite (secure product code generation): RECONSTRUCTION.
 *
 * The original Phase 3 script was deleted after Phase 3. This suite was
 * rebuilt in Phase 4 from the checklist recorded in progress.md and covers
 * every category listed there. Its check count is not the original 92.
 *
 * Creates WooCommerce products (simple, variable with variations, grouped,
 * external, draft, an orphaned variation), a page and two users, and removes
 * all of them afterwards, including code rows, lookup rows and the Action
 * Scheduler jobs they trigger. Log output is captured in memory.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';
pqbg_test_load_wp();
require_once ABSPATH . 'wp-admin/includes/user.php';

use ProductQrBarcode\{CodeGenerator, CodeRepository, ProductCodeService, Schema};

global $wpdb;

$C         = Schema::codes_table();
$alphabet  = CodeGenerator::ALPHABET;
$as_table  = $wpdb->prefix . 'actionscheduler_actions';
$start_id  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $C" );
$start_as  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(action_id), 0) FROM $as_table" );
$base_c    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
$base_prod = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" );
$base_user = (int) count_users()['total_users'];
$product_ids = array();
$user_ids    = array();

// In-memory logger: WooCommerce re-reads this filter on every wc_get_logger() call.
$logger = new class() extends WC_Logger {
	/** @var array<int, array{0: string, 1: string}> */
	public array $entries = array();

	public function log( $level, $message, $context = array() ) {
		$this->entries[] = array( (string) $level, (string) $message );
	}
};
add_filter( 'woocommerce_logging_class', static fn() => $logger );

/**
 * RNG that spells out the given codes (as alphabet indexes), then falls back to random_int().
 *
 * @param string[] $codes Codes to produce first, in order.
 * @param int      $calls Incremented on every call.
 */
$rng_for = static function ( array $codes, &$calls ) use ( $alphabet ) {
	$queue = array();
	foreach ( $codes as $code ) {
		foreach ( str_split( str_replace( '-', '', substr( $code, 3 ) ) ) as $ch ) {
			$queue[] = strpos( $alphabet, $ch );
		}
	}
	return static function ( $min, $max ) use ( &$queue, &$calls ) {
		++$calls;
		return array() !== $queue ? array_shift( $queue ) : random_int( $min, $max );
	};
};

$fresh_code = static fn() => ( new CodeGenerator() )->generate();

try {
	pqbg_section( 'alphabet and format' );
	pqbg_t( 'alphabet constant', 'ABCDEFGHJKMNPQRSTUVWXYZ23456789' === $alphabet && 31 === strlen( $alphabet ) && 31 === count( array_unique( str_split( $alphabet ) ) ) );
	pqbg_t( 'format pattern constant', '/^DC(-[A-HJKMNP-Z2-9]{4}){3}$/D' === CodeGenerator::FORMAT_PATTERN );
	pqbg_t( 'positive control: a valid code is accepted', CodeGenerator::is_valid_format( 'DC-7K4M-9P2X-Q8RT' ) );
	$base = 'DC-AAAA-AAAA-AAAA';
	for ( $g = 0; $g < 3; $g++ ) {
		$exact = true;
		for ( $pos = 0; $pos < 4; $pos++ ) {
			for ( $b = 0; $b < 256; $b++ ) {
				$candidate = $base;
				$candidate[ 3 + $g * 5 + $pos ] = chr( $b );
				$exact = $exact && ( CodeGenerator::is_valid_format( $candidate ) === ( false !== strpos( $alphabet, chr( $b ) ) ) );
			}
		}
		pqbg_t( 'group ' . ( $g + 1 ) . ': pattern accepts exactly the alphabet (all 256 byte values, every position)', $exact );
	}
	$malformed = array(
		'empty'                 => '',
		'prefix only'           => 'DC-',
		'lowercase'             => 'dc-7k4m-9p2x-q8rt',
		'mixed case'            => 'DC-7k4M-9P2X-Q8RT',
		'trailing newline'      => "DC-7K4M-9P2X-Q8RT\n",
		'trailing NUL'          => "DC-7K4M-9P2X-Q8RT\0",
		'leading space'         => ' DC-7K4M-9P2X-Q8RT',
		'too short'             => 'DC-7K4M-9P2X-Q8R',
		'too long'              => 'DC-7K4M-9P2X-Q8RTX',
		'two groups'            => 'DC-7K4M-9P2X',
		'four groups'           => 'DC-7K4M-9P2X-Q8RT-ABCD',
		'no hyphens'            => 'DC7K4M9P2XQ8RT',
		'wrong prefix'          => 'XC-7K4M-9P2X-Q8RT',
		'underscores'           => 'DC_7K4M_9P2X_Q8RT',
		'contains 0'            => 'DC-7K4M-9P2X-Q8R0',
		'contains 1'            => 'DC-7K4M-9P2X-Q8R1',
		'contains I'            => 'DC-7K4M-9P2X-Q8RI',
		'contains L'            => 'DC-7K4M-9P2X-Q8RL',
		'contains O'            => 'DC-7K4M-9P2X-Q8RO',
		'Greek Tau lookalike'   => "DC-7K4M-9P2X-Q8R\u{03A4}",
		'fullwidth characters'  => "DC-7K4M-9P2X-Q8R\u{FF34}",
	);
	foreach ( $malformed as $label => $bad ) {
		pqbg_t( "malformed rejected: {$label}", ! CodeGenerator::is_valid_format( $bad ) );
	}

	pqbg_section( 'generated codes (20,000 samples)' );
	$gen     = new CodeGenerator();
	$samples = array();
	for ( $i = 0; $i < 20000; $i++ ) {
		$samples[] = $gen->generate();
	}
	pqbg_t( 'all well-formed', count( $samples ) === count( array_filter( $samples, array( CodeGenerator::class, 'is_valid_format' ) ) ) );
	pqbg_t( 'no excluded characters', ! preg_grep( '/[01ILOa-z]/', array_map( fn( $c ) => substr( $c, 3 ), $samples ) ) );
	pqbg_t( 'all distinct', 20000 === count( array_unique( $samples ) ) );
	$positions = array_fill( 0, 12, array() );
	foreach ( $samples as $s ) {
		foreach ( str_split( str_replace( '-', '', substr( $s, 3 ) ) ) as $p => $ch ) {
			$positions[ $p ][ $ch ] = true;
		}
	}
	pqbg_t( 'every symbol appears in every position', 12 * 31 === array_sum( array_map( 'count', $positions ) ) );

	pqbg_section( 'randomness source' );
	$prop = new ReflectionProperty( CodeGenerator::class, 'random_int' );
	pqbg_t( 'random_int is the default source', 'random_int' === $prop->getValue( new CodeGenerator() ) );
	pqbg_t( 'generate() takes no input', 0 === ( new ReflectionMethod( CodeGenerator::class, 'generate' ) )->getNumberOfParameters() );
	$calls_in = static function ( string $file ): array {
		$names = array();
		foreach ( token_get_all( (string) file_get_contents( $file ) ) as $tok ) {
			if ( is_array( $tok ) && T_STRING === $tok[0] ) {
				$names[ strtolower( $tok[1] ) ] = true;
			}
		}
		return $names;
	};
	$gen_names = $calls_in( PQBG_PLUGIN_DIR . 'includes/CodeGenerator.php' );
	$banned    = array( 'rand', 'mt_rand', 'uniqid', 'microtime', 'time', 'hrtime', 'date', 'md5', 'sha1', 'crc32', 'hash', 'get_sku', 'get_name', 'get_the_title', 'lcg_value' );
	pqbg_t( 'token scan: no predictable sources in CodeGenerator', array() === array_intersect( $banned, array_keys( $gen_names ) ), implode( ',', array_intersect( $banned, array_keys( $gen_names ) ) ) );
	pqbg_t( 'token scan is live (finds preg_match)', isset( $gen_names['preg_match'] ) );

	pqbg_section( 'generator collisions' );
	$rng_calls = 0;
	$first     = 'DC-AAAA-BBBB-CCCC';
	$checks    = 0;
	$g         = new CodeGenerator( $rng_for( array( $first ), $rng_calls ), function ( $code ) use ( $first, &$checks ) { ++$checks; return $code === $first; } );
	$result    = $g->generate_unique();
	pqbg_t( 'a collision leads to the next candidate', is_string( $result ) && $result !== $first && CodeGenerator::is_valid_format( $result ) && 2 === $checks );
	$rng_calls = 0;
	$checks    = 0;
	$g         = new CodeGenerator( $rng_for( array(), $rng_calls ), function () use ( &$checks ) { ++$checks; return true; } );
	$result    = $g->generate_unique();
	pqbg_t( 'exhaustion: controlled error after exactly 10 checks and 120 RNG calls', is_wp_error( $result ) && 'pqbg_code_generation_failed' === $result->get_error_code() && 10 === $checks && 120 === $rng_calls, "$checks checks, $rng_calls calls" );
	$result = ( new CodeGenerator( function () { throw new RuntimeException( 'secret-detail' ); }, fn() => false ) )->generate_unique();
	pqbg_t( 'RNG exception: controlled error without details', is_wp_error( $result ) && 'pqbg_random_unavailable' === $result->get_error_code() && ! str_contains( $result->get_error_message(), 'secret-detail' ) );
	$result = ( new CodeGenerator( fn() => 31, fn() => false ) )->generate_unique();
	pqbg_t( 'RNG out-of-range value: controlled error', is_wp_error( $result ) && 'pqbg_random_unavailable' === $result->get_error_code() );
	$result = ( new CodeGenerator( fn() => '3', fn() => false ) )->generate_unique();
	pqbg_t( 'RNG non-integer value: controlled error', is_wp_error( $result ) && 'pqbg_random_unavailable' === $result->get_error_code() );

	pqbg_section( 'fixtures' );
	$sm_id     = wp_insert_user( array( 'user_login' => 'pqbg_p3_shop_manager', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'pqbg-p3-sm@example.invalid', 'role' => 'shop_manager' ) );
	$seller_id = wp_insert_user( array( 'user_login' => 'pqbg_p3_seller', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'pqbg-p3-seller@example.invalid', 'role' => 'pqbg_seller' ) );
	$user_ids  = array( $sm_id, $seller_id );
	$mk        = static function ( WC_Product $p, string $name, string $status = 'publish' ) use ( &$product_ids ): int {
		$p->set_name( 'PQBG P3 TEST ' . $name );
		$p->set_status( $status );
		$id            = $p->save();
		$product_ids[] = $id;
		return $id;
	};
	$simple    = $mk( new WC_Product_Simple(), 'simple' );
	$simple_b  = $mk( new WC_Product_Simple(), 'simple B' );
	$simple_c  = $mk( new WC_Product_Simple(), 'simple C' );
	$simple_d  = $mk( new WC_Product_Simple(), 'simple D' );
	$draft     = $mk( new WC_Product_Simple(), 'draft', 'draft' );
	$variable  = new WC_Product_Variable();
	$attr      = new WC_Product_Attribute();
	$attr->set_name( 'size' );
	$attr->set_options( array( 'S', 'M' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$variable->set_attributes( array( $attr ) );
	$variable  = $mk( $variable, 'variable' );
	$vars      = array();
	foreach ( array( 'S', 'M' ) as $size ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $variable );
		$v->set_attributes( array( 'size' => $size ) );
		$vars[] = $mk( $v, 'variation ' . $size );
	}
	$grouped = new WC_Product_Grouped();
	$grouped->set_children( array( $simple ) );
	$grouped  = $mk( $grouped, 'grouped' );
	$external = new WC_Product_External();
	$external->set_product_url( 'https://example.invalid/' );
	$external = $mk( $external, 'external' );
	$orphan   = new WC_Product_Variation();
	$orphan->set_parent_id( $simple_b );
	$orphan  = $mk( $orphan, 'orphaned variation' );
	$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'PQBG P3 TEST page', 'post_status' => 'publish' ) );
	pqbg_t( 'fixtures created', $sm_id > 0 && $seller_id > 0 && $page_id > 0 && 2 === count( $vars ) && 11 === count( $product_ids ) );

	pqbg_section( 'eligibility' );
	$el = ProductCodeService::eligibility( $simple );
	pqbg_t( 'simple product eligible (parent_id 0)', is_array( $el ) && $simple === $el['product_id'] && 0 === $el['parent_id'] );
	$el = ProductCodeService::eligibility( $vars[0] );
	pqbg_t( 'variation eligible (parent_id = variable parent)', is_array( $el ) && $vars[0] === $el['product_id'] && $variable === $el['parent_id'] );
	foreach ( array( 'variable parent' => $variable, 'grouped' => $grouped, 'external' => $external, 'orphaned variation (parent not variable)' => $orphan ) as $label => $id ) {
		$el = ProductCodeService::eligibility( $id );
		pqbg_t( "not eligible: {$label}", is_wp_error( $el ) && 'pqbg_ineligible_product' === $el->get_error_code() );
	}
	foreach ( array( '0' => 0, 'negative' => -5, 'nonexistent' => 999999999, 'PHP_INT_MAX' => PHP_INT_MAX, 'page' => $page_id ) as $label => $id ) {
		$el = ProductCodeService::eligibility( $id );
		pqbg_t( "rejected as invalid product: {$label}", is_wp_error( $el ) && 'pqbg_invalid_product' === $el->get_error_code() );
	}
	$GLOBALS['post'] = get_post( $simple );
	$el              = ProductCodeService::eligibility( 0 );
	unset( $GLOBALS['post'] );
	pqbg_t( 'ID 0 does not fall back to the global $post', is_wp_error( $el ) );
	pqbg_t( 'a draft product is eligible', ProductCodeService::is_eligible( $draft ) );

	pqbg_section( 'authorization' );
	$svc     = new ProductCodeService();
	$count_p = static fn( int $pid ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $C WHERE product_id = %d", $pid ) );
	foreach ( array( 'user 0' => 0, 'seller' => $seller_id, 'nonexistent user' => 999999 ) as $label => $uid ) {
		$r = $svc->get_or_create( $simple, $uid );
		pqbg_t( "forbidden: {$label}", is_wp_error( $r ) && 'pqbg_forbidden' === $r->get_error_code() );
	}
	pqbg_t( 'no rows written by forbidden calls', 0 === $count_p( $simple ) );
	wp_set_current_user( 1 );
	$r = $svc->get_or_create( $simple, $seller_id );
	pqbg_t( 'the acting user is checked, not the current user (admin current, seller acting)', is_wp_error( $r ) && 'pqbg_forbidden' === $r->get_error_code() );
	wp_set_current_user( 0 );

	pqbg_section( 'assignment' );
	$row = $svc->get_or_create( $simple, $sm_id );
	pqbg_t( 'shop manager allowed while logged out (acting user checked)', is_array( $row ) );
	pqbg_t(
		'simple product row fields',
		is_array( $row ) && CodeGenerator::is_valid_format( $row['code'] ) && $simple === (int) $row['product_id'] && 0 === (int) $row['parent_id']
		&& $simple === (int) $row['active_product_id'] && 'active' === $row['status'] && 'product' === $row['kind'] && $sm_id === (int) $row['created_by']
	);
	pqbg_t( 'row readable through CodeRepository', is_array( $row ) && CodeRepository::find_by_code( $row['code'] ) == $row && CodeRepository::find_active_for_product( $simple ) == $row );
	$v0 = $svc->get_or_create( $vars[0], $sm_id );
	$v1 = $svc->get_or_create( $vars[1], $sm_id );
	pqbg_t( 'each variation gets its own code with parent_id set', is_array( $v0 ) && is_array( $v1 ) && $v0['code'] !== $v1['code'] && $variable === (int) $v0['parent_id'] && $variable === (int) $v1['parent_id'] );
	$none = true;
	foreach ( array( $variable, $grouped, $external, $orphan, $page_id ) as $id ) {
		$none = $none && is_wp_error( $svc->get_or_create( $id, $sm_id ) ) && 0 === $count_p( $id );
	}
	pqbg_t( 'variable, grouped, external, orphaned variation and page get no code', $none );

	pqbg_section( 'one active code' );
	$again = $svc->get_or_create( $simple, $sm_id );
	pqbg_t( 'repeat request returns the same row', is_array( $again ) && $again['id'] === $row['id'] && $again['code'] === $row['code'] );
	pqbg_t( 'still 1 row and 1 active for the item', 1 === $count_p( $simple ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $C WHERE active_product_id = %d", $simple ) ) );
	$rng_calls = 0;
	( new ProductCodeService( new CodeGenerator( $rng_for( array(), $rng_calls ) ) ) )->get_or_create( $simple, $sm_id );
	pqbg_t( 'the RNG is never called when a code already exists', 0 === $rng_calls );

	pqbg_section( 'retired codes' );
	$old_code = $row['code'];
	pqbg_t( 'retire the active code', true === CodeRepository::retire( (int) $row['id'], $sm_id ) );
	$new = $svc->get_or_create( $simple, $sm_id );
	pqbg_t( 'retiring leads to a new code', is_array( $new ) && $new['code'] !== $old_code && 'active' === $new['status'] );
	$old = CodeRepository::find_by_code( $old_code );
	pqbg_t( 'old row preserved and not reactivated', is_array( $old ) && 'retired' === $old['status'] && null === $old['active_product_id'] && $old['id'] === $row['id'] );
	$rng_calls = 0;
	$skip      = ( new CodeGenerator( $rng_for( array( $old_code ), $rng_calls ) ) )->generate_unique();
	pqbg_t( 'the generator skips the retired code', is_string( $skip ) && $skip !== $old_code && 24 === $rng_calls );
	$r = CodeRepository::create_active( $old_code, 999999931, 0, $sm_id );
	pqbg_t( 'repository rejects the retired code for another item', is_wp_error( $r ) && 'pqbg_code_conflict' === $r->get_error_code() );
	$wpdb->suppress_errors( true );
	$raw = $wpdb->insert( $C, array( 'code' => $old_code, 'product_id' => $simple, 'status' => 'retired', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
	$wpdb->suppress_errors( false );
	pqbg_t( 'database rejects the retired code for its own item', false === $raw );

	pqbg_section( 'code_exists' );
	pqbg_t( 'active code exists', CodeRepository::code_exists( $new['code'] ) );
	pqbg_t( 'retired code exists', CodeRepository::code_exists( $old_code ) );
	pqbg_t( 'unknown well-formed code does not exist', ! CodeRepository::code_exists( $fresh_code() ) );
	pqbg_t( 'malformed input is reported as taken', CodeRepository::code_exists( "bad code'" ) && CodeRepository::code_exists( '' ) );

	pqbg_section( 'service collisions' );
	$snap      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $C WHERE code = %s", $new['code'] ), ARRAY_A );
	$rng_calls = 0;
	$b         = ( new ProductCodeService( new CodeGenerator( $rng_for( array( $new['code'] ), $rng_calls ) ) ) )->get_or_create( $simple_b, $sm_id );
	pqbg_t( 'pre-insert collision leads to a new code', is_array( $b ) && $b['code'] !== $new['code'] && 24 === $rng_calls );
	pqbg_t( 'the existing row is untouched', $snap == $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $C WHERE code = %s", $new['code'] ), ARRAY_A ) );

	pqbg_section( 'database race' );
	$rng_calls = 0;
	$retry_id  = $vars[0];
	CodeRepository::retire( (int) $v0['id'], $sm_id );
	$blind = ( new ProductCodeService( new CodeGenerator( $rng_for( array( $new['code'] ), $rng_calls ), fn() => false ) ) )->get_or_create( $retry_id, $sm_id );
	pqbg_t( 'pre-check blinded: UNIQUE(code) catches the duplicate and the service retries', is_array( $blind ) && $blind['code'] !== $new['code'] && 24 === $rng_calls );
	pqbg_t( 'the other row is untouched', $snap == $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $C WHERE code = %s", $new['code'] ), ARRAY_A ) );
	$rng_calls       = 0;
	$logger->entries = array();
	$forever         = array_fill( 0, 10, $new['code'] );
	$persist         = ( new ProductCodeService( new CodeGenerator( $rng_for( $forever, $rng_calls ), fn() => false ) ) )->get_or_create( $simple_c, $sm_id );
	pqbg_t( 'persistent conflict fails after exactly 3 saves (36 RNG calls)', is_wp_error( $persist ) && 'pqbg_code_generation_failed' === $persist->get_error_code() && 36 === $rng_calls, "$rng_calls calls" );
	pqbg_t( 'no partial row', 0 === $count_p( $simple_c ) );
	pqbg_t( 'one log entry, without any code value', 1 === count( $logger->entries ) && ! str_contains( $logger->entries[0][1], $new['code'] ) && ! preg_match( '/DC-[A-Z0-9]{4}/', $logger->entries[0][1] ), json_encode( $logger->entries ) );
	$logger->entries = array();
	$exhaust         = ( new ProductCodeService( new CodeGenerator( null, fn() => true ) ) )->get_or_create( $simple_d, $sm_id );
	pqbg_t( 'generator exhaustion through the service writes no row', is_wp_error( $exhaust ) && 'pqbg_code_generation_failed' === $exhaust->get_error_code() && 0 === $count_p( $simple_d ) );
	$winner    = $fresh_code();
	$candidate = null;
	$racer     = function ( $code ) use ( $winner, $simple_d, $sm_id, &$candidate ) {
		if ( null === $candidate ) {
			$candidate = $code;
			CodeRepository::create_active( $winner, $simple_d, 0, $sm_id ); // The "other request" wins.
		}
		return false;
	};
	$won = ( new ProductCodeService( new CodeGenerator( null, $racer ) ) )->get_or_create( $simple_d, $sm_id );
	pqbg_t( 'concurrent winner: its code is returned', is_array( $won ) && $winner === $won['code'] );
	pqbg_t( 'concurrent winner: only one active row', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $C WHERE active_product_id = %d", $simple_d ) ) );
	pqbg_t( 'concurrent winner: the losing candidate is never stored', is_string( $candidate ) && ! CodeRepository::code_exists( $candidate ) );
	$wpdb->suppress_errors( true );
	$dup_raw   = $wpdb->insert( $C, array( 'code' => $winner, 'product_id' => 999999932, 'status' => 'retired', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
	$dup_lower = $wpdb->insert( $C, array( 'code' => strtolower( $winner ), 'product_id' => 999999932, 'status' => 'retired', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
	$wpdb->suppress_errors( false );
	pqbg_t( 'raw duplicate inserts blocked, including a lowercase variant', false === $dup_raw && false === $dup_lower );

	pqbg_section( 'batch' );
	$gen   = new CodeGenerator();
	$ok    = true;
	$batch = array();
	for ( $i = 1; $i <= 300; $i++ ) {
		$code    = $gen->generate_unique();
		$ok      = $ok && is_string( $code ) && is_int( CodeRepository::create_active( $code, 990000000 + $i, 0, $sm_id ) );
		$batch[] = $code;
	}
	pqbg_t( '300 generated codes persisted with no conflicts', $ok );
	pqbg_t( 'no duplicate codes in the table', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT code FROM $C GROUP BY code HAVING COUNT(*) > 1) d" ) );
	pqbg_t( 'no item has more than one active code', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT product_id FROM $C WHERE status = 'active' GROUP BY product_id HAVING COUNT(*) > 1) d" ) );
	pqbg_t(
		'the invariant holds on every row',
		0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C WHERE NOT ( (status = 'active' AND kind = 'product' AND active_product_id = product_id) OR (active_product_id IS NULL AND NOT (status = 'active' AND kind = 'product')) )" )
	);

	pqbg_section( 'scope' );
	pqbg_t( 'no pqbg post meta', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '%pqbg%'" ) );
	$opts = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%pqbg%' ORDER BY option_name" );
	// Phase 6 added pqbg_rewrite_version, the scan route's rewrite-rules flag. It holds no data.
	pqbg_t( 'only the pqbg options exist: db version, settings and the Phase 6 rewrite-rules flag', array( 'pqbg_db_version', 'pqbg_rewrite_version', 'pqbg_settings' ) === $opts, implode( ',', $opts ) );
	$forbidden = array( 'add_action', 'add_filter', 'register_rest_route', 'add_shortcode', 'wpdb', '_get', '_post', '_request', '_server', '_cookie', 'home_url', 'site_url', 'admin_url' );
	$scan      = static function ( string $file ) use ( $forbidden ): array {
		$found = array();
		foreach ( token_get_all( (string) file_get_contents( $file ) ) as $tok ) {
			if ( is_array( $tok ) && in_array( $tok[0], array( T_STRING, T_VARIABLE ), true ) && in_array( strtolower( ltrim( $tok[1], '$' ) ), $forbidden, true ) ) {
				$found[] = $tok[1];
			}
			if ( is_array( $tok ) && in_array( $tok[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) && preg_match( '/\b(SELECT|INSERT|UPDATE|DELETE)\b/', $tok[1] ) ) {
				$found[] = 'SQL';
			}
		}
		return $found;
	};
	$hits = array_merge( $scan( PQBG_PLUGIN_DIR . 'includes/CodeGenerator.php' ), $scan( PQBG_PLUGIN_DIR . 'includes/ProductCodeService.php' ) );
	pqbg_t( 'no hooks, endpoints, SQL, superglobals or URLs in CodeGenerator/ProductCodeService', array() === $hits, implode( ',', $hits ) );
	pqbg_t( 'the scan is live (finds $wpdb and SQL in CodeRepository)', in_array( '$wpdb', $scan( PQBG_PLUGIN_DIR . 'includes/CodeRepository.php' ), true ) && in_array( 'SQL', $scan( PQBG_PLUGIN_DIR . 'includes/CodeRepository.php' ), true ) );
	do_action( 'rest_api_init' );
	$hit = fn( $s ) => str_contains( $s, 'pqbg' ) || str_contains( $s, 'qrcode-barcode' ) || str_contains( $s, 'scan' );
	pqbg_t( 'no pqbg or /scan/ REST routes', ! array_filter( rest_get_server()->get_namespaces(), $hit ) && ! array_filter( array_keys( rest_get_server()->get_routes() ), fn( $r ) => str_contains( $r, 'pqbg' ) || str_starts_with( $r, '/scan' ) ) );
	pqbg_t( 'no pqbg shortcodes', ! array_filter( array_keys( $GLOBALS['shortcode_tags'] ), $hit ) );
	pqbg_t( 'no pqbg AJAX actions', ! array_filter( array_keys( $GLOBALS['wp_filter'] ), fn( $h ) => str_starts_with( $h, 'wp_ajax' ) && $hit( $h ) ) );
	// Phase 6 added the scan route: exactly its two rules, and no other pqbg or scan rule.
	$scan_rules = array_keys( ProductQrBarcode\ScanUrl::rewrite_rules( ProductQrBarcode\ScanRoute::ROUTE_VAR, ProductQrBarcode\ScanRoute::CODE_VAR ) );
	pqbg_t( 'no pqbg or scan rewrite rules other than the two Phase 6 scan rules', array() === array_diff( array_filter( array_keys( (array) get_option( 'rewrite_rules' ) ), fn( $k ) => str_contains( $k, 'scan' ) || str_contains( $k, 'pqbg' ) ), $scan_rules ) );
	$ours = static function ( string $hook ): bool {
		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? array() as $cbs ) {
			foreach ( $cbs as $cb ) {
				$f = $cb['function'];
				$n = is_array( $f ) ? ( is_object( $f[0] ) ? get_class( $f[0] ) : (string) $f[0] ) : ( is_string( $f ) ? $f : ( $f instanceof Closure ? ( new ReflectionFunction( $f ) )->getFileName() : '' ) );
				if ( str_contains( (string) $n, 'ProductQrBarcode' ) || str_contains( str_replace( '\\', '/', (string) $n ), 'product-qrcode-barcode-generator' ) ) {
					return true;
				}
			}
		}
		return false;
	};
	// Phase 5 (approved) added CodeLifecycle on exactly the WooCommerce CRUD save hooks and deleted_post.
	$save_hooks = array( 'save_post', 'save_post_product', 'save_post_product_variation', 'wp_insert_post', 'transition_post_status', 'woocommerce_before_product_object_save', 'woocommerce_after_product_object_save', 'before_delete_post', 'wp_trash_post', 'untrashed_post' );
	pqbg_t( 'no plugin callbacks on other product save/create/trash hooks (Phase 5 uses only the approved ones)', ! array_filter( $save_hooks, $ours ) );
	$only_lifecycle = static function ( string $hook ): bool {
		$found = false;
		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? array() as $cbs ) {
			foreach ( $cbs as $cb ) {
				$f = $cb['function'];
				$n = is_array( $f ) ? ( is_object( $f[0] ) ? get_class( $f[0] ) : (string) $f[0] ) : '';
				if ( str_contains( $n, 'ProductQrBarcode' ) ) {
					if ( 'ProductQrBarcode\\CodeLifecycle' !== ltrim( $n, '\\' ) ) {
						return false;
					}
					$found = true;
				}
			}
		}
		return $found;
	};
	pqbg_t( 'approved Phase 5 hooks are served by CodeLifecycle only', 5 === count( array_filter( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation', 'deleted_post' ), $only_lifecycle ) ) );
} finally {
	pqbg_section( 'cleanup' );
	wp_set_current_user( 0 );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE id > %d", $start_id ) );
	$wpdb->query( "ALTER TABLE $C AUTO_INCREMENT = 1" );

	foreach ( array_reverse( $product_ids ) as $id ) {
		$p = wc_get_product( $id );
		if ( $p ) {
			$p->delete( true );
		}
	}
	if ( ! empty( $page_id ) ) {
		wp_delete_post( $page_id, true );
	}
	foreach ( $user_ids as $uid ) {
		wp_delete_user( $uid );
	}

	// Action Scheduler jobs triggered by the test products.
	$new_actions = $wpdb->get_results( $wpdb->prepare( "SELECT action_id, hook, args FROM $as_table WHERE action_id > %d", $start_as ), ARRAY_A );
	$ours_as     = array_filter(
		$new_actions,
		function ( $a ) use ( $product_ids ) {
			foreach ( $product_ids as $id ) {
				if ( preg_match( '/(^|\D)' . $id . '(\D|$)/', (string) $a['args'] ) ) {
					return true;
				}
			}
			return false;
		}
	);
	foreach ( $ours_as as $a ) {
		ActionScheduler::store()->delete_action( (int) $a['action_id'] );
	}
	echo '   removed ' . count( $ours_as ) . ' Action Scheduler job(s): ' . implode( ', ', array_unique( array_column( $ours_as, 'hook' ) ) ) . "\n";

	$ids_in = implode( ',', array_map( 'intval', array_merge( $product_ids, array( (int) ( $page_id ?? 0 ) ) ) ) );
	pqbg_t( 'codes table back to its starting row count', $base_c === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" ) );
	pqbg_t( 'products and variations back to the starting count', $base_prod === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" ) );
	pqbg_t( 'no posts, meta or term relationships left for test IDs', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ($ids_in)" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ($ids_in)" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id IN ($ids_in)" ) );
	pqbg_t( 'no WooCommerce lookup rows left for test IDs', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id IN ($ids_in)" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_product_attributes_lookup WHERE product_id IN ($ids_in) OR product_or_parent_id IN ($ids_in)" ) );
	pqbg_t( 'users back to the starting count', $base_user === (int) count_users()['total_users'] );
	pqbg_t( 'no test Action Scheduler jobs left', 0 === count( array_filter( $wpdb->get_col( $wpdb->prepare( "SELECT args FROM $as_table WHERE action_id > %d", $start_as ) ), fn( $args ) => (bool) array_filter( $product_ids, fn( $id ) => (bool) preg_match( '/(^|\D)' . $id . '(\D|$)/', (string) $args ) ) ) ) );
}

pqbg_test_done();
