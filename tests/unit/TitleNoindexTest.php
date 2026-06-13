<?php
/**
 * Focused coverage of:
 *   - title formatting: every separator glyph, format-token combinations,
 *     pagination edge cases
 *   - robots / noindex decision logic: a full truth table over the four
 *     settings-driven archive rules
 *
 * Run: php tests/unit/TitleNoindexTest.php
 *
 * @package Inklura\SEO
 */

require __DIR__ . '/../bootstrap.php';

require __DIR__ . '/../../src/Options.php';

use Inklura\SEO\Options;

$failures = 0;
$check    = function ( $cond, $label ) use ( &$failures ) {
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$failures;
	}
};

// ---------------------------------------------------------------------
// Every configured separator glyph round-trips through format_title.
// ---------------------------------------------------------------------
foreach ( Options::separators() as $key => $glyph ) {
	$out      = Options::format_title( '%title% %sep% %sitename%', 'Page', 'Site', $glyph );
	$expected = 'Page ' . $glyph . ' Site';
	$check( $expected === $out, 'format_title: glyph "' . $key . '" (' . $glyph . ') renders as "' . $expected . '"' );
}

// ---------------------------------------------------------------------
// Format-token combinations.
// ---------------------------------------------------------------------
$check(
	'Brand: Page' === Options::format_title( '%sitename%: %title%', 'Page', 'Brand', '-' ),
	'format_title: literal text between tokens is preserved'
);
$check(
	'Page' === Options::format_title( '%title%', 'Page', 'Brand', '-' ),
	'format_title: title-only format'
);
$check(
	'Brand' === Options::format_title( '%sitename%', '', 'Brand', '-' ),
	'format_title: sitename-only format'
);
$check(
	'Page - Tagline - Brand' === Options::format_title( '%title% %sep% %tagline% %sep% %sitename%', 'Page', 'Brand', '-', 'Tagline' ),
	'format_title: title + tagline + sitename all present'
);
// All-empty resolvable tokens collapse to ''.
$check(
	'' === Options::format_title( '%title% %sep% %sitename%', '', '', '-' ),
	'format_title: everything empty → empty string'
);
// Leading + trailing separator both stripped.
$check(
	'Mid' === Options::format_title( '%title% %sep% %sitename% %sep% %tagline%', 'Mid', '', '-', '' ),
	'format_title: separators around a lone middle token are stripped'
);

// ---------------------------------------------------------------------
// Pagination edge cases.
// ---------------------------------------------------------------------
// Page 0 / negative treated as page 1.
$check(
	'Page - Site' === Options::format_title_paged( '%title% %sep% %sitename%', 'Page', 'Site', '-', '', 0 ),
	'format_title_paged: page 0 behaves like page 1'
);
// Front-page-style (empty title) + %page% on page 2.
$check(
	'Site - Page 2' === Options::format_title_paged( '%title% %sep% %sitename% %sep% %page%', '', 'Site', '-', '', 2 ),
	'format_title_paged: empty title + page 2 keeps a clean suffix'
);
// %page% only, page 1 → empty (no stray glyph).
$check(
	'' === Options::format_title_paged( '%page%', '', '', '-', '', 1 ),
	'format_title_paged: lone %page% on page 1 is empty'
);
// %page% only, page 5.
$check(
	'Page 5' === Options::format_title_paged( '%page%', '', '', '-', '', 5 ),
	'format_title_paged: lone %page% on page 5'
);
// High page number.
$check(
	'A - B - Page 99' === Options::format_title_paged( '%title% %sep% %sitename%', 'A', 'B', '-', '', 99 ),
	'format_title_paged: large page number'
);

// ---------------------------------------------------------------------
// noindex decision logic — full truth table.
// ---------------------------------------------------------------------
// All rules on.
$all_on = array(
	'noindex_search' => 1,
	'noindex_paged'  => 1,
	'noindex_author' => 1,
	'noindex_date'   => 1,
);
// All rules off.
$all_off = array(
	'noindex_search' => 0,
	'noindex_paged'  => 0,
	'noindex_author' => 0,
	'noindex_date'   => 0,
);

$contexts = array(
	'is_search' => 'search results',
	'is_paged'  => 'paginated page',
	'is_author' => 'author archive',
	'is_date'   => 'date archive',
);

// With all rules ON, each single context triggers noindex.
foreach ( $contexts as $ctx_key => $ctx_label ) {
	$check(
		true === Options::archive_noindex( array( $ctx_key => true ), $all_on ),
		'noindex: ' . $ctx_label . ' → noindex when its rule is ON'
	);
}
// With all rules OFF, no single context triggers noindex.
foreach ( $contexts as $ctx_key => $ctx_label ) {
	$check(
		false === Options::archive_noindex( array( $ctx_key => true ), $all_off ),
		'noindex: ' . $ctx_label . ' → indexable when its rule is OFF'
	);
}

// Per-rule isolation: only the matching rule matters.
$only_author = array( 'noindex_search' => 0, 'noindex_paged' => 0, 'noindex_author' => 1, 'noindex_date' => 0 );
$check( false === Options::archive_noindex( array( 'is_search' => true ), $only_author ), 'noindex: search not affected by author rule' );
$check( true === Options::archive_noindex( array( 'is_author' => true ), $only_author ), 'noindex: author rule fires in isolation' );

// Combined contexts: noindex if ANY enabled rule matches.
$check(
	true === Options::archive_noindex(
		array( 'is_author' => true, 'is_paged' => true ),
		array( 'noindex_author' => 0, 'noindex_paged' => 1 )
	),
	'noindex: author+paged, only paged enabled → noindex'
);
$check(
	false === Options::archive_noindex(
		array( 'is_author' => true, 'is_paged' => true ),
		array( 'noindex_author' => 0, 'noindex_paged' => 0 )
	),
	'noindex: author+paged, neither enabled → indexable'
);
// No context flags at all → never noindex.
$check( false === Options::archive_noindex( array(), $all_on ), 'noindex: no context → indexable even with all rules on' );
// Missing opts keys default to off (empty()).
$check( false === Options::archive_noindex( array( 'is_search' => true ), array() ), 'noindex: missing opts keys treated as off' );

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit( $failures ? 1 : 0 );
