<?php
/**
 * Unit tests for the pure, WP-free SEO logic:
 *   - Options::format_title()        (token substitution + separator cleanup)
 *   - Options::format_title_paged()  (%page% + pagination suffixing)
 *   - Options::summarize()           (tag strip + word-boundary truncation)
 *   - Options::og_image_chain()      (post override → featured → default)
 *   - Options::archive_noindex()     (settings-driven noindex decisions)
 *   - Options::chunk_entries()       (sitemap index splitting)
 *   - Sitemap::build_xml()           (urlset rendering + XML escaping)
 *   - Sitemap::sitemap_url()         (absolute sitemap URL)
 *   - Options merge precedence       (defaults ⊕ network ⊕ per-blog, incl. 0.2.0 keys)
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
// format_title_paged (%page% + pagination)
// ---------------------------------------------------------------------
// Page 1 (unpaged): no suffix, %page% token dropped cleanly.
$check(
	'Hello - My Site' === Options::format_title_paged( '%title% %sep% %sitename% %sep% %page%', 'Hello', 'My Site', '-', '', 1 ),
	'format_title_paged: %page% dropped on page 1 (no dangling separator)'
);
// Page 2 with explicit %page% token.
$check(
	'Hello - My Site - Page 2' === Options::format_title_paged( '%title% %sep% %sitename% %sep% %page%', 'Hello', 'My Site', '-', '', 2 ),
	'format_title_paged: %page% expands to "Page 2" on page 2'
);
// Page 3, %page% in the middle.
$check(
	'Hello - Page 3 - My Site' === Options::format_title_paged( '%title% %sep% %page% %sep% %sitename%', 'Hello', 'My Site', '-', '', 3 ),
	'format_title_paged: %page% in the middle on page 3'
);
// No %page% token but paginated → suffix is appended.
$check(
	'Hello - My Site - Page 4' === Options::format_title_paged( '%title% %sep% %sitename%', 'Hello', 'My Site', '-', '', 4 ),
	'format_title_paged: appends suffix when format has no %page% token'
);
// No %page% token, page 1 → unchanged.
$check(
	'Hello - My Site' === Options::format_title_paged( '%title% %sep% %sitename%', 'Hello', 'My Site', '-', '', 1 ),
	'format_title_paged: page 1 with no token is unchanged'
);
// Localized page word.
$check(
	'Hi - Site - Página 2' === Options::format_title_paged( '%title% %sep% %sitename%', 'Hi', 'Site', '-', '', 2, 'Página' ),
	'format_title_paged: honours a localized page word'
);
// Alternate separator carries into the appended suffix.
$check(
	'A » B » Page 2' === Options::format_title_paged( '%title% %sep% %sitename%', 'A', 'B', '»', '', 2 ),
	'format_title_paged: appended suffix uses the configured separator'
);

// ---------------------------------------------------------------------
// og_image_chain (post override → featured → default)
// ---------------------------------------------------------------------
$check(
	'https://x/override.png' === Options::og_image_chain( 'https://x/override.png', 'https://x/feat.png', 'https://x/def.png' ),
	'og_image_chain: post override wins'
);
$check(
	'https://x/feat.png' === Options::og_image_chain( '', 'https://x/feat.png', 'https://x/def.png' ),
	'og_image_chain: featured used when no override'
);
$check(
	'https://x/def.png' === Options::og_image_chain( '', '', 'https://x/def.png' ),
	'og_image_chain: default used when no override + no featured'
);
$check(
	'' === Options::og_image_chain( '', '', '' ),
	'og_image_chain: empty when nothing set'
);
$check(
	'https://x/feat.png' === Options::og_image_chain( '   ', 'https://x/feat.png', 'https://x/def.png' ),
	'og_image_chain: whitespace-only override is skipped'
);

// ---------------------------------------------------------------------
// archive_noindex (settings-driven decision logic)
// ---------------------------------------------------------------------
$opts_idx = array(
	'noindex_search' => 1,
	'noindex_paged'  => 0,
	'noindex_author' => 0,
	'noindex_date'   => 0,
);
$check(
	true === Options::archive_noindex( array( 'is_search' => true ), $opts_idx ),
	'archive_noindex: search noindexed when enabled'
);
$check(
	false === Options::archive_noindex( array( 'is_paged' => true ), $opts_idx ),
	'archive_noindex: paged NOT noindexed when its flag is off'
);
$check(
	true === Options::archive_noindex( array( 'is_author' => true ), array( 'noindex_author' => 1 ) ),
	'archive_noindex: author noindexed when enabled'
);
$check(
	true === Options::archive_noindex( array( 'is_date' => true ), array( 'noindex_date' => 1 ) ),
	'archive_noindex: date noindexed when enabled'
);
$check(
	false === Options::archive_noindex( array(), $opts_idx ),
	'archive_noindex: nothing matches → not noindexed'
);
$check(
	true === Options::archive_noindex( array( 'is_search' => true, 'is_paged' => true ), array( 'noindex_search' => 0, 'noindex_paged' => 1 ) ),
	'archive_noindex: any matching rule triggers (paged here)'
);

// ---------------------------------------------------------------------
// chunk_entries (sitemap index splitting)
// ---------------------------------------------------------------------
$mk_entries = function ( $n ) {
	$e = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$e[] = array( 'loc' => 'https://x/' . $i, 'lastmod' => '' );
	}
	return $e;
};
$one = Options::chunk_entries( $mk_entries( 3 ), 5 );
$check( 1 === count( $one ), 'chunk_entries: small set → single chunk' );
$check( 3 === count( $one[0] ), 'chunk_entries: single chunk keeps all entries' );

$split = Options::chunk_entries( $mk_entries( 12 ), 5 );
$check( 3 === count( $split ), 'chunk_entries: 12 entries / 5 per → 3 chunks' );
$check( 5 === count( $split[0] ) && 5 === count( $split[1] ) && 2 === count( $split[2] ), 'chunk_entries: chunk sizes 5,5,2' );

$exact = Options::chunk_entries( $mk_entries( 10 ), 5 );
$check( 2 === count( $exact ), 'chunk_entries: exact multiple → 2 chunks' );

$empty = Options::chunk_entries( array(), 5 );
$check( 1 === count( $empty ) && array() === $empty[0], 'chunk_entries: empty set → one empty chunk' );

$clamped = Options::chunk_entries( $mk_entries( 4 ), 0 );
$check( 4 === count( $clamped ), 'chunk_entries: per<1 clamps to 1 (one chunk each)' );

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
$check( preg_match_all( '/./us', $truncated ) <= 50, 'summarize: stays within the character limit including ellipsis' );
$check( '…' === substr( $truncated, -3 ), 'summarize: appends an ellipsis when cut' );
$check( 'été…' === Options::summarize( 'étéétéété', 4 ), 'summarize: handles UTF-8 without mbstring' );
$check( '…' === Options::summarize( 'abc', 1 ), 'summarize: handles a one-character limit' );
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
$check( false !== strpos( $xml, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' ), 'build_xml: has urlset' );
$check( false !== strpos( $xml, 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' ), 'build_xml: declares the image namespace' );
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

// ---------------------------------------------------------------------
// New 0.2.0 setting defaults + precedence
// ---------------------------------------------------------------------
inkseo_test_reset();
$opts5 = new Options();
$check( 'organization' === $opts5->get( 'home_schema_type' ), 'options: home_schema_type defaults to organization' );
$check( '' === $opts5->get( 'twitter_site' ), 'options: twitter_site defaults to empty' );
$check( 1000 === (int) $opts5->get( 'sitemap_max_urls' ), 'options: sitemap_max_urls default is 1000' );
$check( true === $opts5->module_enabled( 'breadcrumbs' ), 'options: breadcrumbs default-enabled' );
$check( 1 === (int) $opts5->get( 'noindex_search' ), 'options: noindex_search default on' );
$check( 0 === (int) $opts5->get( 'noindex_paged' ), 'options: noindex_paged default off' );
$check( 1 === (int) $opts5->get( 'sitemap_taxonomies' ), 'options: sitemap_taxonomies default on' );

// New keys honour the same precedence chain as the originals.
$GLOBALS['_inkseo_multisite'] = true;
update_site_option( Options::NETWORK_OPTION, array( 'home_schema_type' => 'person', 'sitemap_max_urls' => 500 ) );
$opts6 = new Options();
$check( 'person' === $opts6->get( 'home_schema_type' ), 'options: network default applies to new keys' );
$check( 500 === (int) $opts6->get( 'sitemap_max_urls' ), 'options: network sitemap_max_urls applied' );

update_option( Options::OPTION, array( 'home_schema_type' => 'none' ) );
$opts7 = new Options();
$check( 'none' === $opts7->get( 'home_schema_type' ), 'options: per-blog overrides network for new keys' );
$check( 500 === (int) $opts7->get( 'sitemap_max_urls' ), 'options: unset per-blog new key inherits network value' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
