<?php
/**
 * JSON-LD structured data.
 *
 * WebSite schema on the home page; Article schema on singular posts.
 *
 * @package Inklura\SEO
 */

namespace Inklura\SEO;

defined( 'ABSPATH' ) || exit;

class Schema {

	/** @var Options */
	private $options;

	/** @var Plugin */
	private $plugin;

	public function __construct( Options $options, Plugin $plugin ) {
		$this->options = $options;
		$this->plugin  = $plugin;
	}

	public function register(): void {
		add_action( 'wp_head', array( $this, 'render' ), 5 );
	}

	public function render(): void {
		$graph = $this->build();
		if ( empty( $graph ) ) {
			return;
		}
		$doc = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
		echo '<script type="application/ld+json">'
			. wp_json_encode( $doc )
			. '</script>' . "\n";
	}

	/**
	 * Build the @graph node list for the current context.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function build(): array {
		$nodes = array();

		if ( is_front_page() || is_home() ) {
			$nodes[] = array(
				'@type'       => 'WebSite',
				'@id'         => trailingslashit( (string) home_url( '/' ) ) . '#website',
				'url'         => (string) home_url( '/' ),
				'name'        => (string) get_bloginfo( 'name' ),
				'description' => (string) get_bloginfo( 'description' ),
				'inLanguage'  => $this->language(),
			);
			return $nodes;
		}

		if ( is_singular( 'post' ) ) {
			$id = (int) get_queried_object_id();
			if ( $id <= 0 ) {
				return $nodes;
			}
			$post = get_post( $id );
			if ( ! $post ) {
				return $nodes;
			}

			$frontend = $this->plugin->module( 'frontend' );
			$desc     = $frontend instanceof Frontend ? $frontend->description() : '';

			$article = array(
				'@type'         => 'Article',
				'@id'           => (string) get_permalink( $id ) . '#article',
				'mainEntityOfPage' => (string) get_permalink( $id ),
				'headline'      => (string) get_the_title( $id ),
				'datePublished' => (string) get_post_time( 'c', true, $id ),
				'dateModified'  => (string) get_post_modified_time( 'c', true, $id ),
				'inLanguage'    => $this->language(),
				'author'        => array(
					'@type' => 'Person',
					'name'  => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
				),
				'publisher'     => array(
					'@type' => 'Organization',
					'name'  => (string) get_bloginfo( 'name' ),
					'url'   => (string) home_url( '/' ),
				),
			);
			if ( '' !== $desc ) {
				$article['description'] = $desc;
			}
			if ( has_post_thumbnail( $id ) ) {
				$img = get_the_post_thumbnail_url( $id, 'full' );
				if ( $img ) {
					$article['image'] = (string) $img;
				}
			}
			$nodes[] = $article;
		}

		return $nodes;
	}

	private function language(): string {
		$locale = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'language' ) : '';
		return '' !== $locale ? $locale : 'en-US';
	}
}
