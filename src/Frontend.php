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
		add_theme_support( 'title-tag' );
		add_filter( 'document_title_separator', array( $this, 'title_separator' ) );
		add_filter( 'document_title_parts', array( $this, 'title_parts' ) );
		add_filter( 'pre_get_document_title', array( $this, 'pre_document_title' ) );

		// Meta description, canonical, robots.
		add_action( 'wp_head', array( $this, 'render_head' ), 1 );

		// Let WordPress emit its own robots; we add noindex via the filter.
		add_filter( 'wp_robots', array( $this, 'wp_robots' ) );
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
		$custom = $this->current_seo_title();
		if ( '' !== $custom ) {
			return $custom;
		}

		$page_title = $this->context_title();
		$sitename   = (string) get_bloginfo( 'name' );
		$tagline    = (string) get_bloginfo( 'description' );

		$format = (string) $this->options->get( 'title_format', '%title% %sep% %sitename%' );

		// On the front page with no real "page title", drop %title% so we don't
		// get a dangling separator.
		if ( ( is_front_page() || is_home() ) && '' === $page_title ) {
			$page_title = $tagline;
		}

		return Options::format_title( $format, $page_title, $sitename, $this->separator(), $tagline );
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
	 * Add noindex,nofollow to the robots directive for posts flagged noindex.
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

	// -----------------------------------------------------------------
	// Value resolution
	// -----------------------------------------------------------------

	/** The configured separator glyph. */
	private function separator(): string {
		$key  = (string) $this->options->get( 'title_separator', '-' );
		$map  = Options::separators();
		return isset( $map[ $key ] ) ? $map[ $key ] : $key;
	}

	/** Per-post SEO title override, or '' if none / not singular. */
	private function current_seo_title(): string {
		if ( ! is_singular() ) {
			return '';
		}
		$id = (int) get_queried_object_id();
		if ( $id <= 0 ) {
			return '';
		}
		$val = get_post_meta( $id, MetaBox::META_TITLE, true );
		return is_string( $val ) ? trim( $val ) : '';
	}

	/** A human title for the current context (post/page/archive/etc.). */
	private function context_title(): string {
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
		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			if ( $id > 0 ) {
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
		// Home / fallback: the site tagline.
		return Options::summarize( (string) get_bloginfo( 'description' ), 160 );
	}

	/** The canonical URL for the current context. */
	private function canonical_url(): string {
		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			$link = $id > 0 ? get_permalink( $id ) : '';
			return $link ? (string) $link : '';
		}
		if ( is_front_page() || is_home() ) {
			return (string) home_url( '/' );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$obj = get_queried_object();
			if ( $obj && isset( $obj->term_id ) ) {
				$link = get_term_link( $obj );
				if ( ! is_wp_error( $link ) ) {
					return (string) $link;
				}
			}
		}
		return '';
	}

	/** Whether the current singular object is flagged noindex. */
	public function is_noindex(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$id = (int) get_queried_object_id();
		if ( $id <= 0 ) {
			return false;
		}
		return '1' === (string) get_post_meta( $id, MetaBox::META_NOINDEX, true );
	}
}
