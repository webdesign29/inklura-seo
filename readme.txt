=== Inklura SEO ===
Contributors: gr0b1
Tags: seo, sitemap, meta description, open graph, structured data
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage SEO titles, descriptions, social previews, XML sitemaps, structured data and breadcrumbs. No account or subscription required.

== Description ==

Inklura SEO provides search and social metadata for WordPress sites:

* Document title templates, canonical URLs and meta descriptions.
* Open Graph and Twitter Card metadata with social image fallbacks.
* XML sitemaps for public content, taxonomy archives and author archives, split into an index when needed.
* WebSite, Organization or Person, Article and BreadcrumbList structured data.
* Per-post and per-term title and description overrides.
* Indexing controls for search results, author archives, date archives and pagination.
* Optional visible breadcrumbs with the [inklura_breadcrumbs] shortcode.
* Per-site settings and optional defaults on multisite networks.

All features are included. There is no paid unlock, license key, trial period or required external service. The WordPress.org edition uses WordPress.org updates and includes no external updater.

= Privacy =

The plugin does not collect analytics, send telemetry or contact an external API. Settings and metadata are stored in your WordPress database. Public metadata and sitemaps are derived from your published content. No account is required.

= Source and support =

The source is available at https://github.com/webdesign29/inklura-seo. You can report reproducible issues there. Inklura is published by Webdesign29 in Brest, France.

== Installation ==

1. Upload the inklura-seo directory to wp-content/plugins, or install the ZIP through Plugins > Add New.
2. Activate Inklura SEO.
3. Open Settings > Inklura SEO to choose the modules and defaults.
4. Edit a post or public taxonomy term to override its SEO metadata.
5. Visit /inklura-sitemap.xml to inspect your sitemap.

Avoid enabling overlapping SEO output in another SEO plugin at the same time; duplicate metadata can confuse crawlers. Choose one plugin to manage each output.

== Frequently Asked Questions ==

= Does this require bext, WooCommerce or an Inklura subscription? =

No. Inklura SEO runs on a standard WordPress installation. It does not require another Inklura product or an external account.

= Does this guarantee search rankings? =

No. It manages technical metadata and sitemaps. Search engines decide how to crawl, index and rank your content.

= Does it support multisite? =

Yes. Settings are scoped to each site. Network administrators can configure defaults; individual site settings take precedence.

= What happens on deactivation or deletion? =

Deactivation retains settings and metadata. Deleting the plugin through WordPress removes its own settings and metadata, including network defaults on multisite. Back up any metadata you want to retain before deleting it.

== Changelog ==

= 0.2.1 =
* Prepare the WordPress.org edition with native directory updates and no external updater.
* Add installation, privacy and coexistence documentation.

= 0.2.0 =
* Add sitemap pagination, taxonomy and author entries, breadcrumbs and expanded indexing controls.

= 0.1.0 =
* Initial release.
