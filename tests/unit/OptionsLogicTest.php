<?php
/**
 * Unit tests for the pure, WP-free SEO logic:
 *   - Options::format_title()  (token substitution + separator cleanup)
 *   - Options::summarize()     (tag strip + word-boundary truncation)
 *   - Sitemap::build_xml()     (urlset rendering + XML escaping)
 *   - Sitemap::sitemap_url()   (absolute sitemap URL)
 *   - Options merge precedence (defaults ⊕ network ⊕ per-blog)
 *
 * Run: php tests/unit/OptionsLogicTest.php
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

// ---------------------------------------------------------------------
// format_title
// ---------------------------------------------------------------------
$check(
	'Hello - My Site' === Options::format_title( '%title% %sep% %sitename%', 'Hello', 'My Site', '-' ),
	'format_title: standard title + separator + sitename'
);
$check(
	'My Site - Hello' === Options::format_title( '%sitename% %sep% %title%', 'Hello', 'My Site', '-' ),
	'format_title: sitename-first format'
);
// Empty title must NOT leave a dangling separator.
$check(
	'My Site' === Options::format_title( '%title% %sep% %sitename%', '', 'My Site', '-' ),
	'format_title: empty title drops the dangling separator'
);
// Empty sitename, title only.
$check(
	'Hello' === Options::format_title( '%title% %sep% %sitename%', 'Hello', '', '-' ),
	'format_title: empty sitename drops the trailing separator'
);
// Different glyph.
$check(
	'Hello » Brand' === Options::format_title( '%title% %sep% %sitename%', 'Hello', 'Brand', '»' ),
	'format_title: alternate separator glyph'
);
// Tagline token.
$check(
	'Hello — Tag' === Options::format_title( '%title% %sep% %tagline%', 'Hello', 'Brand', '—', 'Tag' ),
	'format_title: tagline token resolves'
);
// Empty separator falls back to '-'.
$check(
	'Hello - Brand' === Options::format_title( '%title% %sep% %sitename%', 'Hello', 'Brand', '' ),
	'format_title: blank separator falls back to hyphen'
);
// Doubled separator from an empty middle token is collapsed.
$check(
	'A - B' === Options::format_title( '%title% %sep% %tagline% %sep% %sitename%', 'A', 'B', '-', '' ),
	'format_title: empty middle token does not double the separator'
);

// ---------------------------------------------------------------------
// summarize
// ---------------------------------------------------------------------
$check(
	'Hello world' === Options::summarize( '<p>Hello <strong>world</strong></p>', 160 ),
	'summarize: strips HTML tags'
);
$check(
	'Clean text' === Options::summarize( "  Clean\n\t  text  ", 160 ),
	'summarize: collapses whitespace + trims'
);
$check(
	'' === Options::summarize( '[gallery ids="1,2,3"]', 160 ),
	'summarize: strips shortcode-ish brackets'
);
$long      = str_repeat( 'word ', 60 ); // 300 chars
$truncated = Options::summarize( $long, 50 );
$check( mb_strlen( $truncated ) <= 51, 'summarize: truncates to <= max (+ellipsis char)' );
$check( '…' === mb_substr( $truncated, -1 ), 'summarize: appends an ellipsis when cut' );
$check( false === strpos( $truncated, 'wor…' ), 'summarize: truncates on a word boundary (no half words)' );

// ---------------------------------------------------------------------
// Sitemap::sitemap_url
// ---------------------------------------------------------------------
$check(
	'https://example.test/inklura-sitemap.xml' === Sitemap::sitemap_url( 'https://example.test/' ),
	'sitemap_url: trailing slash home'
);
$check(
	'https://example.test/inklura-sitemap.xml' === Sitemap::sitemap_url( 'https://example.test' ),
	'sitemap_url: no trailing slash home'
);
$check(
	'https://example.test/blog/inklura-sitemap.xml' === Sitemap::sitemap_url( 'https://example.test/blog/' ),
	'sitemap_url: subdirectory install'
);

// ---------------------------------------------------------------------
// Sitemap::build_xml
// ---------------------------------------------------------------------
$xml = Sitemap::build_xml(
	array(
		array(
			'loc'     => 'https://example.test/',
			'lastmod' => '',
		),
		array(
			'loc'     => 'https://example.test/hello-world/',
			'lastmod' => '2026-06-13T10:00:00+00:00',
		),
	)
);
$check( false !== strpos( $xml, '<?xml version="1.0" encoding="UTF-8"?>' ), 'build_xml: has XML prolog' );
$check( false !== strpos( $xml, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' ), 'build_xml: has urlset' );
$check( 2 === substr_count( $xml, '<url>' ), 'build_xml: one <url> per entry' );
$check( false !== strpos( $xml, '<loc>https://example.test/hello-world/</loc>' ), 'build_xml: emits loc' );
$check( false !== strpos( $xml, '<lastmod>2026-06-13T10:00:00+00:00</lastmod>' ), 'build_xml: emits lastmod when present' );
$check( 1 === substr_count( $xml, '<lastmod>' ), 'build_xml: omits empty lastmod' );

// XML escaping of an ampersand in a query string.
$xml2 = Sitemap::build_xml( array( array( 'loc' => 'https://example.test/?a=1&b=2', 'lastmod' => '' ) ) );
$check( false !== strpos( $xml2, '?a=1&amp;b=2' ), 'build_xml: escapes & in loc' );
$check( false === strpos( $xml2, '?a=1&b=2<' ), 'build_xml: no raw unescaped ampersand' );

// Entries with an empty loc are skipped.
$xml3 = Sitemap::build_xml( array( array( 'loc' => '', 'lastmod' => '2026-01-01' ) ) );
$check( 0 === substr_count( $xml3, '<url>' ), 'build_xml: skips entries with empty loc' );

// ---------------------------------------------------------------------
// Options merge precedence
// ---------------------------------------------------------------------
inkseo_test_reset();
$opts = new Options();
$check( '-' === $opts->get( 'title_separator' ), 'options: default separator when nothing stored' );
$check( true === $opts->module_enabled( 'titles' ), 'options: modules default to enabled' );

// Network default overrides hard default; per-blog overrides network.
$GLOBALS['_inkseo_multisite'] = true;
update_site_option( Options::NETWORK_OPTION, array( 'title_separator' => 'pipe', 'enable_og' => 0 ) );
$opts2 = new Options();
$check( 'pipe' === $opts2->get( 'title_separator' ), 'options: network default applied' );
$check( false === $opts2->module_enabled( 'og' ), 'options: network can disable a module' );

update_option( Options::OPTION, array( 'title_separator' => 'mdash' ) );
$opts3 = new Options();
$check( 'mdash' === $opts3->get( 'title_separator' ), 'options: per-blog overrides network' );
$check( false === $opts3->module_enabled( 'og' ), 'options: unset per-blog key inherits network value' );

// Unknown keys in a stored option are ignored.
update_option( Options::OPTION, array( 'title_separator' => '-', 'evil' => 'x' ) );
$opts4 = new Options();
$check( ! array_key_exists( 'evil', $opts4->all() ), 'options: unknown stored keys are filtered out' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
