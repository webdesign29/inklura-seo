<?php
/**
 * A simple XML sitemap at /inklura-sitemap.xml listing published posts + pages,
 * each with a <lastmod>. Wired via a rewrite rule + query var (so it works
 * without a physical file), and advertised through the robots_txt filter.
 *
 * @package Inklura\SEO
 */

namespace Inklura\SEO;

defined( 'ABSPATH' ) || exit;

class Sitemap {

	const QUERY_VAR = 'inklura_seo_sitemap';
	const PATH      = 'inklura-sitemap.xml';

	/** @var Options */
	private $options;

	/** @var Plugin */
	private $plugin;

	public function __construct( Options $options, Plugin $plugin ) {
		$this->options = $options;
		$this->plugin  = $plugin;
	}

	public function register(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 10, 2 );
	}

	/**
	 * Register the rewrite rule. Static so the activation hook can call it
	 * before flush_rewrite_rules() without booting a module instance.
	 */
	public static function add_rewrite(): void {
		add_rewrite_rule( '^' . self::PATH . '$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * @param array<int,string> $vars
	 * @return array<int,string>
	 */
	public function query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** Render the sitemap when the query var is present. */
	public function maybe_render(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		// Self-gate: if disabled after the rewrite was registered, 404 cleanly.
		if ( ! $this->options->module_enabled( 'sitemap' ) ) {
			return;
		}

		$entries = $this->collect_entries();
		$xml     = self::build_xml( $entries );

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- build_xml() escapes each field.
		exit;
	}

	/**
	 * Gather published posts + pages as { loc, lastmod } rows.
	 *
	 * @return array<int,array{loc:string,lastmod:string}>
	 */
	private function collect_entries(): array {
		$entries = array();

		// Home page first.
		$entries[] = array(
			'loc'     => (string) home_url( '/' ),
			'lastmod' => '',
		);

		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 2000,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'has_password'   => false,
			)
		);

		foreach ( $posts as $post ) {
			$link = get_permalink( $post );
			if ( ! $link ) {
				continue;
			}
			$entries[] = array(
				'loc'     => (string) $link,
				'lastmod' => (string) get_post_modified_time( 'c', true, $post ),
			);
		}

		/**
		 * Filter the sitemap entries before rendering.
		 *
		 * @param array $entries Each: array{ loc:string, lastmod:string }.
		 */
		$entries = apply_filters( 'inklura/seo/sitemap_entries', $entries );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Build the urlset XML from entry rows. Pure + WP-free except for the escape
	 * helper, so it's unit-testable.
	 *
	 * @param array<int,array{loc:string,lastmod:string}> $entries
	 * @return string
	 */
	public static function build_xml( array $entries ): string {
		$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $entries as $e ) {
			$loc = isset( $e['loc'] ) ? (string) $e['loc'] : '';
			if ( '' === $loc ) {
				continue;
			}
			$out .= "\t<url>\n";
			$out .= "\t\t<loc>" . self::xml_escape( $loc ) . "</loc>\n";
			if ( ! empty( $e['lastmod'] ) ) {
				$out .= "\t\t<lastmod>" . self::xml_escape( (string) $e['lastmod'] ) . "</lastmod>\n";
			}
			$out .= "\t</url>\n";
		}

		$out .= '</urlset>' . "\n";
		return $out;
	}

	/**
	 * The absolute URL of the sitemap, for a given home URL. Pure helper.
	 *
	 * @param string $home_url The site's home URL.
	 * @return string
	 */
	public static function sitemap_url( string $home_url ): string {
		return rtrim( $home_url, '/' ) . '/' . self::PATH;
	}

	/** XML-escape a value (entity-encode the five predefined entities). */
	private static function xml_escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Add a Sitemap: line to robots.txt.
	 *
	 * @param string $output
	 * @param bool   $public
	 * @return string
	 */
	public function robots_txt( $output, $public ): string {
		if ( $this->options->module_enabled( 'sitemap' ) ) {
			$output .= "\nSitemap: " . self::sitemap_url( (string) home_url( '/' ) ) . "\n";
		}
		return (string) $output;
	}
}
