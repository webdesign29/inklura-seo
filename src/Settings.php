<?php
/**
 * Settings page (Settings API): title separator, title format, default OG image,
 * and per-module on/off toggles. Stored per-blog in `inklura_seo_settings`.
 *
 * @package Inklura\SEO
 */

namespace Inklura\SEO;

defined( 'ABSPATH' ) || exit;

class Settings {

	const GROUP = 'inklura_seo';
	const PAGE  = 'inklura-seo';

	/** @var Options */
	private $options;

	/** @var Plugin */
	private $plugin;

	public function __construct( Options $options, Plugin $plugin ) {
		$this->options = $options;
		$this->plugin  = $plugin;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( INKLURA_SEO_FILE ),
			array( $this, 'action_links' )
		);
	}

	public function menu(): void {
		add_options_page(
			'Inklura SEO',
			'Inklura SEO',
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Add a "Settings" link on the Plugins screen row.
	 *
	 * @param array<int,string> $links
	 * @return array<int,string>
	 */
	public function action_links( $links ): array {
		$links   = is_array( $links ) ? $links : array();
		$url     = admin_url( 'options-general.php?page=' . self::PAGE );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'inklura-seo' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Validate + normalize posted settings.
	 *
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$in  = is_array( $input ) ? array_filter( $input, 'is_scalar' ) : array();
		// The Settings API already unslashes input before this callback runs.
		$out = array();

		// Separator: must be a known key.
		$sep_keys             = array_keys( Options::separators() );
		$sep                  = isset( $in['title_separator'] ) ? (string) $in['title_separator'] : '-';
		$out['title_separator'] = in_array( $sep, $sep_keys, true ) ? $sep : '-';

		// Title format: free text, but must contain at least %title% or %sitename%.
		$format = isset( $in['title_format'] ) ? sanitize_text_field( $in['title_format'] ) : '';
		if ( '' === $format || ( false === strpos( $format, '%title%' ) && false === strpos( $format, '%sitename%' ) ) ) {
			$format = '%title% %sep% %sitename%';
		}
		$out['title_format'] = $format;

		// Default OG image: a URL.
		$out['default_og_image'] = isset( $in['default_og_image'] )
			? esc_url_raw( trim( (string) $in['default_og_image'] ) )
			: '';

		// Twitter @site handle, normalized.
		$out['twitter_site'] = OpenGraph::normalize_handle(
			isset( $in['twitter_site'] ) ? (string) $in['twitter_site'] : ''
		);

		// Home-page schema entity.
		$schema_type             = isset( $in['home_schema_type'] ) ? (string) $in['home_schema_type'] : 'organization';
		$out['home_schema_type'] = in_array( $schema_type, array( 'organization', 'person', 'none' ), true ) ? $schema_type : 'organization';
		$out['home_schema_name'] = isset( $in['home_schema_name'] ) ? sanitize_text_field( $in['home_schema_name'] ) : '';
		$out['home_schema_logo'] = isset( $in['home_schema_logo'] ) ? esc_url_raw( trim( (string) $in['home_schema_logo'] ) ) : '';

		// Breadcrumbs.
		$home_label                  = isset( $in['breadcrumb_home_label'] ) ? sanitize_text_field( $in['breadcrumb_home_label'] ) : '';
		$out['breadcrumb_home_label'] = '' !== $home_label ? $home_label : 'Home';

		// Sitemap tuning.
		$max                     = isset( $in['sitemap_max_urls'] ) ? (int) $in['sitemap_max_urls'] : 1000;
		if ( $max < 1 ) {
			$max = 1;
		}
		if ( $max > 50000 ) {
			$max = 50000;
		}
		$out['sitemap_max_urls'] = $max;

		// Module toggles + boolean feature flags.
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

		$this->options->flush_cache();

		// Sitemap rewrite rules depend on the toggle; ask WP to re-flush on next load.
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			Sitemap::add_rewrite();
			flush_rewrite_rules( false );
		}

		return $out;
	}

	// -----------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$g = function ( $k, $d = '' ) {
			return $this->options->get( $k, $d );
		};

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Inklura SEO', 'inklura-seo' ) . '</h1>';

		$this->maybe_show_network_notice();

		echo '<form method="post" action="options.php" style="max-width:780px">';
		settings_fields( self::GROUP );

		echo '<h2>' . esc_html__( 'Titles', 'inklura-seo' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->row(
			__( 'Title separator', 'inklura-seo' ),
			$this->select_separator( (string) $g( 'title_separator', '-' ) )
		);
		$this->row(
			__( 'Title format', 'inklura-seo' ),
			$this->text( 'title_format', (string) $g( 'title_format', '%title% %sep% %sitename%' ), '%title% %sep% %sitename%', 'large-text code' )
			. '<p class="description">' . esc_html__( 'Tokens:', 'inklura-seo' )
			. ' <code>%title%</code> <code>%sitename%</code> <code>%tagline%</code> <code>%sep%</code> <code>%page%</code>'
			. ' &mdash; ' . esc_html__( '%page% is replaced with the page suffix on paginated views (and dropped on page 1).', 'inklura-seo' ) . '</p>'
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Social', 'inklura-seo' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row(
			__( 'Default OG image URL', 'inklura-seo' ),
			$this->text( 'default_og_image', (string) $g( 'default_og_image', '' ), 'https://example.com/social.png', 'large-text code' )
			. '<p class="description">' . esc_html__( 'Fallback share image when a post has no OG override and no featured image.', 'inklura-seo' ) . '</p>'
		);
		$this->row(
			__( 'Twitter / X @site handle', 'inklura-seo' ),
			$this->text( 'twitter_site', (string) $g( 'twitter_site', '' ), '@yoursite' )
			. '<p class="description">' . esc_html__( 'Output as twitter:site. A handle or a profile URL — both are accepted.', 'inklura-seo' ) . '</p>'
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Home page entity (schema)', 'inklura-seo' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row(
			__( 'Entity type', 'inklura-seo' ),
			$this->select_home_schema( (string) $g( 'home_schema_type', 'organization' ) )
			. '<p class="description">' . esc_html__( 'Emit an Organization or Person node on the home page (or none).', 'inklura-seo' ) . '</p>'
		);
		$this->row(
			__( 'Entity name', 'inklura-seo' ),
			$this->text( 'home_schema_name', (string) $g( 'home_schema_name', '' ), (string) get_bloginfo( 'name' ) )
			. '<p class="description">' . esc_html__( 'Leave blank to use the site name.', 'inklura-seo' ) . '</p>'
		);
		$this->row(
			__( 'Logo / image URL', 'inklura-seo' ),
			$this->text( 'home_schema_logo', (string) $g( 'home_schema_logo', '' ), 'https://example.com/logo.png', 'large-text code' )
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Breadcrumbs', 'inklura-seo' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row( __( 'Enable breadcrumbs', 'inklura-seo' ), $this->checkbox( 'enable_breadcrumbs', $g( 'enable_breadcrumbs', 1 ), __( 'BreadcrumbList structured data + the [inklura_breadcrumbs] shortcode / template tag', 'inklura-seo' ) ) );
		$this->row(
			__( 'Home crumb label', 'inklura-seo' ),
			$this->text( 'breadcrumb_home_label', (string) $g( 'breadcrumb_home_label', 'Home' ), 'Home' )
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Sitemap', 'inklura-seo' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row(
			__( 'URLs per sitemap', 'inklura-seo' ),
			sprintf(
				'<input type="number" min="1" max="50000" name="%s" value="%s" class="small-text" />',
				esc_attr( $this->name( 'sitemap_max_urls' ) ),
				esc_attr( (string) (int) $g( 'sitemap_max_urls', 1000 ) )
			)
			. '<p class="description">' . esc_html__( 'Above this, the sitemap splits into a real index of paginated sub-sitemaps.', 'inklura-seo' ) . '</p>'
		);
		$this->row( __( 'Include taxonomy archives', 'inklura-seo' ), $this->checkbox( 'sitemap_taxonomies', $g( 'sitemap_taxonomies', 1 ), __( 'Categories, tags and public custom taxonomies', 'inklura-seo' ) ) );
		$this->row( __( 'Include author archives', 'inklura-seo' ), $this->checkbox( 'sitemap_authors', $g( 'sitemap_authors', 1 ), __( 'Authors who have published content', 'inklura-seo' ) ) );
		$this->row( __( 'Include featured images', 'inklura-seo' ), $this->checkbox( 'sitemap_images', $g( 'sitemap_images', 1 ), __( 'Add <image:image> for entries with a featured image', 'inklura-seo' ) ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Indexing (robots)', 'inklura-seo' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row( __( 'Search results', 'inklura-seo' ), $this->checkbox( 'noindex_search', $g( 'noindex_search', 1 ), __( 'noindex search-result pages', 'inklura-seo' ) ) );
		$this->row( __( 'Paginated pages', 'inklura-seo' ), $this->checkbox( 'noindex_paged', $g( 'noindex_paged', 0 ), __( 'noindex page 2+ of archives / paginated content', 'inklura-seo' ) ) );
		$this->row( __( 'Author archives', 'inklura-seo' ), $this->checkbox( 'noindex_author', $g( 'noindex_author', 0 ), __( 'noindex author archive pages', 'inklura-seo' ) ) );
		$this->row( __( 'Date archives', 'inklura-seo' ), $this->checkbox( 'noindex_date', $g( 'noindex_date', 0 ), __( 'noindex date-based archive pages', 'inklura-seo' ) ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Modules', 'inklura-seo' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->row( __( 'Titles & meta', 'inklura-seo' ), $this->checkbox( 'enable_titles', $g( 'enable_titles', 1 ), __( 'Output document title, meta description, canonical and robots', 'inklura-seo' ) ) );
		$this->row( __( 'Open Graph / Twitter', 'inklura-seo' ), $this->checkbox( 'enable_og', $g( 'enable_og', 1 ), __( 'Output Open Graph + Twitter Card tags', 'inklura-seo' ) ) );
		$this->row( __( 'XML sitemap', 'inklura-seo' ), $this->checkbox( 'enable_sitemap', $g( 'enable_sitemap', 1 ), __( 'Serve /inklura-sitemap.xml and advertise it in robots.txt', 'inklura-seo' ) ) );
		$this->row( __( 'JSON-LD schema', 'inklura-seo' ), $this->checkbox( 'enable_schema', $g( 'enable_schema', 1 ), __( 'Output WebSite/Organization (home), Article (posts) and BreadcrumbList data', 'inklura-seo' ) ) );
		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'inklura-seo' ) );
		echo '</form>';

		$sitemap_url = Sitemap::index_url();
		echo '<p class="description">' . esc_html__( 'Sitemap:', 'inklura-seo' ) . ' <a href="' . esc_url( $sitemap_url ) . '" target="_blank" rel="noreferrer">' . esc_html( $sitemap_url ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Created by Benjamin Favre.', 'inklura-seo' )
			. ' <a href="' . esc_url( 'https://www.inklura.fr/' ) . '">Inklura</a> &middot; '
			. '<a href="' . esc_url( 'https://plugins.inklura.fr/' ) . '">' . esc_html__( 'Plugin catalogue', 'inklura-seo' ) . '</a></p>';

		echo '</div>';
	}

	private function maybe_show_network_notice(): void {
		if ( ! is_multisite() ) {
			return;
		}
		$net = get_site_option( Options::NETWORK_OPTION, array() );
		if ( is_array( $net ) && ! empty( $net ) && current_user_can( 'manage_network_options' ) ) {
			echo '<div class="notice notice-info inline"><p>'
				. esc_html__( 'Network defaults are configured; values you leave at their default here inherit from', 'inklura-seo' )
				. ' <a href="' . esc_url( network_admin_url( 'settings.php?page=' . Network::PAGE ) ) . '">' . esc_html__( 'Network → Inklura SEO', 'inklura-seo' ) . '</a>.</p></div>';
		}
	}

	// -----------------------------------------------------------------
	// Field helpers
	// -----------------------------------------------------------------

	private function name( string $key ): string {
		return Options::OPTION . '[' . $key . ']';
	}

	private function row( string $label, string $field ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function text( string $key, string $value, string $placeholder = '', string $class = 'regular-text' ): string {
		return sprintf(
			'<input type="text" name="%s" value="%s" placeholder="%s" class="%s" />',
			esc_attr( $this->name( $key ) ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			esc_attr( $class )
		);
	}

	private function checkbox( string $key, $checked, string $label ): string {
		return sprintf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( $this->name( $key ) ),
			checked( (bool) $checked, true, false ),
			esc_html( $label )
		);
	}

	private function select_separator( string $value ): string {
		$out = '<select name="' . esc_attr( $this->name( 'title_separator' ) ) . '">';
		foreach ( Options::separators() as $key => $glyph ) {
			$out .= sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $key ),
				selected( $value, (string) $key, false ),
				esc_html( $glyph )
			);
		}
		return $out . '</select>';
	}

	private function select_home_schema( string $value ): string {
		$choices = array(
			'organization' => __( 'Organization', 'inklura-seo' ),
			'person'       => __( 'Person', 'inklura-seo' ),
			'none'         => __( 'None', 'inklura-seo' ),
		);
		$out = '<select name="' . esc_attr( $this->name( 'home_schema_type' ) ) . '">';
		foreach ( $choices as $key => $label ) {
			$out .= sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( $value, $key, false ),
				esc_html( $label )
			);
		}
		return $out . '</select>';
	}
}
