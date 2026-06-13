<?php
/**
 * Plugin bootstrap: wires the SEO feature modules.
 *
 * @package Inklura\SEO
 */

namespace Inklura\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton orchestrator.
 *
 * Each feature module is a small class exposing register(); front-end modules
 * are gated by their per-blog "enable_*" setting. Admin-only modules (the meta
 * box, the settings page, the network page, the updater) boot only where they
 * can do something.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Options */
	private $options;

	/** @var array<string,object> Booted module instances, keyed by slug. */
	private $modules = array();

	/** @var bool */
	private $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->options = new Options();
	}

	public function options(): Options {
		return $this->options;
	}

	/**
	 * Module instance accessor.
	 *
	 * @param string $slug
	 * @return object|null
	 */
	public function module( string $slug ) {
		return isset( $this->modules[ $slug ] ) ? $this->modules[ $slug ] : null;
	}

	/**
	 * Boot everything. Safe to call once; subsequent calls no-op.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Master kill switch (wp-config: define('INKLURA_SEO_ENABLE', false);).
		if ( defined( 'INKLURA_SEO_ENABLE' ) && ! INKLURA_SEO_ENABLE ) {
			return;
		}

		// Multisite: settings are blog-scoped, so reset the per-request cache
		// whenever code switches the current blog.
		if ( is_multisite() ) {
			add_action( 'switch_blog', array( $this->options, 'flush_cache' ) );
		}

		$want_admin_ui = is_admin() || ( defined( 'WP_CLI' ) && WP_CLI );

		// Front-end output modules, each gated by its per-blog setting.
		$front = array(
			'frontend'    => array( Frontend::class, 'titles' ),
			'opengraph'   => array( OpenGraph::class, 'og' ),
			'schema'      => array( Schema::class, 'schema' ),
			'breadcrumbs' => array( Breadcrumbs::class, 'breadcrumbs' ),
		);
		foreach ( $front as $slug => $info ) {
			if ( $this->options->module_enabled( $info[1] ) ) {
				$this->boot_module( $slug, $info[0] );
			}
		}

		// The sitemap needs its rewrite rule + query var registered on every
		// request (front and admin) so the URL keeps resolving; it self-gates
		// inside render() when the module is disabled.
		if ( $this->options->module_enabled( 'sitemap' ) ) {
			$this->boot_module( 'sitemap', Sitemap::class );
		}

		// The per-post meta box + per-term SEO fields (admin-only, but their save
		// hooks fire on save_post / created_*term* during normal requests).
		if ( $want_admin_ui ) {
			$this->boot_module( 'metabox', MetaBox::class );
			$this->boot_module( 'termmeta', TermMeta::class );
			$this->boot_module( 'settings', Settings::class );
			if ( is_multisite() ) {
				$this->boot_module( 'network', Network::class );
			}
		}

		// Auto-updates for normal-plugin installs only.
		if ( ! INKLURA_SEO_IS_MU && ( $want_admin_ui || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) ) {
			$this->boot_module( 'updater', Updater::class );
		}

		/**
		 * Fires after Inklura SEO has booted all enabled modules.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'inklura/seo/booted', $this );
	}

	/**
	 * Instantiate a module, call register(), and track it.
	 *
	 * @param string $slug
	 * @param string $class FQCN.
	 */
	private function boot_module( string $slug, string $class ): void {
		if ( ! class_exists( $class ) ) {
			return;
		}
		$module = new $class( $this->options, $this );
		if ( method_exists( $module, 'register' ) ) {
			$module->register();
		}
		$this->modules[ $slug ] = $module;
	}
}
