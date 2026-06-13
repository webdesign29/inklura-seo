# Changelog

All notable changes to **Inklura SEO** are documented here. This project adheres
to [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-06-13

Initial release.

### Added
- Front-end `<title>` control via a configurable format (`%title%`, `%sitename%`,
  `%tagline%`, `%sep%`) with a selectable separator glyph.
- `meta description`, `rel=canonical`, and per-post `noindex` robots directive,
  derived from per-post SEO fields or sensible defaults (site name/tagline,
  excerpt/content).
- Open Graph + Twitter Card tags (`og:title/description/type/url/image`,
  `twitter:card=summary_large_image`), using the featured image with a
  configurable default OG image fallback.
- Per-post **Inklura SEO** meta box (SEO title, meta description, noindex),
  saved with a nonce + capability check.
- XML sitemap at `/inklura-sitemap.xml` (rewrite rule + query var, flushed on
  activation), listing published posts + pages with `<lastmod>`, plus a
  `Sitemap:` line via the `robots_txt` filter.
- JSON-LD: `WebSite` schema on the home page, `Article` schema on singular posts.
- Settings page (Settings API): separator, title format, default OG image, and a
  per-module toggle.
- Multisite: per-blog settings + a network-admin defaults page (`get_site_option`).
- Self-hosted auto-updates for normal-plugin installs.

[0.1.0]: https://github.com/webdesign29/inklura-seo/releases/tag/v0.1.0
