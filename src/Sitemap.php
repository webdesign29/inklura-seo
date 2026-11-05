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
		if ( $this->options->module_enabled( 'sitemap' ) ) {
			add_filter( 'wp_sitemaps_enabled', '__return_false' );
		}
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
		add_rewrite_rule( '^inklura-sitemap\\.xml$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/** Remove our in-memory rules before WordPress flushes on deactivation. */
	public static function remove_rewrite(): void {
		global $wp_rewrite;
		foreach ( array_keys( $wp_rewrite->extra_rules_top ) as $rule ) {
			if ( 0 === strpos( $rule, '^inklura-sitemap' ) ) {
				unset( $wp_rewrite->extra_rules_top[ $rule ] );
			}
		}
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
		if ( ! $this->options->module_enabled( 'sitemap' ) || ! get_option( 'blog_public' ) ) {
			$this->not_found();
			return;
		}

		$type = get_query_var( self::VAR_TYPE );
		$page = get_query_var( self::VAR_PAGE );
		if ( ! is_scalar( $type ) || ! is_scalar( $page ) ) {
			$this->not_found();
			return;
		}
		$type = (string) $type;
		$page = (int) $page;

		if ( '' === $type ) {
			$xml = $this->build_index();
		} else {
			$xml = $this->build_subsitemap( $type, $page );
		}

		if ( null === $xml ) {
			$this->not_found();
			return;
		}

		status_header( 200 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- build_* escape each field.
		exit;
	}

	private function not_found(): void {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	// -----------------------------------------------------------------
	// Index
	// -----------------------------------------------------------------

	/** Build the index from counts; never load every published post here. */
	private function build_index(): string {
		$specs = array();
		$this->add_index_specs( $specs, 'content', $this->content_count() );
		if ( $this->options->get( 'sitemap_taxonomies', 1 ) ) {
			foreach ( $this->public_taxonomies() as $taxonomy ) {
				$count = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true, 'fields' => 'count' ) );
				$this->add_index_specs( $specs, 'tax-' . $taxonomy, is_wp_error( $count ) ? 0 : (int) $count );
			}
		}
		if ( $this->authors_enabled() ) {
			$query = new \WP_User_Query( $this->author_args( 1, true ) );
			$this->add_index_specs( $specs, 'author', (int) $query->get_total() );
		}
		$specs = apply_filters( 'inklura/seo/sitemap_index', $specs );
		return self::build_index_xml( is_array( $specs ) ? $specs : array() );
	}

	/** Append one index entry per page without materializing its URL list. */
	private function add_index_specs( array &$specs, string $type, int $count ): void {
		$pages = (int) ceil( $count / $this->per_page() );
		for ( $page = 1; $page <= $pages; ++$page ) {
			$specs[] = array( 'loc' => self::page_url( $type, $page ) );
		}
	}

	/** Build just the requested page. Unknown and empty pages are 404s. */
	private function build_subsitemap( string $type, int $page ): ?string {
		if ( $page < 1 ) {
			return null;
		}
		$entries = array();
		if ( 'content' === $type ) {
			$entries = $this->collect_content_entries( $page );
		} elseif ( 0 === strpos( $type, 'tax-' ) ) {
			$taxonomy = substr( $type, 4 );
			if ( $this->options->get( 'sitemap_taxonomies', 1 ) && in_array( $taxonomy, $this->public_taxonomies(), true ) ) {
				$entries = $this->collect_term_entries( $taxonomy, $page );
			}
		} elseif ( 'author' === $type && $this->authors_enabled() ) {
			$entries = $this->collect_author_entries( $page );
		}
		return empty( $entries ) ? null : self::build_xml( $entries );
	}

	/** Shared eligibility for count and page queries: public, indexable content. */
	private function content_args(): array {
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		return array(
			'post_type'              => $this->public_post_types(),
			'post_status'            => 'publish',
			'has_password'           => false,
			'ignore_sticky_posts'    => true,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'post__not_in'           => $front ? array( $front ) : array(),
			'meta_query'             => array(
				'relation' => 'OR',
				array( 'key' => MetaBox::META_NOINDEX, 'compare' => 'NOT EXISTS' ),
				array( 'key' => MetaBox::META_NOINDEX, 'value' => '1', 'compare' => '!=' ),
			),
		);
	}

	/** Home is included once, provided the static front page is indexable. */
	private function home_entry(): ?array {
		$modified = '';
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$id   = (int) get_option( 'page_on_front' );
			$post = get_post( $id );
			if ( ! $id || ! $post || 'publish' !== $post->post_status || '' !== $post->post_password
				|| '1' === (string) get_post_meta( $id, MetaBox::META_NOINDEX, true ) ) {
				return null;
			}
			$modified = (string) get_post_modified_time( 'c', true, $post );
		}
		return array( 'loc' => (string) home_url( '/' ), 'lastmod' => $modified );
	}

	private function content_count(): int {
		$args                   = $this->content_args();
		$args['posts_per_page']  = 1;
		$args['fields']          = 'ids';
		$args['no_found_rows']   = false;
		$query                  = new \WP_Query( $args );
		return (int) $query->found_posts + ( null !== $this->home_entry() ? 1 : 0 );
	}

	/** Query at most one page, with stable ordering and no arbitrary 5,000 cap. */
	private function collect_content_entries( int $page ): array {
		$home    = $this->home_entry();
		$per     = $this->per_page();
		$entries = 1 === $page && null !== $home ? array( $home ) : array();
		$limit   = $per - count( $entries );
		if ( $limit > 0 ) {
			$args                   = $this->content_args();
			$args['posts_per_page']  = $limit;
			$args['offset']          = max( 0, ( $page - 1 ) * $per - ( null !== $home ? 1 : 0 ) );
			$query                  = new \WP_Query( $args );
			$with_images            = (bool) $this->options->get( 'sitemap_images', 1 );
			foreach ( $query->posts as $post ) {
				$link = get_permalink( $post );
				if ( ! $link ) {
					continue;
				}
				$row = array( 'loc' => (string) $link, 'lastmod' => (string) get_post_modified_time( 'c', true, $post ) );
				if ( $with_images ) {
					$img = get_the_post_thumbnail_url( $post, 'full' );
					if ( $img ) {
						$row['images'] = array( (string) $img );
					}
				}
				$entries[] = $row;
			}
		}
		// The filter receives only the current page's content entries.
		$entries = apply_filters( 'inklura/seo/sitemap_entries', $entries );
		return is_array( $entries ) ? $entries : array();
	}

	/** Paginated taxonomy URLs; optional lastmod is omitted when unknown. */
	private function collect_term_entries( string $taxonomy, int $page ): array {
		$entries = array();
		$terms   = get_terms( array(
			'taxonomy' => $taxonomy, 'hide_empty' => true,
			'number' => $this->per_page(), 'offset' => ( $page - 1 ) * $this->per_page(),
			'orderby' => 'term_id', 'order' => 'ASC',
		) );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $entries;
		}
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( $link && ! is_wp_error( $link ) ) {
				$entries[] = array( 'loc' => (string) $link );
			}
		}
		return $entries;
	}

	private function authors_enabled(): bool {
		return (bool) $this->options->get( 'sitemap_authors', 1 ) && ! $this->options->get( 'noindex_author', 0 );
	}

	private function author_args( int $page, bool $count = false ): array {
		return array(
			'has_published_posts' => $this->public_post_types(), 'fields' => 'ID',
			'number' => $count ? 1 : $this->per_page(),
			'offset' => $count ? 0 : ( $page - 1 ) * $this->per_page(),
			'orderby' => 'ID', 'order' => 'ASC', 'count_total' => $count,
		);
	}

	/** One bounded author query, with no per-author lastmod queries. */
	private function collect_author_entries( int $page ): array {
		$query   = new \WP_User_Query( $this->author_args( $page ) );
		$entries = array();
		$ids     = $query->get_results();
		cache_users( array_map( 'intval', $ids ) );
		foreach ( $ids as $id ) {
			$link = get_author_posts_url( (int) $id );
			if ( $link ) {
				$entries[] = array( 'loc' => (string) $link );
			}
		}
		return $entries;
	}

	private function public_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		return array_keys( array_filter( $types, 'is_post_type_viewable' ) );
	}

	private function public_taxonomies(): array {
		$taxes = get_taxonomies( array( 'public' => true, 'publicly_queryable' => true ), 'names' );
		unset( $taxes['post_format'] );
		return array_values( $taxes );
	}

	/** Directory links work with both pretty and plain WordPress permalinks. */
	public static function index_url(): string {
		return get_option( 'permalink_structure' ) ? self::sitemap_url( (string) home_url( '/' ) )
			: (string) add_query_arg( self::QUERY_VAR, 1, home_url( '/' ) );
	}

	private static function page_url( string $type, int $page ): string {
		return get_option( 'permalink_structure' ) ? self::subsitemap_url( (string) home_url( '/' ), $type, $page )
			: (string) add_query_arg( array( self::QUERY_VAR => 1, self::VAR_TYPE => $type, self::VAR_PAGE => $page ), home_url( '/' ) );
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
		if ( $public && get_option( 'blog_public' ) && $this->options->module_enabled( 'sitemap' ) ) {
			$output .= "\nSitemap: " . self::index_url() . "\n";
		}
		return (string) $output;
	}
}
