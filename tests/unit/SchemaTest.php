<?php
/**
 * Unit tests for the pure schema / social helpers:
 *   - Breadcrumbs::json_ld()       (BreadcrumbList ListItem structure)
 *   - Breadcrumbs::render_html()   (escaped visible trail)
 *   - OpenGraph::normalize_handle()(Twitter @site normalization)
 *
 * Run: php tests/unit/SchemaTest.php
 *
 * @package Inklura\SEO
 */

require __DIR__ . '/../bootstrap.php';

require __DIR__ . '/../../src/Options.php';
require __DIR__ . '/../../src/Breadcrumbs.php';
require __DIR__ . '/../../src/OpenGraph.php';

use Inklura\SEO\Breadcrumbs;
use Inklura\SEO\OpenGraph;

$failures = 0;
$check    = function ( $cond, $label ) use ( &$failures ) {
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$failures;
	}
};

// ---------------------------------------------------------------------
// Breadcrumbs::json_ld
// ---------------------------------------------------------------------
$crumbs = array(
	array( 'name' => 'Home', 'url' => 'https://example.test/' ),
	array( 'name' => 'Blog', 'url' => 'https://example.test/blog/' ),
	array( 'name' => 'Hello World', 'url' => 'https://example.test/blog/hello/' ),
);
$node = Breadcrumbs::json_ld( $crumbs );
$check( is_array( $node ), 'json_ld: returns an array for a multi-level trail' );
$check( 'BreadcrumbList' === $node['@type'], 'json_ld: @type is BreadcrumbList' );
$check( 3 === count( $node['itemListElement'] ), 'json_ld: one ListItem per crumb' );
$check( 1 === $node['itemListElement'][0]['position'], 'json_ld: positions are 1-based' );
$check( 3 === $node['itemListElement'][2]['position'], 'json_ld: positions increment' );
$check( 'ListItem' === $node['itemListElement'][0]['@type'], 'json_ld: items are ListItem' );
$check( 'Home' === $node['itemListElement'][0]['name'], 'json_ld: carries the crumb name' );
$check( 'https://example.test/' === $node['itemListElement'][0]['item'], 'json_ld: carries the crumb item URL' );

// JSON-encodes cleanly.
$encoded = json_encode( $node );
$check( false !== $encoded && false !== strpos( $encoded, '"BreadcrumbList"' ), 'json_ld: encodes to valid JSON' );

// Single crumb (home only) → null (no breadcrumb worth emitting).
$check( null === Breadcrumbs::json_ld( array( array( 'name' => 'Home', 'url' => 'https://example.test/' ) ) ), 'json_ld: single crumb → null' );
$check( null === Breadcrumbs::json_ld( array() ), 'json_ld: empty → null' );

// A current crumb with no URL still gets a position + name but no "item".
$last_no_url = Breadcrumbs::json_ld(
	array(
		array( 'name' => 'Home', 'url' => 'https://example.test/' ),
		array( 'name' => 'Search results', 'url' => '' ),
	)
);
$check( is_array( $last_no_url ), 'json_ld: trail with a URL-less leaf still emits' );
$check( ! isset( $last_no_url['itemListElement'][1]['item'] ), 'json_ld: URL-less crumb omits item' );
$check( 'Search results' === $last_no_url['itemListElement'][1]['name'], 'json_ld: URL-less crumb keeps name' );

// ---------------------------------------------------------------------
// Breadcrumbs::render_html
// ---------------------------------------------------------------------
$html = Breadcrumbs::render_html( $crumbs, '/' );
$check( false !== strpos( $html, '<nav class="inklura-breadcrumbs"' ), 'render_html: wraps in a nav' );
$check( false !== strpos( $html, 'aria-label="Breadcrumb"' ), 'render_html: nav has aria-label' );
$check( 2 === substr_count( $html, '<a ' ), 'render_html: links every crumb except the last' );
$check( false !== strpos( $html, 'aria-current="page"', 0 ), 'render_html: last crumb marked aria-current' );
$check( false !== strpos( $html, 'href="https://example.test/blog/"', 0 ), 'render_html: emits crumb hrefs' );
$check( 2 === substr_count( $html, 'inklura-crumb-sep' ), 'render_html: separator between 3 crumbs appears twice' );

// XSS safety: a malicious crumb name must be escaped.
$evil = Breadcrumbs::render_html(
	array(
		array( 'name' => 'Home', 'url' => 'https://example.test/' ),
		array( 'name' => '<script>alert(1)</script>', 'url' => '' ),
	),
	'/'
);
$check( false === strpos( $evil, '<script>alert(1)</script>' ), 'render_html: escapes a script-tag crumb name' );
$check( false !== strpos( $evil, '&lt;script&gt;' ), 'render_html: script tag is entity-encoded' );

// Single crumb → empty (nothing to show).
$check( '' === Breadcrumbs::render_html( array( array( 'name' => 'Home', 'url' => 'https://example.test/' ) ) ), 'render_html: single crumb → empty string' );

// A crumb without a URL renders as a span, not a link.
$one_link = Breadcrumbs::render_html(
	array(
		array( 'name' => 'Home', 'url' => 'https://example.test/' ),
		array( 'name' => 'Archive', 'url' => '' ),
		array( 'name' => 'Item', 'url' => 'https://example.test/x/' ),
	),
	'>'
);
$check( 1 === substr_count( $one_link, '<a ' ), 'render_html: URL-less middle crumb is not a link' );
$check( false !== strpos( $one_link, '>&gt;<', 0 ) || false !== strpos( $one_link, '&gt;', 0 ), 'render_html: custom separator is escaped' );

// ---------------------------------------------------------------------
// OpenGraph::normalize_handle
// ---------------------------------------------------------------------
$check( '@inklura' === OpenGraph::normalize_handle( 'inklura' ), 'normalize_handle: bare handle gets a leading @' );
$check( '@inklura' === OpenGraph::normalize_handle( '@inklura' ), 'normalize_handle: existing @ preserved (single)' );
$check( '@inklura' === OpenGraph::normalize_handle( '  @inklura  ' ), 'normalize_handle: trims surrounding space' );
$check( '@inklura' === OpenGraph::normalize_handle( 'https://twitter.com/inklura' ), 'normalize_handle: extracts handle from twitter.com URL' );
$check( '@inklura' === OpenGraph::normalize_handle( 'https://x.com/@inklura' ), 'normalize_handle: extracts handle from x.com URL with @' );
$check( '@my_handle' === OpenGraph::normalize_handle( '@my_handle' ), 'normalize_handle: underscores kept' );
$check( '@badname' === OpenGraph::normalize_handle( '@bad!! name' ), 'normalize_handle: invalid chars + spaces dropped' );
$check( '' === OpenGraph::normalize_handle( '' ), 'normalize_handle: empty input → empty' );
$check( '' === OpenGraph::normalize_handle( '@@@' ), 'normalize_handle: only @ → empty' );
$check( '' === OpenGraph::normalize_handle( '!!!' ), 'normalize_handle: only junk → empty' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
