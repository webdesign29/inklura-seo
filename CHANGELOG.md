# Changelog

All notable changes to **Inklura SEO** are documented here. This project adheres
to [Semantic Versioning](https://semver.org/).

## 0.2.3

- Bundle French, German, Spanish, Italian and Brazilian Portuguese interface catalogues, with English fallback.
- Register bundled translations at init for normal and must-use installations; retain WordPress.org language-pack priority.
- Translate default breadcrumb labels and accessibility text while retaining saved custom labels.

## 0.2.2

- Credit Benjamin Favre and link the Inklura platform and plugin catalogue.
- Fix duplicate core/plugin canonical tags and self-canonicals for paginated posts, blog indexes and archives.
- Escape custom document titles through WordPress's normal title filters; protect password-gated content from metadata disclosure.
- Replace whole-site sitemap collection with database pagination and count queries. Include all eligible content beyond 5,000 posts; exclude noindex, drafts, protected posts and noindex author archives.
- Omit unknown archive modification dates instead of running one lastmod query per archive. Post lastmod remains accurate.
- Return 404 for invalid, disabled and nonpublic sitemap requests; support plain and pretty permalink URLs and avoid overlapping core sitemaps.
- Remove the mbstring dependency for UTF-8 descriptions, handle malformed settings and preserve literal backslashes.
- Keep Open Graph/schema descriptions independent of the title module, honor the configured schema publisher, and keep the breadcrumb helper callable when disabled.
- Add native WordPress integration, security and large-site regression coverage, plus PHP 7.4–8.5 compatibility checks.

## [0.2.0] - 2026-06-13

A big SEO-coverage release: breadcrumbs, richer schema, a real sitemap index,
per-term meta, finer indexing control and better social cards.

### Added
- **Breadcrumbs** — a `BreadcrumbList` JSON-LD node plus an optional visible
  trail via the `[inklura_breadcrumbs]` shortcode and the
  `\Inklura\SEO\inklura_seo_breadcrumbs()` template tag. Configurable home label.
- **Home-page entity schema** — emit an `Organization` *or* `Person` node on the
  home page, with a configurable name (defaults to the site name) and logo /
  image URL.
- **Sitemap index** — when a set exceeds the configurable per-sitemap URL cap,
  the sitemap splits into a real `<sitemapindex>` of paginated sub-sitemaps. Now
  also includes **public taxonomy archives** (categories, tags, custom) and
  **author archives**, an `<image:image>` block for featured images, and an
  accurate `<lastmod>` (terms/authors use their newest published post).
- **Per-term SEO meta** — an SEO title + meta description on the Add/Edit term
  screens of every public taxonomy (term meta, nonce + capability checked).
- **Indexing controls** — settings to `noindex` search results, paginated
  (page 2+) views, author archives and date archives.
- **Open Graph improvements** — `og:image:width`/`height`, a per-post OG image
  override, and a fallback chain: per-post override → featured image → default.
- **Twitter `@site` handle** setting (output as `twitter:site`; accepts a handle
  or a profile URL).
- **`%page%` title token** — paginated views get a "Page N" suffix; the token is
  dropped cleanly on page 1, or appended automatically when the format omits it.

### Changed
- Term archives now honour a per-term SEO title / description for the document
  title and meta description (falling back to the term description).
- Settings and the network-defaults page expose all of the above; new keys
  follow the same defaults ⊕ network ⊕ per-blog precedence.
- Uninstall now also removes the per-post OG-image meta and per-term SEO meta.

[0.2.0]: https://github.com/webdesign29/inklura-seo/releases/tag/v0.2.0

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
