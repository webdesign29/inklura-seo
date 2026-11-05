<?php
/** Run with wp eval-file tests/integration.php on the isolated audit database. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'inklura_seo_audit' !== DB_NAME ) {
	throw new RuntimeException( 'Integration tests require the isolated inklura_seo_audit database.' );
}
$GLOBALS['inkseo_checks'] = 0;
function inkseo_assert( $ok, $message ) {
	++$GLOBALS['inkseo_checks'];
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
}
function inkseo_visit( $args ) {
	$GLOBALS['wp_query'] = new WP_Query( $args );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post'] = $GLOBALS['wp_query']->post;
	if ( $GLOBALS['post'] ) {
		setup_postdata( $GLOBALS['post'] );
	}
}
function inkseo_call( $object, $method, ...$args ) {
	$m = new ReflectionMethod( $object, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invokeArgs( $object, $args );
}
function inkseo_head() {
	ob_start();
	do_action( 'wp_head' );
	return ob_get_clean();
}
function inkseo_locs( $xml ) {
	$doc = simplexml_load_string( $xml );
	if ( ! $doc ) {
		throw new RuntimeException( 'Invalid sitemap XML' );
	}
	$doc->registerXPathNamespace( 's', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
	return array_map( 'strval', $doc->xpath( '//s:loc' ) );
}
$plugin = Inklura\SEO\Plugin::instance();
$options = $plugin->options();
$original = array();
foreach ( array( 'inklura_seo_settings', 'show_on_front', 'page_on_front', 'page_for_posts', 'permalink_structure', 'blog_public' ) as $key ) {
	$original[ $key ] = get_option( $key );
}
$ids = array();
$term_id = 0;
$subscriber = 0;
$prefix = 'inklura-audit-' . uniqid();
$_SERVER['SERVER_NAME'] = (string) wp_parse_url( home_url(), PHP_URL_HOST );
try {
	global $wp_rewrite;
	$wp_rewrite->set_permalink_structure( '' );
	update_option( 'blog_public', 1 );
	update_option( 'inklura_seo_settings', array() );
	$options->flush_cache();
	inkseo_assert( null === $plugin->module( 'updater' ), 'Directory edition registered the external updater' );
	inkseo_assert( ! is_file( WP_PLUGIN_DIR . '/inklura-seo/src/Updater.php' ), 'External updater shipped' );
	$post = wp_insert_post( array( 'post_title' => 'Été & nature', 'post_content' => str_repeat( 'été ', 100 ) . '<!--nextpage-->Deuxième page', 'post_status' => 'publish' ) );
	$ids[] = $post;
	update_post_meta( $post, '_inklura_seo_title', 'A custom SEO title' );
	update_post_meta( $post, '_inklura_seo_desc', str_repeat( 'été ', 100 ) );
	inkseo_visit( array( 'p' => $post ) );
	$head = inkseo_head();
	inkseo_assert( 1 === substr_count( $head, 'rel="canonical"' ), 'Expected exactly one canonical tag' );
	inkseo_assert( 'A custom SEO title' === wp_get_document_title(), 'Post title override failed' );
	update_post_meta( $post, '_inklura_seo_title', '</title><script>alert(1)</script>' );
	inkseo_assert( false === strpos( wp_get_document_title(), '<script>' ), 'Document title bypassed WordPress escaping' );
	update_post_meta( $post, '_inklura_seo_title', 'A custom SEO title' );
	inkseo_assert( strpos( $head, 'property="og:title"' ) !== false, 'Open Graph missing' );
	inkseo_assert( strpos( $head, 'name="twitter:card"' ) !== false, 'Twitter card missing' );
	inkseo_assert( strpos( $head, 'été' ) !== false, 'UTF-8 description missing' );
	preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $head, $match );
	inkseo_assert( isset( json_decode( $match[1], true )['@graph'] ), 'Schema is not valid JSON' );
	$frontend = new Inklura\SEO\Frontend( $options, $plugin );
	inkseo_visit( array( 'p' => $post, 'page' => 2 ) );
	inkseo_assert( wp_get_canonical_url( $post ) === $frontend->canonical_url(), 'Multipage post canonical differs from WordPress' );
	inkseo_assert( strpos( wp_get_document_title(), 'Page 2' ) !== false, 'Paginated title suffix missing' );

	$hidden = wp_insert_post( array( 'post_title' => 'Excluded noindex', 'post_status' => 'publish' ) );
	$ids[] = $hidden;
	update_post_meta( $hidden, '_inklura_seo_noindex', '1' );
	$protected = wp_insert_post( array( 'post_title' => 'Protected content', 'post_content' => 'Do not expose this protected content', 'post_status' => 'publish', 'post_password' => 'secret' ) );
	$ids[] = $protected;
	inkseo_visit( array( 'p' => $protected ) );
	inkseo_assert( '' === $frontend->description(), 'Protected content leaked into description' );
	inkseo_assert( strpos( inkseo_head(), 'Do not expose this protected content' ) === false, 'Protected description leaked into social/schema output' );
	$draft = wp_insert_post( array( 'post_title' => 'Draft content', 'post_status' => 'draft' ) );
	$ids[] = $draft;
	$home = wp_insert_post( array( 'post_title' => 'Welcome', 'post_content' => 'Home content', 'post_type' => 'page', 'post_status' => 'publish' ) );
	$ids[] = $home;
	$blog = wp_insert_post( array( 'post_title' => 'Our news', 'post_type' => 'page', 'post_status' => 'publish' ) );
	$ids[] = $blog;
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $home );
	update_option( 'page_for_posts', $blog );
	update_post_meta( $blog, '_inklura_seo_title', 'Custom news title' );
	update_post_meta( $blog, '_inklura_seo_desc', 'Latest news description' );
	inkseo_visit( array( 'page_id' => $blog ) );
	inkseo_assert( is_home(), 'Posts-page fixture is not a blog index' );
	inkseo_assert( get_permalink( $blog ) === $frontend->canonical_url(), 'Blog index canonical points to the wrong page' );
	inkseo_assert( 'Custom news title' === wp_get_document_title(), 'Posts-page SEO title ignored' );
	inkseo_assert( 'Latest news description' === $frontend->description(), 'Posts-page description ignored' );
	inkseo_assert( 2 === count( $plugin->module( 'breadcrumbs' )->crumbs() ), 'Posts-page breadcrumb missing' );
	inkseo_visit( array( 'page_id' => $blog, 'paged' => 2 ) );
	$plain_paged = add_query_arg( 'paged', 2, get_permalink( $blog ) );
	inkseo_assert( $plain_paged === $frontend->canonical_url(), 'Plain blog pagination canonical wrong' );
	$term = wp_insert_term( $prefix, 'category' );
	$term_id = $term['term_id'];
	wp_set_post_terms( $post, array( $term_id ), 'category' );
	update_term_meta( $term_id, '_inklura_seo_title', 'Category SEO title' );
	update_term_meta( $term_id, '_inklura_seo_desc', 'Category SEO description' );
	inkseo_visit( array( 'cat' => $term_id ) );
	inkseo_assert( get_term_link( $term_id, 'category' ) === $frontend->canonical_url(), 'Category canonical wrong' );
	$head = inkseo_head();
	inkseo_assert( strpos( $head, 'content="Category SEO title"' ) !== false, 'Taxonomy social title wrong' );
	inkseo_assert( strpos( $head, 'content="Category SEO description"' ) !== false, 'Taxonomy social description wrong' );
	inkseo_visit( array( 'author' => 1 ) );
	inkseo_assert( get_author_posts_url( 1 ) === $frontend->canonical_url(), 'Author canonical wrong' );
	wp_set_current_user( 1 );
	$term_meta = new Inklura\SEO\TermMeta( $options, $plugin );
	$_POST = array( 'inklura_seo_term_title' => 'Unauthorized' );
	$term_meta->save( $term_id );
	inkseo_assert( 'Category SEO title' === get_term_meta( $term_id, '_inklura_seo_title', true ), 'Term metadata accepted missing nonce' );
	$_POST = array( 'inklura_seo_term_nonce' => wp_create_nonce( 'inklura_seo_save_term' ), 'inklura_seo_term_title' => 'Saved <b>category</b>' );
	$term_meta->save( $term_id );
	inkseo_assert( 'Saved category' === get_term_meta( $term_id, '_inklura_seo_title', true ), 'Term metadata not saved or sanitized' );
	$_POST = array();
	inkseo_visit( array( 's' => 'summer' ) );
	inkseo_assert( '' === $frontend->canonical_url(), 'Search page canonical points at home' );
	inkseo_assert( ! empty( apply_filters( 'wp_robots', array() )['noindex'] ), 'Search results must be noindex by default' );

	$sitemap = $plugin->module( 'sitemap' );
	$locs = inkseo_locs( inkseo_call( $sitemap, 'build_subsitemap', 'content', 1 ) );
	inkseo_assert( in_array( get_permalink( $post ), $locs, true ), 'Published post absent from sitemap' );
	inkseo_assert( in_array( get_permalink( $blog ), $locs, true ), 'Published page absent from sitemap' );
	foreach ( array( $hidden, $protected, $draft ) as $id ) {
		inkseo_assert( ! in_array( get_permalink( $id ), $locs, true ), 'Non-indexable post present in sitemap' );
	}
	inkseo_assert( 1 === count( array_keys( $locs, home_url( '/' ), true ) ), 'Static homepage duplicated in sitemap' );
	inkseo_assert( count( $locs ) === count( array_unique( $locs ) ), 'Duplicate sitemap URLs' );
	inkseo_assert( null === inkseo_call( $sitemap, 'build_subsitemap', 'unknown', 1 ), 'Unknown sitemap type must be absent' );
	inkseo_assert( null === inkseo_call( $sitemap, 'build_subsitemap', 'content', 0 ), 'Zero sitemap page must be absent' );
	inkseo_assert( null === inkseo_call( $sitemap, 'build_subsitemap', 'content', 999999 ), 'Out-of-range sitemap page must be absent' );
	update_post_meta( $home, '_inklura_seo_noindex', '1' );
	$locs = inkseo_locs( inkseo_call( $sitemap, 'build_subsitemap', 'content', 1 ) );
	inkseo_assert( ! in_array( home_url( '/' ), $locs, true ), 'Noindex homepage included in sitemap' );
	delete_post_meta( $home, '_inklura_seo_noindex' );
	update_option( 'inklura_seo_settings', array( 'noindex_author' => 1 ) );
	$options->flush_cache();
	inkseo_assert( strpos( inkseo_call( $sitemap, 'build_index' ), 'author' ) === false, 'Noindex author archive listed in index' );
	inkseo_assert( null === inkseo_call( $sitemap, 'build_subsitemap', 'author', 1 ), 'Noindex author sitemap still accessible' );
	inkseo_assert( false === apply_filters( 'wp_sitemaps_enabled', true ), 'Overlapping core sitemap is still enabled' );
	inkseo_assert( false === strpos( $sitemap->robots_txt( '', false ), 'Sitemap:' ), 'Private robots.txt advertises sitemap' );
	update_option( 'blog_public', 0 );
	inkseo_assert( false === strpos( $sitemap->robots_txt( '', true ), 'Sitemap:' ), 'Nonpublic site advertises sitemap' );
	update_option( 'blog_public', 1 );
	update_option( 'inklura_seo_settings', array( 'home_schema_type' => 'person', 'home_schema_name' => '</script><script>unsafe</script>' ) );
	$options->flush_cache();
	inkseo_visit( array( 'page_id' => $home ) );
	$head = inkseo_head();
	preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $head, $match );
	$graph = json_decode( $match[1], true )['@graph'];
	inkseo_assert( in_array( 'Person', array_column( $graph, '@type' ), true ), 'Home entity setting ignored' );
	inkseo_assert( false === strpos( $match[1], '</script>' ), 'Schema can break out of its script element' );
	inkseo_visit( array( 'p' => $post ) );
	$head = inkseo_head();
	preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $head, $match );
	$graph = json_decode( $match[1], true )['@graph'];
	inkseo_assert( 'Person' === $graph[0]['publisher']['@type'], 'Article publisher contradicts configured home entity' );

	wp_set_current_user( 1 );
	$settings = new Inklura\SEO\Settings( $options, $plugin );
	$clean = $settings->sanitize( array( 'title_format' => '%title% <script>x</script>', 'default_og_image' => 'javascript:alert(1)', 'twitter_site' => array( 'bad' ), 'home_schema_logo' => array( 'bad' ) ) );
	inkseo_assert( false === strpos( $clean['title_format'], '<script>' ), 'Settings retain executable HTML' );
	inkseo_assert( '' === $clean['default_og_image'] && '' === $clean['home_schema_logo'], 'Unsafe settings URL retained' );
	inkseo_assert( '' === $clean['twitter_site'], 'Malformed handle retained' );
	$clean = $settings->sanitize( array( 'title_format' => '%title%', 'home_schema_name' => 'Path \\ Name' ) );
	inkseo_assert( 'Path \\ Name' === $clean['home_schema_name'], 'Settings were unslashed twice' );
	$meta = new Inklura\SEO\MetaBox( $options, $plugin );
	$_POST = array( 'inklura_seo_title' => 'Unauthorized title' );
	$meta->save( $post, get_post( $post ) );
	inkseo_assert( 'A custom SEO title' === get_post_meta( $post, '_inklura_seo_title', true ), 'Metabox accepted missing nonce' );
	$subscriber = wp_create_user( $prefix, 'isolated-password', $prefix . '@example.test' );
	wp_set_current_user( $subscriber );
	$_POST = array( 'inklura_seo_meta_nonce' => wp_create_nonce( 'inklura_seo_save_meta' ), 'inklura_seo_title' => 'Unauthorized title' );
	$meta->save( $post, get_post( $post ) );
	inkseo_assert( 'A custom SEO title' === get_post_meta( $post, '_inklura_seo_title', true ), 'Metabox accepted an unauthorized user' );
	wp_set_current_user( 1 );
	$_POST = array( 'inklura_seo_meta_nonce' => wp_create_nonce( 'inklura_seo_save_meta' ), 'inklura_seo_title' => 'Saved title', 'inklura_seo_og_image' => array( 'invalid' ) );
	$meta->save( $post, get_post( $post ) );
	inkseo_assert( 'Saved title' === get_post_meta( $post, '_inklura_seo_title', true ), 'Authorized metabox save failed' );
	inkseo_assert( '' === get_post_meta( $post, '_inklura_seo_og_image', true ), 'Malformed metabox URL retained' );

	// Exercise the old truncation boundary using 5,105 real database rows.
	if ( getenv( 'INKLURA_SEO_LARGE_TEST' ) ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		for ( $batch = 0; $batch < 26; ++$batch ) {
			$values = array();
			for ( $i = $batch * 200; $i < min( 5105, ( $batch + 1 ) * 200 ); ++$i ) {
				$values[] = $wpdb->prepare( '(%s,%s,%s,%s,%s,%s,%s,%s,%d)', $prefix . '-' . $i, $prefix . '-' . $i, 'publish', 'post', $now, $now, $now, $now, 1 );
			}
			$wpdb->query( "INSERT INTO {$wpdb->posts} (post_title,post_name,post_status,post_type,post_date,post_date_gmt,post_modified,post_modified_gmt,post_author) VALUES " . implode( ',', $values ) );
		}
		wp_cache_flush();
		update_option( 'inklura_seo_settings', array( 'sitemap_max_urls' => 1000, 'sitemap_taxonomies' => 0, 'sitemap_authors' => 0, 'sitemap_images' => 0 ) );
		$options->flush_cache();
		$before = $wpdb->num_queries;
		$start = microtime( true );
		$index = inkseo_locs( inkseo_call( $sitemap, 'build_index' ) );
		$queries = $wpdb->num_queries - $before;
		inkseo_assert( 6 === count( $index ), 'Sitemap index lost content above 5,000 posts' );
		inkseo_assert( $queries <= 8, 'Sitemap index performs unbounded per-post queries' );
		$last = inkseo_locs( inkseo_call( $sitemap, 'build_subsitemap', 'content', 6 ) );
		inkseo_assert( count( $last ) > 100 && count( $last ) < 1000, 'Last sitemap page missing or incorrect' );
		inkseo_assert( null === inkseo_call( $sitemap, 'build_subsitemap', 'content', 7 ), 'Sitemap page beyond the end is not absent' );
		$all = array();
		for ( $page = 1; $page <= 6; ++$page ) {
			$part = inkseo_locs( inkseo_call( $sitemap, 'build_subsitemap', 'content', $page ) );
			inkseo_assert( count( $part ) <= 1000, 'Sitemap page exceeds its configured URL limit' );
			$all = array_merge( $all, $part );
		}
		inkseo_assert( count( $all ) === count( array_unique( $all ) ), 'Duplicate URLs across sitemap pages' );
		$eligible = inkseo_call( $sitemap, 'content_count' );
		inkseo_assert( $eligible === count( $all ), 'Sitemap pages do not cover the full eligible content set' );
		echo sprintf( "Large-site check: %d URLs, %d index queries, %.3fs total scan, %.1f MiB peak PHP memory\n", count( $all ), $queries, microtime( true ) - $start, memory_get_peak_usage( true ) / 1048576 );
	}
	echo 'PASS: ' . $GLOBALS['inkseo_checks'] . " native WordPress integration checks\n";
} finally {
	$_POST = array();
	wp_set_current_user( 1 );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $term_id ) {
		wp_delete_term( $term_id, 'category' );
	}
	if ( $subscriber ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $subscriber );
	}
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE post_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	foreach ( $original as $key => $value ) {
		update_option( $key, $value );
	}
	$wp_rewrite->init();
	$options->flush_cache();
	wp_cache_flush();
}
