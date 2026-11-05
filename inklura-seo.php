<?php
/**
 * Plugin Name:       Inklura SEO
 * Plugin URI:        https://github.com/webdesign29/inklura-seo
 * Description:        Lightweight, dependency-free SEO for WordPress: titles, meta description, canonical, robots, Open Graph + Twitter Cards, an XML sitemap, JSON-LD schema, and a per-post SEO meta box. Multisite-safe.
 * Version:           0.2.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Benjamin Favre
 * Author URI:        https://www.inklura.fr/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       inklura-seo
 *
 * This file carries a standard plugin header so the package works BOTH as a
 * normal activatable plugin AND as a must-use plugin (when copied into
 * wp-content/mu-plugins). The Updater only registers for normal-plugin installs.
 *
 * @package Inklura\SEO
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'INKLURA_SEO_VERSION' ) ) {
	// Already loaded (e.g. present as both mu-plugin and normal plugin).
	return;
}

define( 'INKLURA_SEO_VERSION', '0.2.2' );
define( 'INKLURA_SEO_FILE', __FILE__ );
define( 'INKLURA_SEO_DIR', __DIR__ );
// Must-use installs can't self-update through wp-admin (a fleet deploy handles
// those); the Updater only registers for normal-plugin installs.
define( 'INKLURA_SEO_IS_MU', false !== strpos( __DIR__, 'mu-plugins' ) );
define(
	'INKLURA_SEO_URL',
	untrailingslashit(
		defined( 'WPMU_PLUGIN_URL' ) && false !== strpos( __DIR__, 'mu-plugins' )
			? rtrim( WPMU_PLUGIN_URL, '/' ) . '/' . basename( __DIR__ )
			: plugin_dir_url( __FILE__ )
	)
);

/**
 * Minimal PSR-4-ish autoloader for the Inklura\SEO namespace.
 * Inklura\SEO\Foo -> src/Foo.php
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Inklura\\SEO\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'Inklura\\SEO\\' ) );
		$relative = str_replace( '\\', '/', $relative );
		$path     = INKLURA_SEO_DIR . '/src/' . $relative . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

// Function template tags cannot be autoloaded. Keep the breadcrumb helper
// callable even when its output module is disabled in settings.
require_once INKLURA_SEO_DIR . '/src/Breadcrumbs.php';

// Activation: register the sitemap rewrite rule, then flush so /inklura-sitemap.xml
// resolves immediately. Deactivation flushes again to clean up.
if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook(
		__FILE__,
		static function () {
			\Inklura\SEO\Sitemap::add_rewrite();
			flush_rewrite_rules();
		}
	);
}
if ( function_exists( 'register_deactivation_hook' ) ) {
	register_deactivation_hook(
		__FILE__,
		static function () {
			\Inklura\SEO\Sitemap::remove_rewrite();
			flush_rewrite_rules();
		}
	);
}

// Boot once WordPress core helpers are available.
\Inklura\SEO\Plugin::instance()->boot();
