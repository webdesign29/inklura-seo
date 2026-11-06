<?php
/**
 * Network-admin defaults page (multisite). Stores `inklura_seo_network_defaults`
 * as a *site option*; individual blogs inherit any key they haven't overridden
 * in their own per-blog settings (see Options::all()).
 *
 * @package Inklura\SEO
 */

namespace Inklura\SEO;

defined( 'ABSPATH' ) || exit;

class Network {

	const PAGE         = 'inklura-seo-network';
	const NONCE_ACTION = 'inklura_seo_network_save';
	const NONCE_NAME   = 'inklura_seo_network_nonce';

	/** @var Options */
	private $options;

	/** @var Plugin */
	private $plugin;

	public function __construct( Options $options, Plugin $plugin ) {
		$this->options = $options;
		$this->plugin  = $plugin;
	}

	public function register(): void {
		add_action( 'network_admin_menu', array( $this, 'menu' ) );
		add_action( 'network_admin_edit_inklura_seo_network', array( $this, 'save' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'settings.php',
			'Inklura SEO',
			'Inklura SEO',
			'manage_network_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/** Handle the network settings save (posts to network_admin_edit_*). */
	public function save(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'inklura-seo' ), 403 );
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			wp_die( esc_html__( 'Missing nonce', 'inklura-seo' ), 400 );
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid nonce', 'inklura-seo' ), 400 );
		}

		$in  = isset( $_POST['inklura_seo'] ) && is_array( $_POST['inklura_seo'] )
			? map_deep( wp_unslash( $_POST['inklura_seo'] ), 'sanitize_text_field' )
			: array();
		$in = array_filter( $in, 'is_scalar' );
		$out = array();

		$sep_keys               = array_keys( Options::separators() );
		$sep                    = isset( $in['title_separator'] ) ? (string) $in['title_separator'] : '-';
		$out['title_separator'] = in_array( $sep, $sep_keys, true ) ? $sep : '-';

		$format = isset( $in['title_format'] ) ? sanitize_text_field( $in['title_format'] ) : '';
		if ( '' === $format || ( false === strpos( $format, '%title%' ) && false === strpos( $format, '%sitename%' ) ) ) {
			$format = '%title% %sep% %sitename%';
		}
		$out['title_format'] = $format;

		$out['default_og_image'] = isset( $in['default_og_image'] ) ? esc_url_raw( trim( (string) $in['default_og_image'] ) ) : '';

		$out['twitter_site'] = OpenGraph::normalize_handle( isset( $in['twitter_site'] ) ? (string) $in['twitter_site'] : '' );

		$schema_type             = isset( $in['home_schema_type'] ) ? (string) $in['home_schema_type'] : 'organization';
		$out['home_schema_type'] = in_array( $schema_type, array( 'organization', 'person', 'none' ), true ) ? $schema_type : 'organization';
		$out['home_schema_name'] = isset( $in['home_schema_name'] ) ? sanitize_text_field( $in['home_schema_name'] ) : '';
		$out['home_schema_logo'] = isset( $in['home_schema_logo'] ) ? esc_url_raw( trim( (string) $in['home_schema_logo'] ) ) : '';

		$home_label                   = isset( $in['breadcrumb_home_label'] ) ? sanitize_text_field( $in['breadcrumb_home_label'] ) : '';
		$out['breadcrumb_home_label'] = $home_label;

		$max = isset( $in['sitemap_max_urls'] ) ? (int) $in['sitemap_max_urls'] : 1000;
		if ( $max < 1 ) {
			$max = 1;
		}
		if ( $max > 50000 ) {
			$max = 50000;
		}
		$out['sitemap_max_urls'] = $max;

		foreach ( array(
			'enable_titles',
			'enable_og',
			'enable_sitemap',
			'enable_schema',
			'enable_breadcrumbs',
			'sitemap_taxonomies',
			'sitemap_authors',
			'sitemap_images',
			'noindex_search',
			'noindex_paged',
			'noindex_author',
			'noindex_date',
		) as $flag ) {
			$out[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1;
		}

		update_site_option( Options::NETWORK_OPTION, $out );
		$this->options->flush_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE,
					'updated' => 'true',
				),
				network_admin_url( 'settings.php' )
			)
		);
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		$net = get_site_option( Options::NETWORK_OPTION, array() );
		$net = is_array( $net ) ? array_merge( Options::defaults(), $net ) : Options::defaults();
		$g   = function ( $k, $d = '' ) use ( $net ) {
			return array_key_exists( $k, $net ) ? $net[ $k ] : $d;
		};

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Inklura SEO — Network defaults', 'inklura-seo' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'These defaults apply to every site that has not overridden the corresponding setting on its own Inklura SEO page.', 'inklura-seo' ) . '</p>';

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Network defaults saved.', 'inklura-seo' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( network_admin_url( 'edit.php?action=inklura_seo_network' ) ) . '" style="max-width:780px">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<table class="form-table" role="presentation"><tbody>';

		// Separator.
		$sep_field = '<select name="inklura_seo[title_separator]">';
		foreach ( Options::separators() as $key => $glyph ) {
			$sep_field .= sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $key ),
				selected( (string) $g( 'title_separator', '-' ), (string) $key, false ),
				esc_html( $glyph )
			);
		}
		$sep_field .= '</select>';
		$this->row( __( 'Title separator', 'inklura-seo' ), $sep_field );

		$this->row(
			__( 'Title format', 'inklura-seo' ),
			'<input type="text" name="inklura_seo[title_format]" class="large-text code" value="' . esc_attr( (string) $g( 'title_format', '%title% %sep% %sitename%' ) ) . '" />'
		);
		$this->row(
			__( 'Default OG image URL', 'inklura-seo' ),
			'<input type="text" name="inklura_seo[default_og_image]" class="large-text code" value="' . esc_attr( (string) $g( 'default_og_image', '' ) ) . '" />'
		);
		$this->row(
			__( 'Twitter / X @site handle', 'inklura-seo' ),
			'<input type="text" name="inklura_seo[twitter_site]" class="regular-text" value="' . esc_attr( (string) $g( 'twitter_site', '' ) ) . '" placeholder="@yoursite" />'
		);

		// Home-page entity (schema).
		$schema_choices = array(
			'organization' => __( 'Organization', 'inklura-seo' ),
			'person'       => __( 'Person', 'inklura-seo' ),
			'none'         => __( 'None', 'inklura-seo' ),
		);
		$schema_field = '<select name="inklura_seo[home_schema_type]">';
		foreach ( $schema_choices as $key => $label ) {
			$schema_field .= sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( (string) $g( 'home_schema_type', 'organization' ), $key, false ),
				esc_html( $label )
			);
		}
		$schema_field .= '</select>';
		$this->row( __( 'Home entity type', 'inklura-seo' ), $schema_field );
		$this->row(
			__( 'Home entity name', 'inklura-seo' ),
			'<input type="text" name="inklura_seo[home_schema_name]" class="regular-text" value="' . esc_attr( (string) $g( 'home_schema_name', '' ) ) . '" />'
		);
		$this->row(
			__( 'Home entity logo / image URL', 'inklura-seo' ),
			'<input type="text" name="inklura_seo[home_schema_logo]" class="large-text code" value="' . esc_attr( (string) $g( 'home_schema_logo', '' ) ) . '" />'
		);

		// Breadcrumbs.
		$this->row(
			__( 'Home crumb label', 'inklura-seo' ),
			'<input type="text" name="inklura_seo[breadcrumb_home_label]" class="regular-text" value="' . esc_attr( (string) $g( 'breadcrumb_home_label', '' ) ) . '" placeholder="' . esc_attr__( 'Home', 'inklura-seo' ) . '" />'
		);

		// Sitemap URLs per page.
		$this->row(
			__( 'URLs per sitemap', 'inklura-seo' ),
			'<input type="number" min="1" max="50000" name="inklura_seo[sitemap_max_urls]" class="small-text" value="' . esc_attr( (string) (int) $g( 'sitemap_max_urls', 1000 ) ) . '" />'
		);

		foreach ( array(
			'enable_titles'      => __( 'Titles & meta', 'inklura-seo' ),
			'enable_og'          => __( 'Open Graph / Twitter', 'inklura-seo' ),
			'enable_sitemap'     => __( 'XML sitemap', 'inklura-seo' ),
			'enable_schema'      => __( 'JSON-LD schema', 'inklura-seo' ),
			'enable_breadcrumbs' => __( 'Breadcrumbs', 'inklura-seo' ),
			'sitemap_taxonomies' => __( 'Sitemap: taxonomy archives', 'inklura-seo' ),
			'sitemap_authors'    => __( 'Sitemap: author archives', 'inklura-seo' ),
			'sitemap_images'     => __( 'Sitemap: featured images', 'inklura-seo' ),
			'noindex_search'     => __( 'noindex search results', 'inklura-seo' ),
			'noindex_paged'      => __( 'noindex paginated pages', 'inklura-seo' ),
			'noindex_author'     => __( 'noindex author archives', 'inklura-seo' ),
			'noindex_date'       => __( 'noindex date archives', 'inklura-seo' ),
		) as $flag => $label ) {
			// Default-on flags vs default-off flags for the checkbox state.
			$default_on = ! in_array( $flag, array( 'noindex_paged', 'noindex_author', 'noindex_date' ), true );
			$field      = sprintf(
				'<label><input type="checkbox" name="inklura_seo[%s]" value="1" %s /> %s</label>',
				esc_attr( $flag ),
				checked( (bool) $g( $flag, $default_on ? 1 : 0 ), true, false ),
				esc_html__( 'Enabled by default', 'inklura-seo' )
			);
			$this->row( $label, $field );
		}

		echo '</tbody></table>';
		submit_button( __( 'Save network defaults', 'inklura-seo' ) );
		echo '</form></div>';
	}

	private function row( string $label, string $field ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
