<?php
/**
 * Uninstall cleanup: remove all options + postmeta Inklura SEO creates.
 *
 * Runs only when the plugin is deleted via the WordPress admin (normal-plugin
 * install). Multisite-aware: cleans every blog's options + meta, then the
 * network defaults site option.
 *
 * @package Inklura\SEO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Per-blog options this plugin creates. */
$inklura_seo_options = array(
	'inklura_seo_settings',
);

/** Postmeta keys this plugin creates. */
$inklura_seo_meta_keys = array(
	'_inklura_seo_title',
	'_inklura_seo_desc',
	'_inklura_seo_noindex',
	'_inklura_seo_og_image',
);

/** Term-meta keys this plugin creates (shared key names with postmeta). */
$inklura_seo_term_meta_keys = array(
	'_inklura_seo_title',
	'_inklura_seo_desc',
);

/**
 * Clean a single blog: delete the options and any post/term meta rows we wrote.
 *
 * @param array<int,string> $options
 * @param array<int,string> $meta_keys
 * @param array<int,string> $term_meta_keys
 */
function inklura_seo_cleanup_blog( $options, $meta_keys, $term_meta_keys = array() ) {
	foreach ( $options as $opt ) {
		delete_option( $opt );
	}
	foreach ( $meta_keys as $key ) {
		// delete_post_meta_by_key removes the row from every post at once.
		if ( function_exists( 'delete_post_meta_by_key' ) ) {
			delete_post_meta_by_key( $key );
		}
	}
	// Term meta: delete every row carrying one of our keys.
	if ( ! empty( $term_meta_keys ) ) {
		global $wpdb;
		if ( isset( $wpdb ) && isset( $wpdb->termmeta ) ) {
			foreach ( $term_meta_keys as $key ) {
				$wpdb->delete( $wpdb->termmeta, array( 'meta_key' => $key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.DirectDatabaseQuery
			}
		}
	}
}

if ( is_multisite() ) {
	// number => 0 = no limit, so large networks are fully cleaned.
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		inklura_seo_cleanup_blog( $inklura_seo_options, $inklura_seo_meta_keys, $inklura_seo_term_meta_keys );
		restore_current_blog();
	}
	// Network-wide defaults (a site option, not per-blog).
	delete_site_option( 'inklura_seo_network_defaults' );
} else {
	inklura_seo_cleanup_blog( $inklura_seo_options, $inklura_seo_meta_keys, $inklura_seo_term_meta_keys );
}
