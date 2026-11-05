<?php
/** Run with wp eval-file after converting the isolated fixture to multisite. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'inklura_seo_audit' !== DB_NAME || ! is_multisite() ) {
	throw new RuntimeException( 'Requires isolated multisite fixture.' );
}
$plugin = Inklura\SEO\Plugin::instance();
$options = $plugin->options();
$original_net = get_site_option( Inklura\SEO\Options::NETWORK_OPTION, array() );
$original_blog = get_option( Inklura\SEO\Options::OPTION, array() );
$site_id = 0;
$checks = 0;
$check = function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
try {
	update_site_option( Inklura\SEO\Options::NETWORK_OPTION, array( 'title_separator' => 'pipe', 'home_schema_name' => 'Network Publisher', 'noindex_author' => 1 ) );
	update_option( Inklura\SEO\Options::OPTION, array( 'title_separator' => 'mdash' ) );
	$options->flush_cache();
	$check( 'mdash' === $options->get( 'title_separator' ), 'Per-site setting did not override network defaults' );
	$check( 'Network Publisher' === $options->get( 'home_schema_name' ), 'Network publisher default was not inherited' );
	$network = get_network();
	$site_id = wpmu_create_blog( $network->domain, $network->path . 'inkseo-audit-secondary/', 'Secondary fixture', 1, array( 'public' => 1 ), $network->id );
	$check( ! is_wp_error( $site_id ), 'Could not create secondary site' );
	switch_to_blog( $site_id );
	$check( 'pipe' === $options->get( 'title_separator' ), 'Settings cache leaked between sites' );
	$check( 1 === $options->get( 'noindex_author' ), 'Indexing default not inherited on new site' );
	update_option( Inklura\SEO\Options::OPTION, array( 'home_schema_name' => 'Secondary Publisher', 'enable_og' => 0 ) );
	$options->flush_cache();
	$check( 'Secondary Publisher' === $options->get( 'home_schema_name' ), 'Secondary override missing' );
	$check( false === $options->module_enabled( 'og' ), 'Secondary module setting not applied' );
	restore_current_blog();
	$check( 'Network Publisher' === $options->get( 'home_schema_name' ), 'Restore did not reset settings cache' );
	$check( true === $options->module_enabled( 'og' ), 'Secondary module setting leaked to primary' );
	$check( 'mdash' === $options->get( 'title_separator' ), 'Primary separator changed' );
	wp_set_current_user( 1 );
	$ui = new Inklura\SEO\Network( $options, $plugin );
	ob_start();
	$ui->render();
	$html = ob_get_clean();
	$check( false !== strpos( $html, 'inklura_seo_network_nonce' ), 'Network settings nonce missing' );
	$check( false !== strpos( $html, 'Network Publisher' ), 'Network settings do not show stored defaults' );
	echo "PASS: $checks native multisite checks\n";
} finally {
	while ( ms_is_switched() ) {
		restore_current_blog();
	}
	update_site_option( Inklura\SEO\Options::NETWORK_OPTION, $original_net );
	update_option( Inklura\SEO\Options::OPTION, $original_blog );
	$options->flush_cache();
	if ( $site_id && ! is_wp_error( $site_id ) ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_blog( $site_id, true );
	}
}
