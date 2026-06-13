# Inklura SEO

[![CI](https://github.com/webdesign29/inklura-seo/actions/workflows/ci.yml/badge.svg)](https://github.com/webdesign29/inklura-seo/actions/workflows/ci.yml)
![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)
![PHP 7.4+](https://img.shields.io/badge/php-7.4%2B-8892bf.svg)
![WordPress 5.8+](https://img.shields.io/badge/wordpress-5.8%2B-21759b.svg)

> Lightweight, dependency-free SEO for WordPress — titles, social cards, a
> sitemap and structured data, without the bloat.

`inklura-seo` is a small WordPress plugin (usable as a normal plugin or a
**must-use** plugin) that handles the SEO essentials and gets out of the way. No
upsells, no telemetry, no megabytes of dependencies — just the `<head>` tags,
the sitemap and the schema that search engines and social networks want.

Multisite-safe: every site in a network keeps its own settings, with optional
network-wide defaults.

## Features

| Module | What it does |
|---|---|
| **Titles & meta** | Controls the document `<title>` via a configurable format (`%title% %sep% %sitename%`, with `%tagline%`), plus `meta description`, `rel=canonical`, and a per-post `noindex` robots directive. Falls back to the site name/tagline and the excerpt/content. |
| **Open Graph / Twitter** | `og:title/description/type/url/image` (from the featured image, or a configurable default), and a `twitter:card = summary_large_image` set. |
| **XML sitemap** | Serves `/inklura-sitemap.xml` (rewrite rule + query var, no physical file) listing published posts + pages with `<lastmod>`, and advertises it in `robots.txt`. |
| **JSON-LD schema** | `WebSite` schema on the home page; `Article` schema on singular posts. |
| **Per-post meta box** | An **Inklura SEO** box on the editor: SEO title, meta description and a noindex checkbox — saved to postmeta with a nonce + capability check. |
| **Settings** | A **Settings → Inklura SEO** page (Settings API): separator, title format, default OG image, and a toggle per module. |
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
- **Title format** — tokens `%title%`, `%sitename%`, `%tagline%`, `%sep%`.
- **Default OG image** — used when a post has no featured image.
- **Modules** — toggle Titles, Open Graph, Sitemap and Schema independently.

Per post, the **Inklura SEO** meta box overrides the title/description and lets
you mark the entry `noindex`.

On multisite, **Network → Inklura SEO** sets defaults that every site inherits
until it overrides them.

### Constants

```php
define( 'INKLURA_SEO_ENABLE', false );                 // master kill switch
define( 'INKLURA_SEO_UPDATE_URL', 'https://…/update' ); // override update manifest
```

## Hooks

```php
// Adjust the sitemap entry list (array of { loc, lastmod }).
add_filter( 'inklura/seo/sitemap_entries', function ( $entries ) { return $entries; } );

// Override the update manifest URL.
add_filter( 'inklura/inklura-seo/update_manifest_url', function ( $url ) { return $url; } );

// Run code after the plugin has booted.
add_action( 'inklura/seo/booted', function ( $plugin ) {} );
```

## Testing

```bash
for t in tests/unit/*.php; do php "$t"; done   # WP-free unit tests
```

CI lints on PHP 7.4/8.1/8.3 and runs the unit tests.

## Sponsors

inklura-seo is built and maintained with the support of:

- **[webdesign29](https://webdesign29.net)** — web agency (Brest, France).
- **[Inklura](https://inklura.fr)** — WordPress-compatible CMS & hosting.

Interested in sponsoring? Use the **Sponsor** button or open an issue.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
