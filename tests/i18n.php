<?php
/** Verify real WordPress locale switching and bundled catalogue loading. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'inklura_seo_audit' !== DB_NAME ) {
	throw new RuntimeException( 'Translation checks require the isolated audit database.' );
}

$fixtures = array(
	'fr_FR' => array( 'Réglages', 'Enregistrer les réglages', 'Page introuvable', 'Accueil', 'Fil d’Ariane' ),
	'de_DE' => array( 'Einstellungen', 'Einstellungen speichern', 'Seite nicht gefunden', 'Startseite', 'Brotkrümelnavigation' ),
	'es_ES' => array( 'Ajustes', 'Guardar ajustes', 'Página no encontrada', 'Inicio', 'Migas de pan' ),
	'it_IT' => array( 'Impostazioni', 'Salva le impostazioni', 'Pagina non trovata', 'Home', 'Breadcrumb' ),
	'pt_BR' => array( 'Configurações', 'Salvar configurações', 'Página não encontrada', 'Início', 'Trilha de navegação' ),
);
$checks = 0;
$assert = static function ( $ok, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$options = Inklura\SEO\Plugin::instance()->options();
$original = get_option( 'inklura_seo_settings', array() );
wp_set_current_user( 1 );
try {
	foreach ( $fixtures as $locale => $expected ) {
		$assert( switch_to_locale( $locale ), 'Cannot switch to ' . $locale );
		$assert( __( 'Settings', 'inklura-seo' ) === $expected[0], 'Bundled settings label failed: ' . $locale );
		$assert( __( 'Page not found', 'inklura-seo' ) === $expected[2], 'Frontend label failed: ' . $locale );
		$assert( false !== strpos( __( '%page% is replaced with the page suffix on paginated views (and dropped on page 1).', 'inklura-seo' ), '%page%' ), 'Title token changed' );
		$mo = new MO();
		$assert( $mo->import_from_file( INKLURA_SEO_DIR . '/languages/inklura-seo-' . $locale . '.mo' ), 'Cannot read MO catalogue' );
		$assert( 94 === count( $mo->entries ), 'Incomplete catalogue: ' . $locale );
		foreach ( $mo->entries as $entry ) {
			$assert( '' !== $entry->translations[0], 'Empty translation: ' . $locale );
		}
		update_option( 'inklura_seo_settings', array() );
		$options->flush_cache();
		$breadcrumbs = new Inklura\SEO\Breadcrumbs( $options, Inklura\SEO\Plugin::instance() );
		$trail = $breadcrumbs->crumbs();
		$assert( $trail[0]['name'] === $expected[3], 'Default Home label failed: ' . $locale );
		$html = Inklura\SEO\Breadcrumbs::render_html( array( array( 'name' => $expected[3], 'url' => home_url( '/' ) ), array( 'name' => 'Test', 'url' => '' ) ) );
		$assert( false !== strpos( $html, 'aria-label="' . esc_attr( $expected[4] ) . '"' ), 'Breadcrumb accessibility label failed' );
		$settings = new Inklura\SEO\Settings( $options, Inklura\SEO\Plugin::instance() );
		ob_start();
		$settings->render();
		$html = ob_get_clean();
		$assert( false !== strpos( $html, esc_html( $expected[1] ) ), 'Native settings screen is not translated: ' . $locale );
		$clean = $settings->sanitize( array( 'breadcrumb_home_label' => '' ) );
		$assert( '' === $clean['breadcrumb_home_label'], 'Empty locale-dependent label was converted to English' );
		update_option( 'inklura_seo_settings', array( 'breadcrumb_home_label' => 'My custom start' ) );
		$options->flush_cache();
		$assert( 'My custom start' === $breadcrumbs->crumbs()[0]['name'], 'Saved custom label was translated' );
		restore_previous_locale();
	}
	$assert( 'Settings' === __( 'Settings', 'inklura-seo' ), 'English fallback failed after restoring locale' );
	echo 'PASS: ' . $checks . " native translation checks across five locales\n";
} finally {
	restore_current_locale();
	update_option( 'inklura_seo_settings', $original );
	$options->flush_cache();
}
