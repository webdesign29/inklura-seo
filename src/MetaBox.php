<?php
/**
 * Per-post "Inklura SEO" meta box: SEO title, meta description and a noindex
 * checkbox, saved to postmeta with a nonce + capability check.
 *
 * @package Inklura\SEO
 */

namespace Inklura\SEO;

defined( 'ABSPATH' ) || exit;

class MetaBox {

	const META_TITLE    = '_inklura_seo_title';
	const META_DESC     = '_inklura_seo_desc';
	const META_NOINDEX  = '_inklura_seo_noindex';
	const META_OG_IMAGE = '_inklura_seo_og_image';

	const NONCE_ACTION = 'inklura_seo_save_meta';
	const NONCE_NAME   = 'inklura_seo_meta_nonce';

	/** @var Options */
	private $options;

	/** @var Plugin */
	private $plugin;

	public function __construct( Options $options, Plugin $plugin ) {
		$this->options = $options;
		$this->plugin  = $plugin;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );

		// Register the meta keys so they're cleaned up / known to core.
		$this->register_meta();
	}

	private function register_meta(): void {
		$args = array(
			'show_in_rest'  => false,
			'single'        => true,
			'type'          => 'string',
			'auth_callback' => static function () {
				return current_user_can( 'edit_posts' );
			},
		);
		foreach ( array( self::META_TITLE, self::META_DESC, self::META_NOINDEX, self::META_OG_IMAGE ) as $key ) {
			register_post_meta( '', $key, $args );
		}
	}

	/** Add the meta box to every public post type that supports the editor. */
	public function add(): void {
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $type ) {
			add_meta_box(
				'inklura_seo_metabox',
				'Inklura SEO',
				array( $this, 'render' ),
				$type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Render the meta box fields.
	 *
	 * @param \WP_Post $post
	 */
	public function render( $post ): void {
		$id       = (int) $post->ID;
		$title    = (string) get_post_meta( $id, self::META_TITLE, true );
		$desc     = (string) get_post_meta( $id, self::META_DESC, true );
		$noindex  = '1' === (string) get_post_meta( $id, self::META_NOINDEX, true );
		$og_image = (string) get_post_meta( $id, self::META_OG_IMAGE, true );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<p><label for="inklura_seo_title"><strong>' . esc_html__( 'SEO title', 'inklura-seo' ) . '</strong></label><br />';
		echo '<input type="text" id="inklura_seo_title" name="inklura_seo_title" class="widefat" value="' . esc_attr( $title ) . '" ';
		echo 'placeholder="' . esc_attr( get_the_title( $id ) ) . '" />';
		echo '<span class="description">' . esc_html__( 'Overrides the <title> for this entry. Leave blank to use the title format.', 'inklura-seo' ) . '</span></p>';

		echo '<p><label for="inklura_seo_desc"><strong>' . esc_html__( 'Meta description', 'inklura-seo' ) . '</strong></label><br />';
		echo '<textarea id="inklura_seo_desc" name="inklura_seo_desc" class="widefat" rows="3" maxlength="320">' . esc_textarea( $desc ) . '</textarea>';
		echo '<span class="description">' . esc_html__( 'Up to ~160 characters. Leave blank to derive from the excerpt/content.', 'inklura-seo' ) . '</span></p>';

		echo '<p><label for="inklura_seo_og_image"><strong>' . esc_html__( 'Social image (Open Graph) URL', 'inklura-seo' ) . '</strong></label><br />';
		echo '<input type="url" id="inklura_seo_og_image" name="inklura_seo_og_image" class="widefat" value="' . esc_attr( $og_image ) . '" ';
		echo 'placeholder="https://example.com/social.png" />';
		echo '<span class="description">' . esc_html__( 'Overrides the share image for this entry. Falls back to the featured image, then the site default.', 'inklura-seo' ) . '</span></p>';

		echo '<p><label for="inklura_seo_noindex"><input type="checkbox" id="inklura_seo_noindex" name="inklura_seo_noindex" value="1" ' . checked( $noindex, true, false ) . ' /> ';
		echo esc_html__( 'Discourage search engines from indexing this entry (noindex, nofollow).', 'inklura-seo' ) . '</label></p>';
	}

	/**
	 * Persist the fields. Nonce + capability checked; skips autosave/revisions.
	 *
	 * @param int      $post_id
	 * @param \WP_Post $post
	 */
	public function save( $post_id, $post ): void {
		// Bail on autosave/revision/bulk edits.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Nonce.
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		// Capability.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// SEO title.
		$title = isset( $_POST['inklura_seo_title'] )
			? sanitize_text_field( wp_unslash( $_POST['inklura_seo_title'] ) )
			: '';
		$this->save_or_delete( $post_id, self::META_TITLE, $title );

		// Meta description.
		$desc = isset( $_POST['inklura_seo_desc'] )
			? sanitize_textarea_field( wp_unslash( $_POST['inklura_seo_desc'] ) )
			: '';
		$this->save_or_delete( $post_id, self::META_DESC, $desc );

		// OG image override (a URL).
		$og_image = isset( $_POST['inklura_seo_og_image'] ) && is_string( $_POST['inklura_seo_og_image'] )
			? esc_url_raw( wp_unslash( $_POST['inklura_seo_og_image'] ) )
			: '';
		$this->save_or_delete( $post_id, self::META_OG_IMAGE, $og_image );

		// Noindex.
		$noindex = ! empty( $_POST['inklura_seo_noindex'] ) ? '1' : '';
		$this->save_or_delete( $post_id, self::META_NOINDEX, $noindex );
	}

	/**
	 * Update the meta when non-empty, or delete it when blank (keep postmeta tidy).
	 *
	 * @param int    $post_id
	 * @param string $key
	 * @param string $value
	 */
	private function save_or_delete( int $post_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
}
