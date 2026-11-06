<?php
/**
 * Settings access + a few pure helpers.
 *
 * Settings live in the per-blog `inklura_seo_settings` option (so each site in a
 * multisite network has its own config). A network-admin defaults page stores
 * `inklura_seo_network_defaults` as a *site option*; per-blog values win, and
 * any key a blog hasn't set falls back to the network default, then to the
 * hard-coded defaults below.
 *
 * The string-building helpers (title formatting, truncation) are intentionally
 * dependency-free so they can be unit-tested without a WordPress runtime.
 *
 * @package Inklura\SEO
 */

namespace Inklura\SEO;

defined( 'ABSPATH' ) || exit;

class Options {

	const OPTION         = 'inklura_seo_settings';
	const NETWORK_OPTION = 'inklura_seo_network_defaults';

	/** @var array<string,mixed>|null Per-request cache of the merged settings. */
	private $cache = null;

	/**
	 * Hard-coded defaults. Every settable key appears here.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'title_separator'   => '-',
			'title_format'      => '%title% %sep% %sitename%',
			'default_og_image'  => '',
			'enable_titles'     => 1,
			'enable_og'         => 1,
			'enable_sitemap'    => 1,
			'enable_schema'     => 1,
			// Organization / Person schema on the home page.
			'home_schema_type'  => 'organization', // 'organization' | 'person' | 'none'
			'home_schema_name'  => '',             // falls back to the site name
			'home_schema_logo'  => '',             // logo / image URL
			// Social.
			'twitter_site'      => '',             // @handle for twitter:site
			// Breadcrumbs.
			'enable_breadcrumbs' => 1,             // BreadcrumbList JSON-LD + trail
			'breadcrumb_home_label' => '', // empty uses the current locale's Home label
			// Sitemap tuning.
			'sitemap_max_urls'  => 1000,           // split into an index above this
			'sitemap_taxonomies' => 1,             // include public taxonomy archives
			'sitemap_authors'   => 1,              // include author archives
			'sitemap_images'    => 1,              // include <image:image> for featured
			// Robots / noindex controls.
			'noindex_search'    => 1,              // noindex search-result pages
			'noindex_paged'     => 0,              // noindex paginated (page 2+) archives
			'noindex_author'    => 0,              // noindex author archives
			'noindex_date'      => 0,              // noindex date archives
		);
	}

	/** Allowed separator glyphs (label => glyph). */
	public static function separators(): array {
		return array(
			'-'   => '-',
			'ndash' => '–',
			'mdash' => '—',
			'middot' => '·',
			'bull'  => '•',
			'star'  => '*',
			'pipe'  => '|',
			'tilde' => '~',
			'raquo' => '»',
			'gt'    => '>',
		);
	}

	/**
	 * The effective, merged settings for the current blog:
	 * defaults ⊕ network defaults ⊕ per-blog option.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$merged = self::defaults();

		// Network defaults (multisite only) override the hard-coded defaults.
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$net = get_site_option( self::NETWORK_OPTION, array() );
			if ( is_array( $net ) ) {
				$merged = array_merge( $merged, self::filter_known( $net ) );
			}
		}

		// Per-blog settings win over everything.
		$blog = get_option( self::OPTION, array() );
		if ( is_array( $blog ) ) {
			$merged = array_merge( $merged, self::filter_known( $blog ) );
		}

		$this->cache = $merged;
		return $merged;
	}

	/**
	 * One setting value with a fallback.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Fallback if absent.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/** Is a module enabled? */
	public function module_enabled( string $module ): bool {
		return (bool) $this->get( 'enable_' . $module, 1 );
	}

	/** Drop the per-request cache (call after a save or a blog switch). */
	public function flush_cache(): void {
		$this->cache = null;
	}

	/**
	 * Keep only keys we recognise, so a stored option can't smuggle junk into
	 * the merged settings.
	 *
	 * @param array<string,mixed> $in
	 * @return array<string,mixed>
	 */
	private static function filter_known( array $in ): array {
		return array_intersect_key( $in, self::defaults() );
	}

	// -----------------------------------------------------------------
	// Pure helpers (WP-free; unit-tested)
	// -----------------------------------------------------------------

	/**
	 * Build a document title from a format string.
	 *
	 * Tokens: %title% (the page/post title), %sitename%, %tagline%, %sep%.
	 * Empty tokens are dropped and any resulting double separators / dangling
	 * separators are cleaned up, so e.g. a blank title never leaves "- Site".
	 *
	 * @param string $format    e.g. "%title% %sep% %sitename%".
	 * @param string $title     The page title.
	 * @param string $sitename  The site name.
	 * @param string $separator The separator glyph.
	 * @param string $tagline   The site tagline (optional).
	 * @return string
	 */
	public static function format_title( string $format, string $title, string $sitename, string $separator, string $tagline = '' ): string {
		$sep = '' !== trim( $separator ) ? trim( $separator ) : '-';

		$out = strtr(
			$format,
			array(
				'%title%'    => $title,
				'%sitename%' => $sitename,
				'%tagline%'  => $tagline,
				'%sep%'      => $sep,
			)
		);

		// Collapse whitespace.
		$out = preg_replace( '/\s+/', ' ', $out );
		$out = trim( $out );

		// Remove a separator left dangling at either end (e.g. empty %title%).
		$sep_q = preg_quote( $sep, '/' );
		$out   = preg_replace( '/^(?:\s*' . $sep_q . '\s*)+/', '', $out );
		$out   = preg_replace( '/(?:\s*' . $sep_q . '\s*)+$/', '', $out );

		// Collapse a doubled separator left by an empty middle token.
		$out = preg_replace( '/(?:\s*' . $sep_q . '\s*){2,}/', ' ' . $sep . ' ', $out );

		return trim( $out );
	}

	/**
	 * Turn arbitrary text into a clean, length-capped meta-description string:
	 * strip tags + shortcodes-ish brackets, collapse whitespace, truncate on a
	 * word boundary, append an ellipsis when cut.
	 *
	 * @param string $text Raw text (may contain HTML).
	 * @param int    $max  Max length in characters.
	 * @return string
	 */
	public static function summarize( string $text, int $max = 160 ): string {
		$text = preg_replace( '/\[[^\]]*\]/', '', $text ); // crude shortcode strip
		$text = wp_strip_all_tags_safe( $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		if ( $max <= 0 ) {
			return $text;
		}

		// WordPress does not require mbstring. Split only the prefix we need,
		// retaining the remaining text in the last element rather than allocating
		// one array entry for every character in a potentially large post.
		$chars = preg_split( '//u', $text, $max + 1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			return ''; // Invalid UTF-8 cannot form a useful metadata value.
		}
		if ( count( $chars ) <= $max ) {
			return $text;
		}
		$cut = implode( '', array_slice( $chars, 0, $max - 1 ) );
		// Back up to the last space so we don't cut a word in half.
		$last_space = strrpos( $cut, ' ' );
		if ( false !== $last_space && $last_space > 0 ) {
			$cut = substr( $cut, 0, $last_space );
		}
		return rtrim( $cut ) . '…';
	}

	/**
	 * Build a paginated document title.
	 *
	 * Substitutes %page% with the configured page suffix when on page 2+, and
	 * drops the token entirely on page 1. If the format has no %page% token but
	 * we're on a paginated view, the suffix is appended with the separator so it
	 * still reads e.g. "Title - Site - Page 2".
	 *
	 * @param string $format    Title format (may contain %page%).
	 * @param string $title     The page title.
	 * @param string $sitename  The site name.
	 * @param string $separator The separator glyph.
	 * @param string $tagline   The site tagline (optional).
	 * @param int    $page      Current page number (1 = first / unpaged).
	 * @param string $page_word The localized word for "Page" (default "Page").
	 * @return string
	 */
	public static function format_title_paged( string $format, string $title, string $sitename, string $separator, string $tagline = '', int $page = 1, string $page_word = 'Page' ): string {
		$sep      = '' !== trim( $separator ) ? trim( $separator ) : '-';
		$has_page = false !== strpos( $format, '%page%' );
		$suffix   = $page > 1 ? trim( $page_word . ' ' . $page ) : '';

		// If the format declares %page%, substitute it (blank on page 1) and let
		// the standard separator-cleanup collapse any gap left behind.
		if ( $has_page ) {
			$work = str_replace( '%page%', $suffix, $format );
			return self::format_title( $work, $title, $sitename, $sep, $tagline );
		}

		// No explicit token: format normally, then append the page suffix.
		$base = self::format_title( $format, $title, $sitename, $sep, $tagline );
		if ( '' === $suffix ) {
			return $base;
		}
		if ( '' === $base ) {
			return $suffix;
		}
		return $base . ' ' . $sep . ' ' . $suffix;
	}

	/**
	 * Resolve the Open Graph image via a fallback chain:
	 *   per-post OG override → featured image → site default.
	 * Returns the first non-empty, trimmed URL, or '' if none set.
	 *
	 * @param string $post_override The per-post OG image URL ('' if none).
	 * @param string $featured      The featured-image URL ('' if none).
	 * @param string $default       The site default OG image URL ('' if none).
	 * @return string
	 */
	public static function og_image_chain( string $post_override, string $featured, string $default ): string {
		foreach ( array( $post_override, $featured, $default ) as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Pure noindex decision for archive/list contexts, driven by settings.
	 *
	 * Singular noindex (the per-post checkbox) is handled separately; this covers
	 * the settings-driven archive rules so the logic is unit-testable.
	 *
	 * @param array<string,bool> $ctx Context booleans: is_search, is_paged,
	 *                                 is_author, is_date.
	 * @param array<string,mixed> $opts Effective settings (noindex_* keys).
	 * @return bool True if the context should be noindex.
	 */
	public static function archive_noindex( array $ctx, array $opts ): bool {
		$flag = static function ( $arr, $key ) {
			return ! empty( $arr[ $key ] );
		};

		if ( $flag( $ctx, 'is_search' ) && $flag( $opts, 'noindex_search' ) ) {
			return true;
		}
		if ( $flag( $ctx, 'is_paged' ) && $flag( $opts, 'noindex_paged' ) ) {
			return true;
		}
		if ( $flag( $ctx, 'is_author' ) && $flag( $opts, 'noindex_author' ) ) {
			return true;
		}
		if ( $flag( $ctx, 'is_date' ) && $flag( $opts, 'noindex_date' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Split a flat list of sitemap entries into pages of at most $per chunks.
	 * Pure helper so the index/sub-sitemap splitting is unit-testable.
	 *
	 * @param array<int,mixed> $entries Flat entry list.
	 * @param int              $per     Max entries per chunk (clamped to >= 1).
	 * @return array<int,array<int,mixed>> Re-indexed chunks (1 element if empty).
	 */
	public static function chunk_entries( array $entries, int $per ): array {
		$per = $per > 0 ? $per : 1;
		if ( count( $entries ) <= $per ) {
			return array( array_values( $entries ) );
		}
		$chunks = array_chunk( array_values( $entries ), $per );
		return $chunks;
	}
}

/**
 * tag-strip that works both inside WordPress (uses wp_strip_all_tags) and in the
 * WP-free unit-test harness (falls back to wp_strip_all_tags being stubbed, or
 * to a plain strip_tags). Declared in the namespace's global function table so
 * the pure summarize() helper above stays testable.
 */
if ( ! function_exists( __NAMESPACE__ . '\\wp_strip_all_tags_safe' ) ) {
	/**
	 * @param string $text
	 * @return string
	 */
	function wp_strip_all_tags_safe( $text ) {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return wp_strip_all_tags( $text );
		}
		return trim( preg_replace( '/<[^>]*>/', ' ', (string) $text ) );
	}
}
