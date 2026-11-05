<?php
/**
 * Front-end <head> output: document title, meta description, canonical URL and
 * the meta robots directive.
 *
 * Values come from the per-post SEO fields (saved by MetaBox) when present, and
 * otherwise from sensible defaults: the configured title format, the site name
 * and tagline, and the post excerpt/content for the description.
 *
 * @package Inklura\SEO
 */

namespace Inklura\SEO;

defined( 'ABSPATH' ) || exit;

class Frontend {

	/** @var Options */
	private $options;

	/** @var Plugin */
	private $plugin;

	public function __construct( Options $options, Plugin $plugin ) {
		$this->options = $options;
		$this->plugin  = $plugin;
	}

	public function register(): void {
		// Take ownership of <title>.
		add_action( 'after_setup_theme', array( $this, 'title_support' ), 100 );
		add_filter( 'document_title_separator', array( $this, 'title_separator' ) );
		add_filter( 'document_title_parts', array( $this, 'title_parts' ) );
		add_filter( 'pre_get_document_title', array( $this, 'pre_document_title' ) );

		// Meta description, canonical, robots.
		add_action( 'wp_head', array( $this, 'render_head' ), 1 );
		remove_action( 'wp_head', 'rel_canonical' );

		// Let WordPress emit its own robots; we add noindex via the filter.
		add_filter( 'wp_robots', array( $this, 'wp_robots' ) );
	}

	/** Register theme support at the point WordPress expects it. */
	public function title_support(): void {
		add_theme_support( 'title-tag' );
	}

	/**
	 * The separator WordPress uses between title parts.
	 *
	 * @param string $sep
	 * @return string
	 */
	public function title_separator( $sep ): string {
		return $this->separator();
	}

	/**
	 * Override the parts of the core-generated title so the site name placement
	 * matches our configured format. (Core joins these with the separator.)
	 *
	 * @param array<string,string> $parts
	 * @return array<string,string>
	 */
	public function title_parts( $parts ): array {
		// If we produced a full title via pre_get_document_title we won't reach
		// here; this is the graceful fallback when that filter is bypassed.
		return is_array( $parts ) ? $parts : array();
	}

	/**
	 * Produce the full document title from our format, honoring a per-post SEO
	 * title override when set.
	 *
	 * @param string $title
	 * @return string
	 */
	public function pre_document_title( $title ) {
		$sitename = (string) get_bloginfo( 'name' );
		$tagline  = (string) get_bloginfo( 'description' );
		$format   = (string) $this->options->get( 'title_format', '%title% %sep% %sitename%' );
		$page     = $this->current_page();

		$custom = $this->current_seo_title();
		if ( '' !== $custom ) {
			// A per-post/term SEO title still gets the %page% suffix on page 2+.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reapply the core formatting bypassed by pre_get_document_title.
			return apply_filters( 'document_title', Options::format_title_paged( '%title%', $custom, $sitename, $this->separator(), $tagline, $page, $this->page_word() ) );
		}

		$page_title = $this->context_title();

		// On the front page with no real "page title", drop %title% so we don't
		// get a dangling separator.
		if ( ( is_front_page() || is_home() ) && '' === $page_title ) {
			$page_title = $tagline;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reapply WordPress's document-title formatting and escaping.
		return apply_filters( 'document_title', Options::format_title_paged( $format, $page_title, $sitename, $this->separator(), $tagline, $page, $this->page_word() ) );
	}

	/**
	 * Emit description + canonical into <head>.
	 */
	public function render_head(): void {
		$desc = $this->description();
		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}

		$canonical = $this->canonical_url();
		if ( '' !== $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
		}
	}

	/**
	 * Add noindex,nofollow to the robots directive for posts flagged noindex and
	 * for archive contexts disabled via settings (search / paginated / author /
	 * date archives).
	 *
	 * @param array<string,bool|string> $robots
	 * @return array<string,bool|string>
	 */
	public function wp_robots( $robots ): array {
		if ( ! is_array( $robots ) ) {
			$robots = array();
		}
		if ( $this->is_noindex() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['index'], $robots['follow'] );
		}
		return $robots;
	}

	/** The current paged number (1 for the first / unpaged view). */
	private function current_page(): int {
		$paged = (int) get_query_var( 'paged' );
		if ( $paged < 1 ) {
			$paged = (int) get_query_var( 'page' ); // single-post pagination (<!--nextpage-->)
		}
		return $paged > 1 ? $paged : 1;
	}

	/** Localized word used in "%page% N" suffixes. */
	private function page_word(): string {
		return __( 'Page', 'inklura-seo' );
	}

	// -----------------------------------------------------------------
	// Value resolution
	// -----------------------------------------------------------------

	/** The configured separator glyph. */
	private function separator(): string {
		$key  = (string) $this->options->get( 'title_separator', '-' );
		$map  = Options::separators();
		return isset( $map[ $key ] ) ? $map[ $key ] : $key;
	}

	/** Per-post / per-term SEO title override, or '' if none. */
	private function current_seo_title(): string {
		if ( is_singular() || ( is_home() && get_option( 'page_for_posts' ) ) ) {
			$id = is_home() ? (int) get_option( 'page_for_posts' ) : (int) get_queried_object_id();
			if ( $id <= 0 ) {
				return '';
			}
			$val = get_post_meta( $id, MetaBox::META_TITLE, true );
			return is_string( $val ) ? trim( $val ) : '';
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$obj = get_queried_object();
			if ( $obj && isset( $obj->term_id ) ) {
				$val = get_term_meta( (int) $obj->term_id, TermMeta::META_TITLE, true );
				return is_string( $val ) ? trim( $val ) : '';
			}
		}
		return '';
	}

	/** A human title for the current context (post/page/archive/etc.). */
	private function context_title(): string {
		if ( is_home() && get_option( 'page_for_posts' ) ) {
			return (string) get_the_title( (int) get_option( 'page_for_posts' ) );
		}
		if ( is_404() ) {
			return __( 'Page not found', 'inklura-seo' );
		}
		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			return $id > 0 ? (string) get_the_title( $id ) : '';
		}
		if ( is_category() || is_tag() || is_tax() ) {
			return (string) single_term_title( '', false );
		}
		if ( is_post_type_archive() ) {
			return (string) post_type_archive_title( '', false );
		}
		if ( is_author() ) {
			$obj = get_queried_object();
			return ( $obj && isset( $obj->display_name ) ) ? (string) $obj->display_name : '';
		}
		if ( is_search() ) {
			return (string) get_search_query();
		}
		if ( is_year() || is_month() || is_day() ) {
			return (string) get_the_archive_title();
		}
		return '';
	}

	/** The meta description for the current context. */
	public function description(): string {
		if ( is_singular() || ( is_home() && get_option( 'page_for_posts' ) ) ) {
			$id = is_home() ? (int) get_option( 'page_for_posts' ) : (int) get_queried_object_id();
			if ( $id > 0 ) {
				if ( post_password_required( $id ) ) {
					return '';
				}
				$custom = get_post_meta( $id, MetaBox::META_DESC, true );
				if ( is_string( $custom ) && '' !== trim( $custom ) ) {
					return Options::summarize( $custom, 160 );
				}
				$post = get_post( $id );
				if ( $post ) {
					$source = '' !== trim( (string) $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
					return Options::summarize( (string) $source, 160 );
				}
			}
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$obj = get_queried_object();
			if ( $obj && isset( $obj->term_id ) ) {
				$custom = get_term_meta( (int) $obj->term_id, TermMeta::META_DESC, true );
				if ( is_string( $custom ) && '' !== trim( $custom ) ) {
					return Options::summarize( $custom, 160 );
				}
				$term_desc = (string) term_description( (int) $obj->term_id );
				if ( '' !== trim( $term_desc ) ) {
					return Options::summarize( $term_desc, 160 );
				}
			}
		}
		// Home / fallback: the site tagline.
		return Options::summarize( (string) get_bloginfo( 'description' ), 160 );
	}

	/** The canonical URL for the current context. */
	public function canonical_url(): string {
		if ( is_404() || is_search() ) {
			return '';
		}
		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			$link = $id > 0 ? wp_get_canonical_url( $id ) : '';
			return $link ? (string) $link : '';
		}
		$link = '';
		if ( is_front_page() || is_home() ) {
			$link = is_home() && get_option( 'page_for_posts' )
				? get_permalink( (int) get_option( 'page_for_posts' ) ) : home_url( '/' );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$obj = get_queried_object();
			if ( $obj && isset( $obj->term_id ) ) {
				$link = get_term_link( $obj );
			}
		} elseif ( is_author() ) {
			$link = get_author_posts_url( (int) get_queried_object_id() );
		} elseif ( is_post_type_archive() ) {
			$obj = get_queried_object();
			$link = $obj && isset( $obj->name ) ? get_post_type_archive_link( $obj->name ) : '';
		} elseif ( is_day() ) {
			$link = get_day_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ), (int) get_query_var( 'day' ) );
		} elseif ( is_month() ) {
			$link = get_month_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ) );
		} elseif ( is_year() ) {
			$link = get_year_link( (int) get_query_var( 'year' ) );
		}
		if ( ! $link || is_wp_error( $link ) ) {
			return '';
		}
		$page = (int) get_query_var( 'paged' );
		if ( $page > 1 ) {
			global $wp_rewrite;
			$link = ! get_option( 'permalink_structure' ) || false !== strpos( $link, '?' )
				? add_query_arg( 'paged', $page, $link )
				: trailingslashit( $link ) . user_trailingslashit( $wp_rewrite->pagination_base . '/' . $page, 'paged' );
		}
		return (string) $link;
	}

	/**
	 * Whether the current request should be noindex: either the per-post checkbox
	 * is set, or an archive context is disabled via the settings-driven rules.
	 */
	public function is_noindex(): bool {
		// Per-post flag.
		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			if ( $id > 0 && '1' === (string) get_post_meta( $id, MetaBox::META_NOINDEX, true ) ) {
				return true;
			}
		}

		// Settings-driven archive rules (pure decision, unit-tested).
		$ctx = array(
			'is_search' => is_search(),
			'is_paged'  => $this->current_page() > 1,
			'is_author' => is_author(),
			'is_date'   => is_date(),
		);
		return Options::archive_noindex( $ctx, $this->options->all() );
	}
}
