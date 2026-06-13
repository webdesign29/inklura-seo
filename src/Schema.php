<?php
/**
 * JSON-LD structured data (a single @graph).
 *
 *   - Home page: WebSite + an Organization OR Person node (configurable name +
 *     logo URL in settings).
 *   - Singular posts: Article.
 *   - Any page with a multi-level trail: BreadcrumbList.
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
			$publisher = $this->home_entity();
			if ( null !== $publisher ) {
				$nodes[] = $publisher;
			}
		}

		if ( is_singular( 'post' ) ) {
			$id = (int) get_queried_object_id();
			if ( $id > 0 ) {
				$post = get_post( $id );
				if ( $post ) {
					$frontend = $this->plugin->module( 'frontend' );
					$desc     = $frontend instanceof Frontend ? $frontend->description() : '';

					$article = array(
						'@type'            => 'Article',
						'@id'              => (string) get_permalink( $id ) . '#article',
						'mainEntityOfPage' => (string) get_permalink( $id ),
						'headline'         => (string) get_the_title( $id ),
						'datePublished'    => (string) get_post_time( 'c', true, $id ),
						'dateModified'     => (string) get_post_modified_time( 'c', true, $id ),
						'inLanguage'       => $this->language(),
						'author'           => array(
							'@type' => 'Person',
							'name'  => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
						),
						'publisher'        => array(
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
			}
		}

		// BreadcrumbList: emitted alongside whatever else applies, when the
		// breadcrumbs module is active and the trail has more than one level.
		$crumbs_node = $this->breadcrumb_node();
		if ( null !== $crumbs_node ) {
			$nodes[] = $crumbs_node;
		}

		return $nodes;
	}

	/**
	 * The home-page publisher entity (Organization or Person), or null when
	 * disabled. Name falls back to the site name; logo is optional.
	 *
	 * @return array<string,mixed>|null
	 */
	private function home_entity() {
		$type = (string) $this->options->get( 'home_schema_type', 'organization' );
		if ( 'none' === $type ) {
			return null;
		}
		$schema_type = 'person' === $type ? 'Person' : 'Organization';

		$name = trim( (string) $this->options->get( 'home_schema_name', '' ) );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}

		$node = array(
			'@type' => $schema_type,
			'@id'   => trailingslashit( (string) home_url( '/' ) ) . '#' . strtolower( $schema_type ),
			'name'  => $name,
			'url'   => (string) home_url( '/' ),
		);

		$logo = trim( (string) $this->options->get( 'home_schema_logo', '' ) );
		if ( '' !== $logo ) {
			if ( 'Organization' === $schema_type ) {
				$node['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => $logo,
				);
			} else {
				$node['image'] = $logo;
			}
		}

		return $node;
	}

	/**
	 * BreadcrumbList graph node for the current request, or null when the
	 * breadcrumbs module is disabled / there is no multi-level trail.
	 *
	 * @return array<string,mixed>|null
	 */
	private function breadcrumb_node() {
		if ( ! $this->options->module_enabled( 'breadcrumbs' ) ) {
			return null;
		}
		$module = $this->plugin->module( 'breadcrumbs' );
		if ( ! ( $module instanceof Breadcrumbs ) ) {
			return null;
		}
		return Breadcrumbs::json_ld( $module->crumbs() );
	}

	private function language(): string {
		$locale = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'language' ) : '';
		return '' !== $locale ? $locale : 'en-US';
	}
}
