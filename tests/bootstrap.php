<?php
/**
 * Shared WP-free test bootstrap: minimal stubs of the WordPress functions the
 * pure logic + the Updater touch. Actions and filters share one registry,
 * exactly like WordPress.
 *
 * Tests set behavior via these globals:
 *   $GLOBALS['_inkseo_opts']      option store (array)
 *   $GLOBALS['_inkseo_netopts']   site-option store (array)
 *   $GLOBALS['_inkseo_st']        site-transient store (array)
 *   $GLOBALS['_inkseo_multisite'] bool
 *
 * @package Inklura\SEO
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['_inkseo_opts']        = array();
$GLOBALS['_inkseo_netopts']     = array();
$GLOBALS['_inkseo_st']          = array();
$GLOBALS['_inkseo_filters']     = array();
$GLOBALS['_inkseo_shortcodes']  = array();
$GLOBALS['_inkseo_multisite']   = false;

/** Reset all mutable test state between scenarios. */
function inkseo_test_reset() {
	$GLOBALS['_inkseo_opts']       = array();
	$GLOBALS['_inkseo_netopts']    = array();
	$GLOBALS['_inkseo_st']         = array();
	$GLOBALS['_inkseo_filters']    = array();
	$GLOBALS['_inkseo_shortcodes'] = array();
	$GLOBALS['_inkseo_multisite']  = false;
}

if ( ! function_exists( 'get_option' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	// --- options ---
	function get_option( $k, $d = false ) {
		return array_key_exists( $k, $GLOBALS['_inkseo_opts'] ) ? $GLOBALS['_inkseo_opts'][ $k ] : $d;
	}
	function update_option( $k, $v, $a = null ) {
		$GLOBALS['_inkseo_opts'][ $k ] = $v;
		return true;
	}
	function add_option( $k, $v ) {
		$GLOBALS['_inkseo_opts'][ $k ] = $v;
		return true;
	}
	function delete_option( $k ) {
		unset( $GLOBALS['_inkseo_opts'][ $k ] );
		return true;
	}
	function get_site_option( $k, $d = false ) {
		return array_key_exists( $k, $GLOBALS['_inkseo_netopts'] ) ? $GLOBALS['_inkseo_netopts'][ $k ] : $d;
	}
	function update_site_option( $k, $v ) {
		$GLOBALS['_inkseo_netopts'][ $k ] = $v;
		return true;
	}
	function delete_site_option( $k ) {
		unset( $GLOBALS['_inkseo_netopts'][ $k ] );
		return true;
	}

	// --- site transients (used by the Updater) ---
	function get_site_transient( $k ) {
		return array_key_exists( $k, $GLOBALS['_inkseo_st'] ) ? $GLOBALS['_inkseo_st'][ $k ] : false;
	}
	function set_site_transient( $k, $v, $ttl = 0 ) {
		$GLOBALS['_inkseo_st'][ $k ] = $v;
		return true;
	}
	function delete_site_transient( $k ) {
		unset( $GLOBALS['_inkseo_st'][ $k ] );
		return true;
	}

	// --- hooks (actions + filters share ONE registry, like WordPress) ---
	function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {
		$GLOBALS['_inkseo_filters'][ $tag ][] = array( 'cb' => $cb, 'args' => (int) $args );
		return true;
	}
	function add_action( $tag, $cb, $prio = 10, $args = 1 ) {
		return add_filter( $tag, $cb, $prio, $args );
	}
	function do_action( $tag, ...$a ) {
		if ( empty( $GLOBALS['_inkseo_filters'][ $tag ] ) ) {
			return;
		}
		foreach ( $GLOBALS['_inkseo_filters'][ $tag ] as $h ) {
			call_user_func_array( $h['cb'], array_slice( $a, 0, $h['args'] ) );
		}
	}
	function apply_filters( $tag, $value, ...$a ) {
		if ( empty( $GLOBALS['_inkseo_filters'][ $tag ] ) ) {
			return $value;
		}
		foreach ( $GLOBALS['_inkseo_filters'][ $tag ] as $h ) {
			$all   = array_merge( array( $value ), $a );
			$value = call_user_func_array( $h['cb'], array_slice( $all, 0, $h['args'] ) );
		}
		return $value;
	}

	// --- environment ---
	function is_multisite() {
		return ! empty( $GLOBALS['_inkseo_multisite'] );
	}

	// --- sanitization / strings ---
	function untrailingslashit( $s ) {
		return rtrim( (string) $s, '/' );
	}
	function trailingslashit( $s ) {
		return rtrim( (string) $s, '/' ) . '/';
	}
	function wp_unslash( $v ) {
		return $v;
	}
	function sanitize_text_field( $s ) {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $s ) );
	}
	function sanitize_textarea_field( $s ) {
		return trim( (string) $s );
	}
	function esc_url_raw( $s ) {
		return trim( (string) $s );
	}
	function esc_url( $s ) {
		// Simplified core-ish behaviour: ampersands become &#038; entities.
		return str_replace( '&', '&#038;', (string) $s );
	}
	function esc_html( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}
	function esc_attr( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}
	function wp_json_encode( $v ) {
		return json_encode( $v );
	}
	function wp_strip_all_tags( $s ) {
		return trim( preg_replace( '/<[^>]*>/', ' ', (string) $s ) );
	}

	// --- shortcodes (only the bits Breadcrumbs touches at file-load time) ---
	function add_shortcode( $tag, $cb ) {
		$GLOBALS['_inkseo_shortcodes'][ $tag ] = $cb;
		return true;
	}
	function shortcode_atts( $defaults, $atts, $shortcode = '' ) {
		$atts = is_array( $atts ) ? $atts : array();
		$out  = array();
		foreach ( (array) $defaults as $k => $v ) {
			$out[ $k ] = array_key_exists( $k, $atts ) ? $atts[ $k ] : $v;
		}
		return $out;
	}
}

// Constants the plugin entry file would normally define.
defined( 'INKLURA_SEO_FILE' ) || define( 'INKLURA_SEO_FILE', '/wp/wp-content/plugins/inklura-seo/inklura-seo.php' );
defined( 'INKLURA_SEO_VERSION' ) || define( 'INKLURA_SEO_VERSION', '0.1.0' );
defined( 'INKLURA_SEO_IS_MU' ) || define( 'INKLURA_SEO_IS_MU', false );
