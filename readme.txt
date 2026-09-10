=== Image Socialiser ===
Contributors: krafit
Tags: open graph, og image, social image, social media, seo
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.0-beta.3
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generates branded Open Graph images for your posts from a site-wide template — rendered on your server, served as static files.

== Description ==

Every post gets a proper social sharing image, without you designing one by hand for each post and without sending your content to some external image service. Image Socialiser renders the image on your own server whenever you save a post: your brand colours, your logo, the post title neatly wrapped and sized to fit.

A few things it does differently:

* **Crawlers never trigger image rendering.** The `og:image` URL always points at a real, static PNG in your uploads directory. Rendering happens in a background queue after you save.
* **Filenames are content-addressed.** Change the title, the template, or your brand colours, and the file gets a new name — caches invalidate themselves, no query-string tricks.
* **It works with the SEO plugin you already have.** The SEO Framework, Rank Math, Yoast SEO, All in One SEO (4.x), and SEOPress are supported through their own APIs; without any of them, the plugin prints the meta tags itself. Podlove Podcast Publisher episode pages are handled too, and generated images become oEmbed embed thumbnails.
* **The editor preview is instant.** A panel in the block editor renders the exact same template in your browser — same layout, same bundled fonts — so what you see is what gets generated. You can set a custom image title and subtitle, or override the image entirely.
* **No external services.** No API keys, no per-image fees, no runtime HTTP calls. Rendering uses Imagick (with a GD fallback) and a bundle of open-licensed fonts — or your own uploaded TTF/OTF files and fonts installed through the WordPress Font Library.
* **A Design Center to make it yours.** Pick from nine built-in designs, set brand colours, fonts and logo, and see a live gallery that updates as you edit. Override individual colours or fonts for a single design, and — for theme and plugin authors — ship complete designs as declarative design packs.

It also covers taxonomy archives, post type archives, and the special pages (front page, blog index, search, 404) — each with its own toggle, template, and optional custom title and subtitle — and comes with nine built-in designs to pick from.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it.
2. Visit **Settings → Social Images** to set your brand colours and logo. That's optional — the defaults work out of the box.
3. Save a post. The image is generated in the background and lands in `wp-content/uploads/og-images/`.

For existing content, run the **Regenerate all images** button in the settings, or `wp image-socialiser regenerate --all` on the command line.

== Frequently Asked Questions ==

= Which image renderer does the plugin use? =

Imagick, when the PHP extension is available — that's the full-fidelity path with gradients and precise text fitting. Without Imagick, it falls back to GD with slightly reduced fidelity. The **Health** section in the settings shows which renderer is active on your host.

= Does it work with my SEO plugin? =

The SEO Framework, Rank Math, Yoast SEO, All in One SEO (4.x), and SEOPress have dedicated integrations that use each plugin's own filters. If none of them is active, Image Socialiser prints the `og:image` and `twitter:image` tags itself. If you run another SEO plugin that outputs its own tags, the native output stays off to avoid duplicates.

= Can I change which post types get images? =

Yes — every public post type can be switched on or off under **Settings → Social Images**, with an optional fallback image and default template per type.

= Where do the generated files live? =

In `wp-content/uploads/og-images/`, as plain files (not media library attachments), with long-lived immutable cache headers. Orphaned files are swept daily, and a post's files are removed when the post is permanently deleted.

= Is the plugin translated? =

English and German (both formal and informal) are bundled. The plugin is fully translation-ready for other languages.

== Changelog ==

= 1.0.0 =
* First stable release