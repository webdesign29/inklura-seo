<?php
/**
 * Unit tests for the sitemap's pure XML layer:
 *   - Sitemap::build_xml()        (urlset incl. <image:image>)
 *   - Sitemap::build_index_xml()  (sitemapindex)
 *   - Sitemap::subsitemap_url()   (paginated sub-sitemap URLs)
 *   - Sitemap::latest_lastmod()   (newest lastmod for an index entry)
 *   - well-formedness of both documents (parsed with DOMDocument)
 *   - index-splitting wired through Options::chunk_entries + subsitemap_url
 *
 * Run: php tests/unit/SitemapTest.php
 *
 * @package Inklura\SEO
 */

require __DIR__ . '/../bootstrap.php';

require __DIR__ . '/../../src/Options.php';
require __DIR__ . '/../../src/Sitemap.php';

use Inklura\SEO\Options;
use Inklura\SEO\Sitemap;

$failures = 0;
$check    = function ( $cond, $label ) use ( &$failures ) {
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$failures;
	}
};

/** Parse XML and report whether it is well-formed. */
$well_formed = function ( $xml ) {
	$prev = libxml_use_internal_errors( true );
	$doc  = new DOMDocument();
	$ok   = $doc->loadXML( $xml );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );
	return false !== $ok;
};

// ---------------------------------------------------------------------
// build_xml with <image:image>
// ---------------------------------------------------------------------
$xml = Sitemap::build_xml(
	array(
		array( 'loc' => 'https://example.test/', 'lastmod' => '' ),
		array(
			'loc'     => 'https://example.test/post/',
			'lastmod' => '2026-06-13T10:00:00+00:00',
			'images'  => array( 'https://example.test/wp-content/feat.jpg' ),
		),
	)
);
$check( $well_formed( $xml ), 'build_xml: document is well-formed XML' );
$check( 2 === substr_count( $xml, '<url>' ), 'build_xml: one <url> per entry' );
$check( false !== strpos( $xml, '<image:image>' ), 'build_xml: emits <image:image> when images present' );
$check( false !== strpos( $xml, '<image:loc>https://example.test/wp-content/feat.jpg</image:loc>' ), 'build_xml: emits <image:loc>' );
$check( 1 === substr_count( $xml, '<image:image>' ), 'build_xml: only the entry with images gets an image block' );

// Multiple images on one URL.
$xml_multi = Sitemap::build_xml(
	array(
		array(
			'loc'    => 'https://example.test/a/',
			'images' => array( 'https://example.test/1.jpg', 'https://example.test/2.jpg' ),
		),
	)
);
$check( 2 === substr_count( $xml_multi, '<image:image>' ), 'build_xml: two images → two image blocks' );
$check( $well_formed( $xml_multi ), 'build_xml: multi-image doc is well-formed' );

// Empty image URL inside the list is skipped.
$xml_empty_img = Sitemap::build_xml( array( array( 'loc' => 'https://example.test/b/', 'images' => array( '' ) ) ) );
$check( false === strpos( $xml_empty_img, '<image:image>' ), 'build_xml: empty image URL skipped' );

// ---------------------------------------------------------------------
// build_index_xml
// ---------------------------------------------------------------------
$index = Sitemap::build_index_xml(
	array(
		array( 'loc' => 'https://example.test/inklura-sitemap-content-1.xml', 'lastmod' => '2026-06-13T10:00:00+00:00' ),
		array( 'loc' => 'https://example.test/inklura-sitemap-content-2.xml', 'lastmod' => '' ),
		array( 'loc' => 'https://example.test/inklura-sitemap-tax-category-1.xml', 'lastmod' => '2026-06-10T08:00:00+00:00' ),
	)
);
$check( $well_formed( $index ), 'build_index_xml: document is well-formed XML' );
$check( false !== strpos( $index, '<sitemapindex' ), 'build_index_xml: has sitemapindex root' );
$check( 3 === substr_count( $index, '<sitemap>' ), 'build_index_xml: one <sitemap> per spec' );
$check( false !== strpos( $index, '<loc>https://example.test/inklura-sitemap-content-1.xml</loc>' ), 'build_index_xml: emits sub-sitemap loc' );
$check( 2 === substr_count( $index, '<lastmod>' ), 'build_index_xml: omits empty lastmod' );

// Specs with an empty loc are skipped.
$index_skip = Sitemap::build_index_xml( array( array( 'loc' => '', 'lastmod' => '2026-01-01' ) ) );
$check( 0 === substr_count( $index_skip, '<sitemap>' ), 'build_index_xml: skips empty loc' );

// XML escaping in the index loc.
$index_esc = Sitemap::build_index_xml( array( array( 'loc' => 'https://example.test/sm.xml?a=1&b=2', 'lastmod' => '' ) ) );
$check( false !== strpos( $index_esc, '?a=1&amp;b=2' ), 'build_index_xml: escapes & in loc' );
$check( $well_formed( $index_esc ), 'build_index_xml: escaped doc is well-formed' );

// ---------------------------------------------------------------------
// subsitemap_url
// ---------------------------------------------------------------------
$check(
	'https://example.test/inklura-sitemap-content-1.xml' === Sitemap::subsitemap_url( 'https://example.test/', 'content', 1 ),
	'subsitemap_url: content page 1'
);
$check(
	'https://example.test/inklura-sitemap-tax-category-3.xml' === Sitemap::subsitemap_url( 'https://example.test', 'tax-category', 3 ),
	'subsitemap_url: taxonomy page 3, no trailing slash home'
);
$check(
	'https://example.test/blog/inklura-sitemap-author-1.xml' === Sitemap::subsitemap_url( 'https://example.test/blog/', 'author', 1 ),
	'subsitemap_url: subdirectory install'
);
$check(
	'https://example.test/inklura-sitemap-content-1.xml' === Sitemap::subsitemap_url( 'https://example.test/', 'content', 0 ),
	'subsitemap_url: page<1 clamps to 1'
);

// ---------------------------------------------------------------------
// latest_lastmod
// ---------------------------------------------------------------------
$check(
	'2026-06-13T10:00:00+00:00' === Sitemap::latest_lastmod(
		array(
			array( 'lastmod' => '2026-06-10T08:00:00+00:00' ),
			array( 'lastmod' => '2026-06-13T10:00:00+00:00' ),
			array( 'lastmod' => '2026-06-12T09:00:00+00:00' ),
		)
	),
	'latest_lastmod: picks the newest ISO-8601 value'
);
$check( '' === Sitemap::latest_lastmod( array( array( 'lastmod' => '' ), array() ) ), 'latest_lastmod: empty when none present' );
$check(
	'2026-01-01T00:00:00+00:00' === Sitemap::latest_lastmod( array( array( 'lastmod' => '' ), array( 'lastmod' => '2026-01-01T00:00:00+00:00' ) ) ),
	'latest_lastmod: ignores empty entries'
);

// ---------------------------------------------------------------------
// index splitting (chunk_entries + subsitemap_url, the index pipeline)
// ---------------------------------------------------------------------
$entries = array();
for ( $i = 0; $i < 23; $i++ ) {
	$entries[] = array( 'loc' => 'https://example.test/p' . $i . '/', 'lastmod' => sprintf( '2026-06-%02dT00:00:00+00:00', ( $i % 28 ) + 1 ) );
}
$per   = 10;
$pages = Options::chunk_entries( $entries, $per );
$check( 3 === count( $pages ), 'index split: 23 entries / 10 per → 3 pages' );

$specs = array();
foreach ( $pages as $idx => $chunk ) {
	$specs[] = array(
		'loc'     => Sitemap::subsitemap_url( 'https://example.test/', 'content', $idx + 1 ),
		'lastmod' => Sitemap::latest_lastmod( $chunk ),
	);
}
$index_built = Sitemap::build_index_xml( $specs );
$check( 3 === substr_count( $index_built, '<sitemap>' ), 'index split: index lists 3 sub-sitemaps' );
$check( false !== strpos( $index_built, 'inklura-sitemap-content-3.xml' ), 'index split: third page URL present' );
$check( $well_formed( $index_built ), 'index split: built index is well-formed' );

// The first page should carry max(lastmod over its 10 entries).
$check( false !== strpos( $index_built, '<lastmod>' ), 'index split: index entries carry a lastmod' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
