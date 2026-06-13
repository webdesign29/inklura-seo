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
			'title_separator' => '-',
			'title_format'    => '%title% %sep% %sitename%',
			'default_og_image' => '',
			'enable_titles'   => 1,
			'enable_og'       => 1,
			'enable_sitemap'  => 1,
			'enable_schema'   => 1,
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

		if ( $max <= 0 || mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$cut = mb_substr( $text, 0, $max );
		// Back up to the last space so we don't cut a word in half.
		$last_space = mb_strrpos( $cut, ' ' );
		if ( false !== $last_space && $last_space > 0 ) {
			$cut = mb_substr( $cut, 0, $last_space );
		}
		return rtrim( $cut ) . '…';
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
