<?php
/**
 * Per-term SEO meta: an "Inklura SEO" SEO title + meta description on the term
 * Add / Edit screens for every public taxonomy, saved to term meta with a nonce
 * + capability check.
 *
 * @package Inklura\SEO
 */

namespace Inklura\SEO;

defined( 'ABSPATH' ) || exit;

class TermMeta {

	const META_TITLE = '_inklura_seo_title';
	const META_DESC  = '_inklura_seo_desc';

	const NONCE_ACTION = 'inklura_seo_save_term';
	const NONCE_NAME   = 'inklura_seo_term_nonce';

	/** @var Options */
	private $options;

	/** @var Plugin */
	private $plugin;

	public function __construct( Options $options, Plugin $plugin ) {
		$this->options = $options;
		$this->plugin  = $plugin;
	}

	public function register(): void {
		// Register the fields on every public taxonomy.
		add_action( 'admin_init', array( $this, 'hook_taxonomies' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
	}

	/** Attach the add/edit form + save hooks to each public taxonomy. */
	public function hook_taxonomies(): void {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', array( $this, 'add_fields' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'edit_fields' ), 10, 2 );
			add_action( 'created_' . $taxonomy, array( $this, 'save' ) );
			add_action( 'edited_' . $taxonomy, array( $this, 'save' ) );
		}
	}

	/** Register the term-meta keys with core. */
	public function register_meta(): void {
		if ( ! function_exists( 'register_term_meta' ) ) {
			return;
		}
		$args = array(
			'show_in_rest'  => false,
			'single'        => true,
			'type'          => 'string',
			'auth_callback' => static function () {
				return current_user_can( 'manage_categories' );
			},
		);
		register_term_meta( '', self::META_TITLE, $args );
		register_term_meta( '', self::META_DESC, $args );
	}

	/**
	 * The "Add new term" form (no existing values).
	 *
	 * @param string $taxonomy
	 */
	public function add_fields( $taxonomy ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<div class="form-field term-inklura-seo-title-wrap">';
		echo '<label for="inklura_seo_term_title"><strong>' . esc_html__( 'SEO title', 'inklura-seo' ) . '</strong></label>';
		echo '<input type="text" id="inklura_seo_term_title" name="inklura_seo_term_title" value="" />';
		echo '<p>' . esc_html__( 'Overrides the document title for this term archive. Leave blank to use the title format.', 'inklura-seo' ) . '</p>';
		echo '</div>';

		echo '<div class="form-field term-inklura-seo-desc-wrap">';
		echo '<label for="inklura_seo_term_desc"><strong>' . esc_html__( 'Meta description', 'inklura-seo' ) . '</strong></label>';
		echo '<textarea id="inklura_seo_term_desc" name="inklura_seo_term_desc" rows="3" cols="40" maxlength="320"></textarea>';
		echo '<p>' . esc_html__( 'Up to ~160 characters. Leave blank to use the term description.', 'inklura-seo' ) . '</p>';
		echo '</div>';
	}

	/**
	 * The "Edit term" form (table-row layout, prefilled).
	 *
	 * @param object $term     WP_Term.
	 * @param string $taxonomy
	 */
	public function edit_fields( $term, $taxonomy ): void {
		$term_id = isset( $term->term_id ) ? (int) $term->term_id : 0;
		$title   = (string) get_term_meta( $term_id, self::META_TITLE, true );
		$desc    = (string) get_term_meta( $term_id, self::META_DESC, true );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<tr class="form-field term-inklura-seo-title-wrap">';
		echo '<th scope="row"><label for="inklura_seo_term_title">' . esc_html__( 'SEO title', 'inklura-seo' ) . '</label></th>';
		echo '<td><input type="text" id="inklura_seo_term_title" name="inklura_seo_term_title" value="' . esc_attr( $title ) . '" />';
		echo '<p class="description">' . esc_html__( 'Overrides the document title for this term archive.', 'inklura-seo' ) . '</p></td>';
		echo '</tr>';

		echo '<tr class="form-field term-inklura-seo-desc-wrap">';
		echo '<th scope="row"><label for="inklura_seo_term_desc">' . esc_html__( 'Meta description', 'inklura-seo' ) . '</label></th>';
		echo '<td><textarea id="inklura_seo_term_desc" name="inklura_seo_term_desc" rows="3" cols="50" maxlength="320">' . esc_textarea( $desc ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Up to ~160 characters. Leave blank to use the term description.', 'inklura-seo' ) . '</p></td>';
		echo '</tr>';
	}

	/**
	 * Persist the term-meta fields. Nonce + capability checked.
	 *
	 * @param int $term_id
	 */
	public function save( $term_id ): void {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$title = isset( $_POST['inklura_seo_term_title'] )
			? sanitize_text_field( wp_unslash( $_POST['inklura_seo_term_title'] ) )
			: '';
		$this->save_or_delete( $term_id, self::META_TITLE, $title );

		$desc = isset( $_POST['inklura_seo_term_desc'] )
			? sanitize_textarea_field( wp_unslash( $_POST['inklura_seo_term_desc'] ) )
			: '';
		$this->save_or_delete( $term_id, self::META_DESC, $desc );
	}

	/**
	 * Update term meta when non-empty, or delete it when blank.
	 *
	 * @param int    $term_id
	 * @param string $key
	 * @param string $value
	 */
	private function save_or_delete( int $term_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_term_meta( $term_id, $key );
		} else {
			update_term_meta( $term_id, $key, $value );
		}
	}
}
