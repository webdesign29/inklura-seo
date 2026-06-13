<?php
/**
 * Breadcrumbs: a BreadcrumbList JSON-LD graph node plus an optional, themable
 * visible trail exposed as the `[inklura_breadcrumbs]` shortcode and the
 * \Inklura\SEO\inklura_seo_breadcrumbs() template function.
 *
 * The crumb list is derived from the current request (front page → ancestors →
 * current object / archive). The pure builders (crumb → JSON-LD, crumb → HTML)
 * live as static methods so they can be unit-tested without WordPress.
 *
 * @package Inklura\SEO
 */

namespace Inklura\SEO;

defined( 'ABSPATH' ) || exit;

class Breadcrumbs {

	/** @var Options */
	private $options;

	/** @var Plugin */
	private $plugin;

	public function __construct( Options $options, Plugin $plugin ) {
		$this->options = $options;
		$this->plugin  = $plugin;
	}

	public function register(): void {
		add_shortcode( 'inklura_breadcrumbs', array( $this, 'shortcode' ) );
	}

	/**
	 * Shortcode handler: render the visible trail for the current request.
	 *
	 * @param array<string,mixed>|string $atts
	 * @return string
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'separator' => '/',
			),
			is_array( $atts ) ? $atts : array(),
			'inklura_breadcrumbs'
		);
		return self::render_html( $this->crumbs(), (string) $atts['separator'] );
	}

	/**
	 * Build the breadcrumb trail for the current context.
	 *
	 * @return array<int,array{name:string,url:string}>
	 */
	public function crumbs(): array {
		$crumbs = array();

		$home_label = (string) $this->options->get( 'breadcrumb_home_label', 'Home' );
		if ( '' === trim( $home_label ) ) {
			$home_label = 'Home';
		}
		$crumbs[] = array(
			'name' => $home_label,
			'url'  => (string) home_url( '/' ),
		);

		if ( is_front_page() || is_home() ) {
			// Home only — the single crumb above is enough.
			return self::finalize( $crumbs );
		}

		if ( is_singular() ) {
			$id   = (int) get_queried_object_id();
			$post = $id > 0 ? get_post( $id ) : null;
			if ( $post ) {
				// Hierarchical ancestors (pages, hierarchical CPTs).
				$ancestors = get_post_ancestors( $post );
				$ancestors = array_reverse( $ancestors );
				foreach ( $ancestors as $ancestor_id ) {
					$crumbs[] = array(
						'name' => (string) get_the_title( $ancestor_id ),
						'url'  => (string) get_permalink( $ancestor_id ),
					);
				}
				$crumbs[] = array(
					'name' => (string) get_the_title( $id ),
					'url'  => (string) get_permalink( $id ),
				);
			}
			return self::finalize( $crumbs );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && isset( $term->term_id ) ) {
				$this->push_term_ancestors( $crumbs, $term );
			}
			return self::finalize( $crumbs );
		}

		if ( is_post_type_archive() ) {
			$crumbs[] = array(
				'name' => (string) post_type_archive_title( '', false ),
				'url'  => '',
			);
			return self::finalize( $crumbs );
		}

		if ( is_author() ) {
			$obj = get_queried_object();
			$crumbs[] = array(
				'name' => ( $obj && isset( $obj->display_name ) ) ? (string) $obj->display_name : '',
				'url'  => '',
			);
			return self::finalize( $crumbs );
		}

		if ( is_search() ) {
			$crumbs[] = array(
				'name' => (string) get_search_query(),
				'url'  => '',
			);
			return self::finalize( $crumbs );
		}

		if ( is_year() || is_month() || is_day() ) {
			$crumbs[] = array(
				'name' => (string) wp_strip_all_tags( (string) get_the_archive_title() ),
				'url'  => '',
			);
			return self::finalize( $crumbs );
		}

		return self::finalize( $crumbs );
	}

	/**
	 * Push a term + its hierarchical ancestors (root-first) onto the crumb list.
	 *
	 * @param array<int,array{name:string,url:string}> $crumbs
	 * @param object $term WP_Term-like object.
	 */
	private function push_term_ancestors( array &$crumbs, $term ): void {
		$chain = array();
		$cur   = $term;
		$guard = 0;
		while ( $cur && isset( $cur->term_id ) && $guard < 50 ) {
			array_unshift( $chain, $cur );
			if ( empty( $cur->parent ) ) {
				break;
			}
			$parent = get_term( (int) $cur->parent, isset( $cur->taxonomy ) ? $cur->taxonomy : '' );
			if ( ! $parent || is_wp_error( $parent ) ) {
				break;
			}
			$cur = $parent;
			++$guard;
		}
		foreach ( $chain as $node ) {
			$link     = get_term_link( $node );
			$crumbs[] = array(
				'name' => isset( $node->name ) ? (string) $node->name : '',
				'url'  => ( $link && ! is_wp_error( $link ) ) ? (string) $link : '',
			);
		}
	}

	/**
	 * Drop crumbs with an empty name (defensive) and re-index.
	 *
	 * @param array<int,array{name:string,url:string}> $crumbs
	 * @return array<int,array{name:string,url:string}>
	 */
	private static function finalize( array $crumbs ): array {
		$out = array();
		foreach ( $crumbs as $c ) {
			if ( '' !== trim( (string) ( isset( $c['name'] ) ? $c['name'] : '' ) ) ) {
				$out[] = array(
					'name' => (string) $c['name'],
					'url'  => isset( $c['url'] ) ? (string) $c['url'] : '',
				);
			}
		}
		return $out;
	}

	// -----------------------------------------------------------------
	// Pure builders (WP-free; unit-tested)
	// -----------------------------------------------------------------

	/**
	 * Build a schema.org BreadcrumbList node from a crumb list.
	 *
	 * @param array<int,array{name:string,url:string}> $crumbs
	 * @return array<string,mixed>|null Null if there are fewer than 2 crumbs.
	 */
	public static function json_ld( array $crumbs ) {
		$crumbs = array_values( $crumbs );
		if ( count( $crumbs ) < 2 ) {
			return null;
		}
		$items = array();
		$pos   = 1;
		foreach ( $crumbs as $c ) {
			$name = isset( $c['name'] ) ? (string) $c['name'] : '';
			if ( '' === $name ) {
				continue;
			}
			$item = array(
				'@type'    => 'ListItem',
				'position' => $pos,
				'name'     => $name,
			);
			$url = isset( $c['url'] ) ? trim( (string) $c['url'] ) : '';
			if ( '' !== $url ) {
				$item['item'] = $url;
			}
			$items[] = $item;
			++$pos;
		}
		if ( count( $items ) < 2 ) {
			return null;
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
	}

	/**
	 * Render the visible breadcrumb trail as escaped HTML. Pure: every dynamic
	 * value is escaped here, so the caller can echo it directly.
	 *
	 * @param array<int,array{name:string,url:string}> $crumbs
	 * @param string $separator Visual separator between crumbs.
	 * @return string '' when there is nothing meaningful to show.
	 */
	public static function render_html( array $crumbs, string $separator = '/' ): string {
		$crumbs = array_values( $crumbs );
		if ( count( $crumbs ) < 2 ) {
			return '';
		}
		$sep   = self::esc_html( $separator );
		$last  = count( $crumbs ) - 1;
		$parts = array();
		foreach ( $crumbs as $i => $c ) {
			$name = self::esc_html( isset( $c['name'] ) ? (string) $c['name'] : '' );
			$url  = isset( $c['url'] ) ? trim( (string) $c['url'] ) : '';
			if ( $i === $last || '' === $url ) {
				$parts[] = '<span class="inklura-crumb inklura-crumb--current" aria-current="page">' . $name . '</span>';
			} else {
				$parts[] = '<a class="inklura-crumb" href="' . self::esc_url( $url ) . '">' . $name . '</a>';
			}
		}
		$inner = implode( ' <span class="inklura-crumb-sep" aria-hidden="true">' . $sep . '</span> ', $parts );
		return '<nav class="inklura-breadcrumbs" aria-label="Breadcrumb">' . $inner . '</nav>';
	}

	/** esc_html that degrades to htmlspecialchars in the WP-free harness. */
	private static function esc_html( string $s ): string {
		if ( function_exists( 'esc_html' ) ) {
			return esc_html( $s );
		}
		return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	}

	/** esc_url that degrades to a conservative filter in the WP-free harness. */
	private static function esc_url( string $s ): string {
		if ( function_exists( 'esc_url' ) ) {
			return esc_url( $s );
		}
		return htmlspecialchars( filter_var( $s, FILTER_SANITIZE_URL ), ENT_QUOTES, 'UTF-8' );
	}
}

/**
 * Template tag: echo (or return) the breadcrumb trail from a theme template.
 *
 *   <?php \Inklura\SEO\inklura_seo_breadcrumbs(); ?>
 *
 * @param array<string,mixed> $args { 'separator': string, 'echo': bool }
 * @return string The HTML (also echoed unless echo=false).
 */
if ( ! function_exists( __NAMESPACE__ . '\\inklura_seo_breadcrumbs' ) ) {
	function inklura_seo_breadcrumbs( $args = array() ) {
		$args   = is_array( $args ) ? $args : array();
		$sep    = isset( $args['separator'] ) ? (string) $args['separator'] : '/';
		$do_echo = ! array_key_exists( 'echo', $args ) || ! empty( $args['echo'] );

		$module = Plugin::instance()->module( 'breadcrumbs' );
		$crumbs = ( $module instanceof Breadcrumbs ) ? $module->crumbs() : array();
		$html   = Breadcrumbs::render_html( $crumbs, $sep );

		if ( $do_echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_html() escapes every field.
		}
		return $html;
	}
}
