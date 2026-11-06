=== Inklura SEO ===
Contributors: gr0b1
Tags: seo, sitemap, meta description, open graph, structured data
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress SEO made simple: control titles, meta descriptions, social sharing, XML sitemaps and schema. Every feature free. No account.

== Description ==

Give your content a clear title, a useful description and the metadata search engines and social networks need to understand it.

Inklura SEO brings the essential on-page and technical SEO controls into WordPress. Set defaults for your whole site, then fine-tune the posts and categories that matter. Every feature is included, with no account, subscription or license key required.

= Make each page easier to present =

* **Titles that stay consistent.** Build site-wide title templates, choose your separator and handle paginated pages.
* **Descriptions you control.** Write an SEO title and meta description for individual posts and public taxonomy archives, or let your defaults do the work.
* **Sharing images with a fallback.** Set an Open Graph image for a post, use its featured image, or fall back to a site-wide image. Open Graph and Twitter Card tags are included.

= Cover the technical essentials =

* **Canonical URLs and indexing controls.** Manage search results, author archives, date archives and paginated content from one settings page.
* **XML sitemaps that follow your content.** Include public posts, taxonomy archives, author archives and featured images. Larger sitemaps split into paginated files with an index.
* **Structured data built in.** Generate WebSite, Organization or Person, Article and BreadcrumbList JSON-LD data.
* **Breadcrumbs when you want them.** Add a visible navigation trail with the [inklura_breadcrumbs] shortcode or a template helper.

= Start with one site. Keep control across a network. =

Use the same straightforward settings on a single WordPress site or a multisite network. Network administrators can set shared defaults, while each site keeps its own overrides. Enable the modules you need and leave the rest switched off.

The interface follows your WordPress language settings, with English, French, German, Spanish, Italian and Brazilian Portuguese included.

= Every feature is free =

No premium-only modules, trial period or required external service. The WordPress.org edition receives its updates from WordPress.org and includes no external updater.

= Privacy =

The plugin does not collect analytics, send telemetry or contact an external API. Settings and metadata are stored in your WordPress database. Public metadata and sitemaps are derived from your published content. No account is required.

= Source and support =

Created and maintained by Benjamin Favre, in Brest, France.

* [Inklura](https://www.inklura.fr/) — the Inklura platform.
* [Inklura plugin catalogue](https://plugins.inklura.fr/) — extensions and documentation.
* [Source and support](https://github.com/webdesign29/inklura-seo) — source code and reproducible issue reports.

== Installation ==

1. Upload the inklura-seo directory to wp-content/plugins, or install the ZIP through Plugins > Add New.
2. Activate Inklura SEO.
3. Open Settings > Inklura SEO to choose the modules and defaults.
4. Edit a post or public taxonomy term to override its SEO metadata.
5. Visit /inklura-sitemap.xml to inspect your sitemap.

Avoid enabling overlapping SEO output in another SEO plugin at the same time; duplicate metadata can confuse crawlers. Choose one plugin to manage each output.

== Frequently Asked Questions ==

= Do I need an account or a paid plan? =

No. All included features are free. Inklura SEO runs on a standard WordPress installation without an Inklura account, subscription or license key.

= Can I use it with another SEO plugin? =

Choose one plugin to generate each type of SEO output. If you keep another SEO plugin active, disable overlapping title, social, sitemap or schema modules to avoid duplicate metadata.

= Which languages are included? =

English, French, German, Spanish, Italian and Brazilian Portuguese are bundled with the plugin. The interface uses your WordPress language settings.

= Does this guarantee search rankings? =

No. It manages technical metadata and sitemaps. Search engines decide how to crawl, index and rank your content.

= Does it support multisite? =

Yes. Settings are scoped to each site. Network administrators can configure defaults; individual site settings take precedence.

= What happens on deactivation or deletion? =

Deactivation retains settings and metadata. Deleting the plugin through WordPress removes its own settings and metadata, including network defaults on multisite. Back up any metadata you want to retain before deleting it.

== Screenshots ==

1. Set site-wide title templates, social sharing defaults and home-page structured data in one place.
2. Give an individual post its own SEO title, meta description and sharing image.
3. Choose what goes into your XML sitemaps, control archive indexing and enable only the modules you need.
4. Add a custom SEO title and meta description to a category or another public taxonomy term.
5. Set network-wide defaults while letting individual sites keep their own settings.

== Changelog ==

= 0.2.3 =
* Bundle French, German, Spanish, Italian and Brazilian Portuguese interface translations.

= 0.2.2 =
* Credit Benjamin Favre and add links to Inklura and the plugin catalogue.
* Fix duplicate canonical tags, paginated/archive URLs and posts-page metadata.
* Respect noindex and password protection in sitemap content and metadata.
* Query sitemap pages independently with no 5,000-post truncation; avoid per-archive date queries.
* Support UTF-8 descriptions without the PHP mbstring extension.
* Preserve WordPress title escaping, validate settings input and keep social/schema modules independent.
* Keep the breadcrumb template helper available when its module is disabled.

= 0.2.1 =
* Prepare the WordPress.org edition with native directory updates and no external updater.
* Add installation, privacy and coexistence documentation.

= 0.2.0 =
* Add sitemap pagination, taxonomy and author entries, breadcrumbs and expanded indexing controls.

= 0.1.0 =
* Initial release.
