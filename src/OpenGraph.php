<?php
/**
 * Open Graph + Twitter Card <head> tags.
 *
 * og:title / og:description / og:type / og:url / og:image with width + height,
 * plus the matching twitter:card set and an optional twitter:site handle.
 *
 * The OG image follows a fallback chain: a per-post OG override → the featured
 * image → the configured default OG image.
 *
 * @package Inklura\SEO
 */

namespace Inklura\SEO;

defined( 'ABSPATH' ) || exit;

class OpenGraph {

	/** @var Options */
	private $options;

	/** @var Plugin */
	private $plugin;

	public function __construct( Options $options, Plugin $plugin ) {
		$this->options = $options;
		$this->plugin  = $plugin;
	}

	public function register(): void {
		add_action( 'wp_head', array( $this, 'render' ), 2 );
	}

	public function render(): void {
		$data = $this->build();

		$og = array(
			'og:locale'      => $data['locale'],
			'og:type'        => $data['type'],
			'og:title'       => $data['title'],
			'og:description' => $data['description'],
			'og:url'         => $data['url'],
			'og:site_name'   => $data['site_name'],
		);
		foreach ( $og as $prop => $val ) {
			if ( '' === (string) $val ) {
				continue;
			}
			echo '<meta property="' . esc_attr( $prop ) . '" content="' . esc_attr( $val ) . '" />' . "\n";
		}

		if ( '' !== $data['image'] ) {
			echo '<meta property="og:image" content="' . esc_url( $data['image'] ) . '" />' . "\n";
			if ( $data['image_w'] > 0 && $data['image_h'] > 0 ) {
				echo '<meta property="og:image:width" content="' . esc_attr( (string) $data['image_w'] ) . '" />' . "\n";
				echo '<meta property="og:image:height" content="' . esc_attr( (string) $data['image_h'] ) . '" />' . "\n";
			}
		}

		// Twitter Card.
		$tw = array(
			'twitter:card'        => '' !== $data['image'] ? 'summary_large_image' : 'summary',
			'twitter:title'       => $data['title'],
			'twitter:description' => $data['description'],
		);
		if ( '' !== $data['twitter_site'] ) {
			$tw['twitter:site'] = $data['twitter_site'];
		}
		foreach ( $tw as $name => $val ) {
			if ( '' === (string) $val ) {
				continue;
			}
			echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $val ) . '" />' . "\n";
		}
		if ( '' !== $data['image'] ) {
			echo '<meta name="twitter:image" content="' . esc_url( $data['image'] ) . '" />' . "\n";
		}
	}

	/**
	 * Assemble the OG/Twitter values for the current context.
	 *
	 * @return array<string,mixed>
	 */
	private function build(): array {
		$site_name = (string) get_bloginfo( 'name' );
		$locale    = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
		$type      = 'website';
		$title     = '';
		$desc      = '';
		$url       = (string) home_url( '/' );

		$default_image = (string) $this->options->get( 'default_og_image', '' );
		$post_override = '';
		$featured      = '';
		$image_w       = 0;
		$image_h       = 0;

		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			if ( $id > 0 ) {
				$type = is_singular( 'post' ) ? 'article' : 'website';

				$custom_title = get_post_meta( $id, MetaBox::META_TITLE, true );
				$title        = ( is_string( $custom_title ) && '' !== trim( $custom_title ) )
					? trim( $custom_title )
					: (string) get_the_title( $id );

				$frontend = $this->plugin->module( 'frontend' );
				if ( $frontend instanceof Frontend ) {
					$desc = $frontend->description();
				}

				$perma = get_permalink( $id );
				if ( $perma ) {
					$url = (string) $perma;
				}

				// Per-post OG override.
				$ov = get_post_meta( $id, MetaBox::META_OG_IMAGE, true );
				if ( is_string( $ov ) && '' !== trim( $ov ) ) {
					$post_override = trim( $ov );
				}

				// Featured image (also gives us width/height).
				if ( has_post_thumbnail( $id ) ) {
					$thumb_id = (int) get_post_thumbnail_id( $id );
					$thumb    = get_the_post_thumbnail_url( $id, 'full' );
					if ( $thumb ) {
						$featured = (string) $thumb;
						$src      = $thumb_id > 0 ? wp_get_attachment_image_src( $thumb_id, 'full' ) : false;
						if ( is_array( $src ) && isset( $src[1], $src[2] ) ) {
							$image_w = (int) $src[1];
							$image_h = (int) $src[2];
						}
					}
				}
			}
		} else {
			$title = $site_name;
			$desc  = Options::summarize( (string) get_bloginfo( 'description' ), 160 );
		}

		if ( '' === $title ) {
			$title = $site_name;
		}

		// Resolve the image via the fallback chain. Width/height only apply when
		// the chosen image *is* the featured image (the others are external URLs
		// of unknown size).
		$image = Options::og_image_chain( $post_override, $featured, $default_image );
		if ( $image !== $featured ) {
			$image_w = 0;
			$image_h = 0;
		}

		$twitter_site = $this->twitter_site();

		return array(
			'locale'       => $locale,
			'type'         => $type,
			'title'        => $title,
			'description'  => $desc,
			'url'          => $url,
			'site_name'    => $site_name,
			'image'        => $image,
			'image_w'      => $image_w,
			'image_h'      => $image_h,
			'twitter_site' => $twitter_site,
		);
	}

	/** Normalize the configured Twitter handle to a leading-@ form, or ''. */
	private function twitter_site(): string {
		$raw = trim( (string) $this->options->get( 'twitter_site', '' ) );
		return self::normalize_handle( $raw );
	}

	/**
	 * Normalize a Twitter/X handle: strip a leading URL, ensure a single leading
	 * '@', drop invalid characters. Pure so it can be unit-tested.
	 *
	 * @param string $raw
	 * @return string '' when nothing usable remains.
	 */
	public static function normalize_handle( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		// Pull the handle out of a profile URL if one was pasted.
		if ( preg_match( '#(?:twitter\.com|x\.com)/@?([A-Za-z0-9_]{1,15})#i', $raw, $m ) ) {
			$raw = $m[1];
		}
		$raw = ltrim( $raw, '@' );
		$raw = preg_replace( '/[^A-Za-z0-9_]/', '', $raw );
		if ( '' === (string) $raw ) {
			return '';
		}
		return '@' . $raw;
	}
}
