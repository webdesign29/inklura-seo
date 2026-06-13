<?php
/**
 * Self-hosted auto-updates for normal-plugin installs.
 *
 * WordPress only auto-updates plugins it knows about (wordpress.org). This wires
 * Inklura SEO into the same machinery against a self-hosted manifest:
 *   - pre_set_site_transient_update_plugins → inject an update when a newer
 *     version is published (so the Plugins screen shows "update available" and
 *     the one-click update works),
 *   - plugins_api → power the "View details" modal,
 *   - upgrader_source_selection → normalise the extracted folder name so the
 *     update installs back into wp-content/plugins/inklura-seo/.
 *
 * The remote check is cached (12 h) so it never hammers the manifest. Must-use
 * installs are excluded (they update via a fleet deploy).
 *
 * Manifest (JSON): { name, slug, version, download_url, homepage, requires,
 * tested, requires_php, author, last_updated, sections:{description,changelog} }.
 *
 * @package Inklura\SEO
 */

namespace Inklura\SEO;

defined( 'ABSPATH' ) || exit;

class Updater {

	const CACHE_KEY = 'inklura_seo_update_manifest';
	const CACHE_TTL = 12 * 3600;
	const SLUG      = 'inklura-seo';
	const DEFAULT_MANIFEST = 'https://wp-plugins.inklura.fr/api/update?slug=inklura-seo';

	/** @var Options */
	private $options;

	/** @var Plugin */
	private $plugin;

	/** @var string plugin_basename, e.g. "inklura-seo/inklura-seo.php" */
	private $basename;

	public function __construct( Options $options, Plugin $plugin ) {
		$this->options  = $options;
		$this->plugin   = $plugin;
		$this->basename = plugin_basename( INKLURA_SEO_FILE );
	}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		// Drop the cached manifest after a successful update so the next check is fresh.
		add_action( 'upgrader_process_complete', array( $this, 'flush_on_update' ), 10, 2 );
	}

	/** The manifest URL (constant > filter > default). */
	private function manifest_url(): string {
		$url = defined( 'INKLURA_SEO_UPDATE_URL' ) && INKLURA_SEO_UPDATE_URL ? (string) INKLURA_SEO_UPDATE_URL : self::DEFAULT_MANIFEST;
		return (string) apply_filters( 'inklura/inklura-seo/update_manifest_url', $url );
	}

	/**
	 * Fetch + cache the manifest. Returns null on any failure (fail-open).
	 *
	 * @param bool $force Skip the cache.
	 * @return array<string,mixed>|null
	 */
	public function manifest( bool $force = false ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$res = wp_remote_get(
			$this->manifest_url(),
			array(
				'timeout' => 8,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			// Cache a short empty marker so a down manifest doesn't retry every load.
			set_site_transient( self::CACHE_KEY, array(), 15 * 60 );
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
			set_site_transient( self::CACHE_KEY, array(), 15 * 60 );
			return null;
		}

		set_site_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * Inject an update entry into the update_plugins transient when the manifest
	 * advertises a newer version.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$m = $this->manifest();
		if ( ! $m ) {
			return $transient;
		}

		$remote  = (string) $m['version'];
		$current = INKLURA_SEO_VERSION;
		$item    = (object) array(
			'id'           => $this->basename,
			'slug'         => self::SLUG,
			'plugin'       => $this->basename,
			'new_version'  => $remote,
			'url'          => (string) ( isset( $m['homepage'] ) ? $m['homepage'] : '' ),
			'package'      => (string) $m['download_url'],
			'tested'       => (string) ( isset( $m['tested'] ) ? $m['tested'] : '' ),
			'requires'     => (string) ( isset( $m['requires'] ) ? $m['requires'] : '' ),
			'requires_php' => (string) ( isset( $m['requires_php'] ) ? $m['requires_php'] : '' ),
			'icons'        => isset( $m['icons'] ) && is_array( $m['icons'] ) ? $m['icons'] : array(),
		);

		if ( version_compare( $remote, $current, '>' ) ) {
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $this->basename ] = $item;
		} else {
			// Surface "no update" so WP doesn't keep asking wordpress.org for our slug.
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ $this->basename ] = $item;
		}
		return $transient;
	}

	/**
	 * Power the "View details" modal for our slug.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$m = $this->manifest();
		if ( ! $m ) {
			return $result;
		}

		$info = (object) array(
			'name'          => (string) ( isset( $m['name'] ) ? $m['name'] : 'Inklura SEO' ),
			'slug'          => self::SLUG,
			'version'       => (string) $m['version'],
			'author'        => (string) ( isset( $m['author'] ) ? $m['author'] : 'webdesign29' ),
			'homepage'      => (string) ( isset( $m['homepage'] ) ? $m['homepage'] : '' ),
			'requires'      => (string) ( isset( $m['requires'] ) ? $m['requires'] : '' ),
			'tested'        => (string) ( isset( $m['tested'] ) ? $m['tested'] : '' ),
			'requires_php'  => (string) ( isset( $m['requires_php'] ) ? $m['requires_php'] : '' ),
			'last_updated'  => (string) ( isset( $m['last_updated'] ) ? $m['last_updated'] : '' ),
			'download_link' => (string) $m['download_url'],
			'sections'      => isset( $m['sections'] ) && is_array( $m['sections'] ) ? $m['sections'] : array(),
		);
		if ( isset( $m['banners'] ) && is_array( $m['banners'] ) ) {
			$info->banners = $m['banners'];
		}
		return $info;
	}

	/**
	 * Ensure the extracted package folder is named for the plugin slug, so the
	 * update lands back in wp-content/plugins/inklura-seo/ even if the archive
	 * unpacks to e.g. inklura-seo-0.2.0/ (GitHub tarball convention).
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param object $upgrader
	 * @param array  $hook_extra
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $this->basename !== $hook_extra['plugin'] ) {
			return $source;
		}
		$desired = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) === $desired ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired ) ) {
			return $desired;
		}
		return $source;
	}

	/**
	 * Clear the cached manifest after our plugin is updated.
	 *
	 * @param object $upgrader
	 * @param array  $data
	 */
	public function flush_on_update( $upgrader, $data ): void {
		if (
			is_array( $data )
			&& ( isset( $data['type'] ) ? $data['type'] : '' ) === 'plugin'
			&& ( isset( $data['action'] ) ? $data['action'] : '' ) === 'update'
			&& ! empty( $data['plugins'] )
			&& in_array( $this->basename, (array) $data['plugins'], true )
		) {
			delete_site_transient( self::CACHE_KEY );
		}
	}
}
