<?php
/**
 * Unit tests for the self-hosted updater: version comparison + how it populates
 * the update_plugins transient and the plugins_api details object.
 *
 * Run: php tests/unit/UpdaterTest.php
 *
 * @package Inklura\SEO
 */

require __DIR__ . '/../bootstrap.php';

// Extra WP stubs the Updater needs (the manifest fetch path isn't exercised
// here because the cache is primed directly).
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		// .../plugins/inklura-seo/inklura-seo.php → inklura-seo/inklura-seo.php
		$parts = explode( '/', $file );
		$n     = count( $parts );
		return $n >= 2 ? $parts[ $n - 2 ] . '/' . $parts[ $n - 1 ] : $file;
	}
}

require __DIR__ . '/../../src/Options.php';
require __DIR__ . '/../../src/Plugin.php';
require __DIR__ . '/../../src/Updater.php';

use Inklura\SEO\Options;
use Inklura\SEO\Plugin;
use Inklura\SEO\Updater;

$failures = 0;
$check    = function ( $c, $l ) use ( &$failures ) {
	echo ( $c ? '  ok   ' : '  FAIL ' ) . $l . "\n";
	if ( ! $c ) {
		++$failures;
	}
};

$basename = 'inklura-seo/inklura-seo.php';
$prime    = function ( $version ) {
	$GLOBALS['_inkseo_st'][ Updater::CACHE_KEY ] = array(
		'name'         => 'Inklura SEO',
		'slug'         => 'inklura-seo',
		'version'      => $version,
		'download_url' => 'https://github.com/webdesign29/inklura-seo/releases/latest/download/inklura-seo.zip',
		'homepage'     => 'https://wp-plugins.inklura.fr/inklura-seo',
		'requires'     => '5.8',
		'tested'       => '6.7',
		'requires_php' => '7.4',
		'sections'     => array(
			'description' => 'desc',
			'changelog'   => '<h4>x</h4>',
		),
	);
};

$updater = new Updater( new Options(), Plugin::instance() );
$mk      = function () {
	$t            = new stdClass();
	$t->response  = array();
	$t->no_update = array();
	return $t;
};

// Newer remote → goes into ->response with the right package + version.
$prime( '0.5.0' );
$t = $updater->inject_update( $mk() );
$check( isset( $t->response[ $basename ] ), 'newer version → injected into ->response' );
$check( ( isset( $t->response[ $basename ]->new_version ) ? $t->response[ $basename ]->new_version : '' ) === '0.5.0', 'new_version is the remote version' );
$check( false !== strpos( (string) ( isset( $t->response[ $basename ]->package ) ? $t->response[ $basename ]->package : '' ), 'releases/latest/download/inklura-seo.zip' ), 'package is the release URL' );
$check( ! isset( $t->no_update[ $basename ] ), 'newer version → not in ->no_update' );

// Same version → ->no_update (not an update).
$prime( '0.1.0' );
$t = $updater->inject_update( $mk() );
$check( ! isset( $t->response[ $basename ] ), 'same version → not in ->response' );
$check( isset( $t->no_update[ $basename ] ), 'same version → listed in ->no_update' );

// Older remote → ->no_update.
$prime( '0.0.9' );
$t = $updater->inject_update( $mk() );
$check( ! isset( $t->response[ $basename ] ), 'older version → not in ->response' );

// Non-object transient passed through unchanged (defensive).
$check( $updater->inject_update( false ) === false, 'non-object transient passed through' );

// plugins_api details for our slug.
$prime( '0.5.0' );
$info = $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'inklura-seo' ) );
$check( is_object( $info ) && ( isset( $info->version ) ? $info->version : '' ) === '0.5.0', 'plugin_info returns version' );
$check( is_object( $info ) && false !== strpos( (string) ( isset( $info->download_link ) ? $info->download_link : '' ), 'inklura-seo.zip' ), 'plugin_info has download_link' );
$check( is_object( $info ) && isset( $info->sections['changelog'] ), 'plugin_info has changelog section' );

// plugins_api ignores other slugs.
$other = $updater->plugin_info( 'ORIG', 'plugin_information', (object) array( 'slug' => 'something-else' ) );
$check( $other === 'ORIG', 'plugin_info passes through other slugs' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
