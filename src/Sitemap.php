<?php
/**
 * XML sitemap with a real sitemap index.
 *
 *   /inklura-sitemap.xml                 → the sitemap index (lists sub-sitemaps)
 *   /inklura-sitemap-<type>-<page>.xml   → a paginated sub-sitemap of URLs
 *
 * <type> is one of: content (posts/pages/CPTs), tax-<taxonomy> (term archives),
 * author (author archives). Each sub-sitemap is capped at `sitemap_max_urls`
 * entries; when a set exceeds the cap it is split across numbered pages and the
 * index lists each page.
 *
 * Entries carry an accurate <lastmod> and, for content with a featured image,
 * an <image:image> block. Wired via rewrite rules + query vars (no physical
 * files), and advertised through robots.txt.
 *
 * @package Inklura\SEO
 */

namespace Inklura\SEO;

defined( 'ABSPATH' ) || exit;

class Sitemap {

	const QUERY_VAR = 'inklura_seo_sitemap';
	const VAR_TYPE  = 'inklura_seo_sm_type';
	const VAR_PAGE  = 'inklura_seo_sm_page';
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
	 * Register the rewrite rules. Static so the activation hook can call it
	 * before flush_rewrite_rules() without booting a module instance.
	 */
	public static function add_rewrite(): void {
		// Sub-sitemap: inklura-sitemap-<type>-<page>.xml
		add_rewrite_rule(
			'^inklura-sitemap-([a-z0-9_-]+)-([0-9]+)\.xml$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::VAR_TYPE . '=$matches[1]&' . self::VAR_PAGE . '=$matches[2]',
			'top'
		);
		// Index: inklura-sitemap.xml
		add_rewrite_rule( '^' . self::PATH . '$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * @param array<int,string> $vars
	 * @return array<int,string>
	 */
	public function query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		$vars[] = self::VAR_TYPE;
		$vars[] = self::VAR_PAGE;
		return $vars;
	}

	/** Render the sitemap (index or sub-sitemap) when the query var is present. */
	public function maybe_render(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		// Self-gate: if disabled after the rewrite was registered, 404 cleanly.
		if ( ! $this->options->module_enabled( 'sitemap' ) ) {
			return;
		}

		$type = (string) get_query_var( self::VAR_TYPE );
		$page = (int) get_query_var( self::VAR_PAGE );

		if ( '' === $type ) {
			$xml = $this->build_index();
		} else {
			$xml = $this->build_subsitemap( $type, $page > 0 ? $page : 1 );
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- build_* escape each field.
		exit;
	}

	// -----------------------------------------------------------------
	// Index
	// -----------------------------------------------------------------

	/** Build the sitemap index, listing every populated sub-sitemap page. */
	private function build_index(): string {
		$per   = $this->per_page();
		$home  = (string) home_url( '/' );
		$specs = array();

		// content (posts + pages + public CPTs).
		$content = $this->collect_content_entries();
		$this->add_index_specs( $specs, 'content', $content, $per, $home );

		// taxonomy archives.
		if ( $this->options->get( 'sitemap_taxonomies', 1 ) ) {
			foreach ( $this->public_taxonomies() as $taxonomy ) {
				$terms = $this->collect_term_entries( $taxonomy );
				$this->add_index_specs( $specs, 'tax-' . $taxonomy, $terms, $per, $home );
			}
		}

		// author archives.
		if ( $this->options->get( 'sitemap_authors', 1 ) ) {
			$authors = $this->collect_author_entries();
			$this->add_index_specs( $specs, 'author', $authors, $per, $home );
		}

		/**
		 * Filter the sub-sitemap specs before the index is rendered.
		 *
		 * @param array $specs Each: array{ loc:string, lastmod:string }.
		 */
		$specs = apply_filters( 'inklura/seo/sitemap_index', $specs );

		return self::build_index_xml( is_array( $specs ) ? $specs : array() );
	}

	/**
	 * Append one index <sitemap> spec per page of the given entry set.
	 *
	 * @param array<int,array{loc:string,lastmod:string}> $specs   Accumulator (by ref).
	 * @param string                                       $type    Sub-sitemap type token.
	 * @param array<int,array<string,mixed>>               $entries Entries for this type.
	 * @param int                                          $per     Max URLs per page.
	 * @param string                                       $home    Home URL.
	 */
	private function add_index_specs( array &$specs, string $type, array $entries, int $per, string $home ): void {
		if ( empty( $entries ) ) {
			return;
		}
		$pages = Options::chunk_entries( $entries, $per );
		foreach ( $pages as $i => $chunk ) {
			$page    = $i + 1;
			$specs[] = array(
				'loc'     => self::subsitemap_url( $home, $type, $page ),
				'lastmod' => self::latest_lastmod( $chunk ),
			);
		}
	}

	// -----------------------------------------------------------------
	// Sub-sitemaps
	// -----------------------------------------------------------------

	/**
	 * Build a single sub-sitemap page of URLs for the given type.
	 *
	 * @param string $type content | tax-<taxonomy> | author
	 * @param int    $page 1-based page number.
	 * @return string
	 */
	private function build_subsitemap( string $type, int $page ): string {
		$entries = array();

		if ( 'content' === $type ) {
			$entries = $this->collect_content_entries();
		} elseif ( 0 === strpos( $type, 'tax-' ) ) {
			$taxonomy = substr( $type, 4 );
			if ( $this->options->get( 'sitemap_taxonomies', 1 ) && in_array( $taxonomy, $this->public_taxonomies(), true ) ) {
				$entries = $this->collect_term_entries( $taxonomy );
			}
		} elseif ( 'author' === $type ) {
			if ( $this->options->get( 'sitemap_authors', 1 ) ) {
				$entries = $this->collect_author_entries();
			}
		}

		$pages = Options::chunk_entries( $entries, $this->per_page() );
		$idx   = $page - 1;
		$slice = isset( $pages[ $idx ] ) ? $pages[ $idx ] : array();

		return self::build_xml( $slice );
	}

	// -----------------------------------------------------------------
	// Entry collection
	// -----------------------------------------------------------------

	/**
	 * Gather published posts, pages and public CPTs as entry rows, including the
	 * home page and (when enabled) an <image:image> for the featured image.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_content_entries(): array {
		$entries = array();

		// Home page first.
		$entries[] = array(
			'loc'     => (string) home_url( '/' ),
			'lastmod' => '',
		);

		$with_images = (bool) $this->options->get( 'sitemap_images', 1 );

		$posts = get_posts(
			array(
				'post_type'      => $this->public_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 5000,
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
			$row = array(
				'loc'     => (string) $link,
				'lastmod' => (string) get_post_modified_time( 'c', true, $post ),
			);
			if ( $with_images && has_post_thumbnail( $post ) ) {
				$img = get_the_post_thumbnail_url( $post, 'full' );
				if ( $img ) {
					$row['images'] = array( (string) $img );
				}
			}
			$entries[] = $row;
		}

		/**
		 * Filter the content sitemap entries before rendering.
		 *
		 * @param array $entries Each: array{ loc, lastmod, images? }.
		 */
		$entries = apply_filters( 'inklura/seo/sitemap_entries', $entries );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Gather term-archive entries for one taxonomy.
	 *
	 * @param string $taxonomy
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_term_entries( string $taxonomy ): array {
		$entries = array();
		$terms   = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => 5000,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $entries;
		}
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( ! $link || is_wp_error( $link ) ) {
				continue;
			}
			$entries[] = array(
				'loc'     => (string) $link,
				'lastmod' => $this->term_lastmod( $term ),
			);
		}
		return $entries;
	}

	/**
	 * Gather author-archive entries for authors who have published content.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_author_entries(): array {
		$entries = array();
		if ( ! function_exists( 'get_users' ) ) {
			return $entries;
		}
		$users = get_users(
			array(
				'has_published_posts' => true,
				'fields'              => array( 'ID' ),
			)
		);
		foreach ( $users as $user ) {
			$uid  = (int) ( isset( $user->ID ) ? $user->ID : $user );
			$link = get_author_posts_url( $uid );
			if ( ! $link ) {
				continue;
			}
			$entries[] = array(
				'loc'     => (string) $link,
				'lastmod' => $this->author_lastmod( $uid ),
			);
		}
		return $entries;
	}

	/**
	 * Most-recent modified date among a term's published posts (accurate lastmod).
	 *
	 * @param object $term WP_Term.
	 * @return string ISO-8601 date or ''.
	 */
	private function term_lastmod( $term ): string {
		if ( ! isset( $term->term_id, $term->taxonomy ) ) {
			return '';
		}
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => (string) $term->taxonomy,
						'field'    => 'term_id',
						'terms'    => (int) $term->term_id,
					),
				),
			)
		);
		if ( empty( $posts ) ) {
			return '';
		}
		return (string) get_post_modified_time( 'c', true, (int) $posts[0] );
	}

	/**
	 * Most-recent modified date among an author's published posts.
	 *
	 * @param int $author_id
	 * @return string ISO-8601 date or ''.
	 */
	private function author_lastmod( int $author_id ): string {
		$posts = get_posts(
			array(
				'author'         => $author_id,
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);
		if ( empty( $posts ) ) {
			return '';
		}
		return (string) get_post_modified_time( 'c', true, (int) $posts[0] );
	}

	/** Public, sitemap-eligible post types. */
	private function public_post_types(): array {
		$types = get_post_types(
			array(
				'public'              => true,
				'exclude_from_search' => false,
			),
			'names'
		);
		unset( $types['attachment'] );
		$types = array_values( $types );
		return ! empty( $types ) ? $types : array( 'post', 'page' );
	}

	/** Public taxonomies eligible for the sitemap. */
	private function public_taxonomies(): array {
		$taxes = get_taxonomies( array( 'public' => true ), 'names' );
		// Post format is noise in a sitemap.
		unset( $taxes['post_format'] );
		return array_values( $taxes );
	}

	/** The per-page URL cap, clamped to a sane range. */
	private function per_page(): int {
		$n = (int) $this->options->get( 'sitemap_max_urls', 1000 );
		if ( $n < 1 ) {
			$n = 1;
		}
		if ( $n > 50000 ) {
			$n = 50000; // sitemaps.org hard cap.
		}
		return $n;
	}

	// -----------------------------------------------------------------
	// Pure XML builders (WP-free except the escape helper; unit-tested)
	// -----------------------------------------------------------------

	/**
	 * Build the <urlset> XML from entry rows. Each row: { loc, lastmod, images? }.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @return string
	 */
	public static function build_xml( array $entries ): string {
		$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
			. ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

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
			if ( ! empty( $e['images'] ) && is_array( $e['images'] ) ) {
				foreach ( $e['images'] as $img ) {
					$img = (string) $img;
					if ( '' === $img ) {
						continue;
					}
					$out .= "\t\t<image:image>\n";
					$out .= "\t\t\t<image:loc>" . self::xml_escape( $img ) . "</image:loc>\n";
					$out .= "\t\t</image:image>\n";
				}
			}
			$out .= "\t</url>\n";
		}

		$out .= '</urlset>' . "\n";
		return $out;
	}

	/**
	 * Build the <sitemapindex> XML from sub-sitemap specs: { loc, lastmod }.
	 *
	 * @param array<int,array{loc:string,lastmod:string}> $specs
	 * @return string
	 */
	public static function build_index_xml( array $specs ): string {
		$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $specs as $s ) {
			$loc = isset( $s['loc'] ) ? (string) $s['loc'] : '';
			if ( '' === $loc ) {
				continue;
			}
			$out .= "\t<sitemap>\n";
			$out .= "\t\t<loc>" . self::xml_escape( $loc ) . "</loc>\n";
			if ( ! empty( $s['lastmod'] ) ) {
				$out .= "\t\t<lastmod>" . self::xml_escape( (string) $s['lastmod'] ) . "</lastmod>\n";
			}
			$out .= "\t</sitemap>\n";
		}

		$out .= '</sitemapindex>' . "\n";
		return $out;
	}

	/**
	 * The newest lastmod among a set of entries (for index <lastmod>). Pure.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @return string The lexicographically-greatest non-empty lastmod, or ''.
	 */
	public static function latest_lastmod( array $entries ): string {
		$latest = '';
		foreach ( $entries as $e ) {
			$lm = isset( $e['lastmod'] ) ? (string) $e['lastmod'] : '';
			if ( '' !== $lm && $lm > $latest ) {
				$latest = $lm;
			}
		}
		return $latest;
	}

	/**
	 * The absolute URL of the sitemap index, for a given home URL. Pure helper.
	 *
	 * @param string $home_url The site's home URL.
	 * @return string
	 */
	public static function sitemap_url( string $home_url ): string {
		return rtrim( $home_url, '/' ) . '/' . self::PATH;
	}

	/**
	 * The absolute URL of a sub-sitemap page. Pure helper.
	 *
	 * @param string $home_url Home URL.
	 * @param string $type     Sub-sitemap type token.
	 * @param int    $page     1-based page number.
	 * @return string
	 */
	public static function subsitemap_url( string $home_url, string $type, int $page ): string {
		$page = $page > 0 ? $page : 1;
		return rtrim( $home_url, '/' ) . '/inklura-sitemap-' . $type . '-' . $page . '.xml';
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
