<?php
/**
 * Open Graph + Twitter Card <head> tags.
 *
 * og:title / og:description / og:type / og:url / og:image (from the featured
 * image, falling back to the configured default OG image) and the matching
 * twitter:card = summary_large_image set.
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
		}

		// Twitter Card.
		$tw = array(
			'twitter:card'        => '' !== $data['image'] ? 'summary_large_image' : 'summary',
			'twitter:title'       => $data['title'],
			'twitter:description' => $data['description'],
		);
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
	 * @return array<string,string>
	 */
	private function build(): array {
		$site_name = (string) get_bloginfo( 'name' );
		$locale    = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';
		$type      = 'website';
		$title     = '';
		$desc      = '';
		$url       = (string) home_url( '/' );
		$image     = (string) $this->options->get( 'default_og_image', '' );

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

				if ( has_post_thumbnail( $id ) ) {
					$thumb = get_the_post_thumbnail_url( $id, 'full' );
					if ( $thumb ) {
						$image = (string) $thumb;
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

		return array(
			'locale'      => $locale,
			'type'        => $type,
			'title'       => $title,
			'description' => $desc,
			'url'         => $url,
			'site_name'   => $site_name,
			'image'       => $image,
		);
	}
}
