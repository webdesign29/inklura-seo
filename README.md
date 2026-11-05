# Inklura SEO

[![CI](https://github.com/webdesign29/inklura-seo/actions/workflows/ci.yml/badge.svg)](https://github.com/webdesign29/inklura-seo/actions/workflows/ci.yml)
![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)
![PHP 7.4+](https://img.shields.io/badge/php-7.4%2B-8892bf.svg)
![WordPress 5.8+](https://img.shields.io/badge/wordpress-5.8%2B-21759b.svg)

> Lightweight, dependency-free SEO for WordPress — titles, social cards, a
> sitemap and structured data, without the bloat.

Created and maintained by **Benjamin Favre**. Discover
[Inklura](https://www.inklura.fr/) and the
[Inklura plugin catalogue](https://plugins.inklura.fr/).

`inklura-seo` is a small WordPress plugin (usable as a normal plugin or a
**must-use** plugin) that handles the SEO essentials and gets out of the way. No
upsells, no telemetry, no megabytes of dependencies — just the `<head>` tags,
the sitemap and the schema that search engines and social networks want.

Multisite-safe: every site in a network keeps its own settings, with optional
network-wide defaults.

## Features

| Module | What it does |
|---|---|
| **Titles & meta** | Controls the document `<title>` via a configurable format (`%title% %sep% %sitename%`, with `%tagline%` and `%page%`), plus `meta description`, `rel=canonical`, and a `noindex` robots directive. Falls back to the site name/tagline and the excerpt/content. `%page%` adds a "Page N" suffix on paginated views. |
| **Open Graph / Twitter** | `og:title/description/type/url/image` with `og:image:width`/`height`, a `twitter:card` set, and an optional `twitter:site` handle. The OG image follows a fallback chain: **per-post override → featured image → site default**. |
| **XML sitemap** | Serves `/inklura-sitemap.xml`. Above a configurable URL cap it splits into a real **sitemap index** of paginated sub-sitemaps. Covers posts/pages/CPTs, **taxonomy archives** (categories, tags, custom) and **author archives**, with accurate post `<lastmod>` and `<image:image>` for featured images. Advertised in `robots.txt`. |
| **JSON-LD schema** | `WebSite` + an **Organization or Person** entity on the home page (configurable name + logo); `Article` on singular posts; `BreadcrumbList` wherever a multi-level trail exists. |
| **Breadcrumbs** | A `BreadcrumbList` graph node plus an optional visible trail — `[inklura_breadcrumbs]` shortcode and a `\Inklura\SEO\inklura_seo_breadcrumbs()` template tag. |
| **Indexing controls** | One-click `noindex` for search results, paginated (page 2+) views, author archives and date archives. |
| **Per-post meta box** | An **Inklura SEO** box on the editor: SEO title, meta description, social-image (OG) override and a noindex checkbox — saved to postmeta with a nonce + capability check. |
| **Per-term SEO** | SEO title + meta description on the Add/Edit screens of every public taxonomy — saved to term meta with a nonce + capability check. |
| **Settings** | A **Settings → Inklura SEO** page (Settings API): separator, title format, default OG image, Twitter handle, home entity, breadcrumbs, sitemap tuning, indexing rules, and a toggle per module. |
| **Multisite** | Per-blog settings + a **Network → Inklura SEO** defaults page (`get_site_option`). Per-blog values win; unset keys inherit the network default. |
| **Auto-update** | Self-hosted updates for normal-plugin installs — "update available" + one-click update from a manifest, like a wordpress.org plugin. |

## Install

### As a normal plugin

Put the repository in `wp-content/plugins/inklura-seo/` and activate **Inklura
SEO**. Activation registers the sitemap rewrite rule and flushes rewrite rules so
`/inklura-sitemap.xml` resolves immediately.

### As a must-use plugin

Copy the package into `wp-content/mu-plugins/inklura-seo/` and load
`inklura-seo.php` from a loader. The auto-updater is disabled for must-use
installs (update by redeploying the package).

## Configure

Open **Settings → Inklura SEO** in wp-admin:

- **Title separator** — the glyph between title parts.
- **Title format** — tokens `%title%`, `%sitename%`, `%tagline%`, `%sep%`,
  `%page%`. `%page%` becomes "Page N" on paginated views (and is dropped on
  page 1); if you omit it, the suffix is appended automatically.
- **Social** — default OG image (fallback share image) and a Twitter / X
  `@site` handle (a handle or a profile URL — both work).
- **Home page entity (schema)** — emit an `Organization` or `Person` node on the
  home page, with a name (defaults to the site name) and a logo / image URL.
- **Breadcrumbs** — enable the trail + structured data, and set the home label.
- **Sitemap** — URLs per sitemap (the split threshold for the index), and
  whether to include taxonomy archives, author archives and featured images.
- **Indexing (robots)** — `noindex` search results, paginated pages, author
  archives and date archives.
- **Modules** — toggle Titles, Open Graph, Sitemap and Schema independently.

Per post, the **Inklura SEO** meta box overrides the title/description, sets a
social-image (OG) override and lets you mark the entry `noindex`. Per term, the
same box on the Add/Edit term screen sets an SEO title + description.

On multisite, **Network → Inklura SEO** sets defaults that every site inherits
until it overrides them.

### Breadcrumbs

Drop the shortcode into content or a block:

```text
[inklura_breadcrumbs separator="›"]
```

…or call the template tag from a theme template:

```php
<?php \Inklura\SEO\inklura_seo_breadcrumbs( array( 'separator' => '›' ) ); ?>
```

The matching `BreadcrumbList` JSON-LD is emitted automatically by the schema
module wherever the trail has more than one level.

### Constants

```php
define( 'INKLURA_SEO_ENABLE', false );                 // master kill switch
define( 'INKLURA_SEO_UPDATE_URL', 'https://…/update' ); // override update manifest
```

## Hooks

```php
// Adjust the current page of content sitemap entries ({ loc, lastmod, images? }).
add_filter( 'inklura/seo/sitemap_entries', function ( $entries ) { return $entries; } );

// Adjust the sitemap-index spec list (array of { loc, lastmod }).
add_filter( 'inklura/seo/sitemap_index', function ( $specs ) { return $specs; } );

// Override the update manifest URL.
add_filter( 'inklura/inklura-seo/update_manifest_url', function ( $url ) { return $url; } );

// Run code after the plugin has booted.
add_action( 'inklura/seo/booted', function ( $plugin ) {} );
```

## Testing

```bash
for t in tests/unit/*.php; do php "$t"; done   # WP-free unit tests
```

CI lints and runs unit tests on PHP 7.4/8.1/8.3/8.5. The WordPress.org
package is also installed in native WordPress fixtures and tested for metadata,
permissions, indexing and full sitemap coverage above 5,000 posts. Integration
tests require an isolated database named `inklura_seo_audit`:

```bash
python3 bin/build-wordpress-org.py
wp plugin install dist/wordpress-org/inklura-seo.zip --force --activate
INKLURA_SEO_LARGE_TEST=1 wp eval-file tests/integration.php
```

The sitemap index uses counts; each content/taxonomy/author request loads only
its configured page. Optional modification dates are emitted for content, and
omitted for taxonomy/author archives where no reliable date is available. With
plain permalinks, the settings page and robots.txt advertise query-string sitemap
URLs. Posts marked noindex and password-protected content are omitted. Settings
must still be coordinated with any other SEO plugins active on the same site.

## Sponsors

inklura-seo is built and maintained with the support of:

- **[webdesign29](https://webdesign29.net)** — web agency (Brest, France).
- **[Inklura](https://www.inklura.fr/)** — WordPress-compatible CMS & hosting.

Interested in sponsoring? Use the **Sponsor** button or open an issue.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
