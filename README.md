# Celestial Sitemap v3.5.1

Enterprise-grade WordPress SEO plugin.

## Directory Structure

```
celestial-sitemap/
├── celestial-sitemap.php          # Bootstrap (autoloader, constants, hooks)
├── uninstall.php                  # Clean removal
├── includes/
│   ├── Plugin.php                 # Singleton orchestrator
│   ├── Core/
│   │   ├── Activator.php          # DB tables, default options, rewrite rules
│   │   ├── Deactivator.php        # Transient cleanup, cron removal
│   │   ├── Options.php            # Typed option accessors
│   │   └── NotFoundLogger.php     # 404 upsert logger
│   ├── Admin/
│   │   ├── AdminController.php    # Menu, meta boxes, AJAX, asset enqueue
│   │   ├── TaxonomyFields.php     # Term meta SEO fields (add/edit screens)
│   │   └── ConflictDetector.php   # Competing SEO plugin detection + admin notice
│   ├── SEO/
│   │   ├── HeadManager.php        # Title, meta description, OGP
│   │   ├── CanonicalManager.php   # Canonical URL, prev/next, paginated archives
│   │   └── RobotsManager.php      # noindex rules, robots.txt
│   ├── Schema/
│   │   └── SchemaManager.php      # Article, WebPage (all CPTs), BreadcrumbList, Organization
│   ├── Sitemap/
│   │   └── SitemapRouter.php      # XML sitemaps, XSL, caching, invalidation
│   └── Redirect/
│       └── RedirectManager.php    # 301/302/307/308 redirect engine
├── templates/
│   ├── admin/
│   │   ├── dashboard.php          # Settings page
│   │   ├── redirects.php          # Redirect management
│   │   └── 404-log.php            # 404 log viewer
│   └── sitemap-xsl.php            # XSLT 1.0 stylesheet
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
└── languages/
    └── celestial-sitemap.pot
```

## Dependency Graph

```
Plugin (singleton)
 ├── Options ─────────────────┐
 ├── CanonicalManager ←───────┤ (reads Options)
 ├── SchemaManager ←──────────┤
 ├── HeadManager ←────────────┤ (unified head builder, receives CanonicalManager + SchemaManager)
 ├── RobotsManager ←──────────┤
 ├── SitemapRouter ←──────────┤
 ├── RedirectManager          │ (standalone, no Options dep)
 ├── NotFoundLogger           │ (standalone)
 └── [admin only]             │
     ├── AdminController ←────┘ (reads/writes Options, uses RedirectManager, NotFoundLogger, SitemapRouter)
     ├── TaxonomyFields        (standalone – registers term meta fields for all public taxonomies)
     └── ConflictDetector      (standalone – detects Yoast, RankMath, AIOSEO, SEOPress)
```

No circular dependencies. All modules receive dependencies via constructor injection.
HeadManager orchestrates CanonicalManager and SchemaManager — they no longer hook into `wp_head` independently.

## Hook Registration Summary

| Hook                        | Callback                            | Priority |
|-----------------------------|-------------------------------------|----------|
| `plugins_loaded`            | `Plugin::boot()`                    | 10       |
| `init`                      | `SitemapRouter::addRewriteRules`    | 1        |
| `admin_init`                | `TaxonomyFields::registerTaxonomyHooks` | 10  |
| `template_redirect`         | `RedirectManager::handleRedirect`   | 1        |
| `template_redirect`         | `HeadManager::collect`              | 10       |
| `template_redirect`         | `SitemapRouter::handleRequest`      | 10       |
| `template_redirect`         | `NotFoundLogger::log404`            | 99       |
| `wp_head`                   | `HeadManager::outputHead`           | 1        |
| `pre_get_document_title`    | `HeadManager::filterTitle`          | 99       |
| `document_title_parts`      | `HeadManager::filterTitleParts`     | 99       |
| `document_title_separator`  | `HeadManager::filterSeparator`      | 99       |
| `wp_robots`                 | `HeadManager::filterRobotsDirectives` | 50     |
| `wp_robots`                 | `RobotsManager::filterRobots`       | 99       |
| `robots_txt`                | `RobotsManager::filterRobotsTxt`    | 99       |
| `save_post`                 | `SitemapRouter::invalidateOnSave`   | 10       |
| `save_post`                 | `AdminController::saveMetaBox`      | 10       |
| `delete_post`               | `SitemapRouter::invalidateAll`      | 10       |
| `created_term`              | `SitemapRouter::invalidateAll`      | 10       |
| `edited_term`               | `SitemapRouter::invalidateAll`      | 10       |
| `delete_term`               | `SitemapRouter::invalidateAll`      | 10       |
| `admin_menu`                | `AdminController::registerMenu`     | 10       |
| `admin_enqueue_scripts`     | `AdminController::enqueueAssets`    | 10       |
| `admin_notices`             | `ConflictDetector::maybeShowNotice` | 10       |
| `query_vars`                | `SitemapRouter::addQueryVars`       | 10       |
| `cel_pregenerate_sitemaps`  | `SitemapRouter::pregenerateSitemaps`| 10       |
| `{$taxonomy}_add_form_fields`  | `TaxonomyFields::renderAddFields`  | 10    |
| `{$taxonomy}_edit_form_fields` | `TaxonomyFields::renderEditFields` | 10    |
| `created_{$taxonomy}`       | `TaxonomyFields::saveFields`        | 10       |
| `edited_{$taxonomy}`        | `TaxonomyFields::saveFields`        | 10       |

Total: 29 hook registrations.

**Unified head builder flow:**
1. `HeadManager::collect()` at `template_redirect` (priority 10) — resolves description, canonical, OGP, schema in a single pass
2. `HeadManager::outputHead()` at `wp_head` (priority 1) — echoes the pre-built HTML fragment (zero DB queries at render time)

CanonicalManager and SchemaManager provide `getCanonicalHtml()` / `getSchemaHtml()` called internally by HeadManager — they do not hook `wp_head` independently.

## Extensibility Filters

Third-party code can modify plugin output via `apply_filters()`:

| Filter Name                   | Type              | Description                                      |
|-------------------------------|-------------------|--------------------------------------------------|
| `cel_head_title`              | `string`          | Resolved document title                          |
| `cel_head_description`        | `string`          | Resolved meta description                        |
| `cel_head_ogp`                | `array<string,string>` | Open Graph / Twitter Card tag array         |
| `cel_canonical_url`           | `string`          | Resolved canonical URL                           |
| `cel_robots_directives`       | `array<string,bool\|string>` | Robots meta directives array        |
| `cel_schema_website`          | `array`           | WebSite JSON-LD schema                           |
| `cel_schema_organization`     | `array`           | Organization/Person JSON-LD schema               |
| `cel_schema_article`          | `array`           | Article JSON-LD schema (receives `$post`)        |
| `cel_schema_webpage`          | `array`           | WebPage JSON-LD schema (receives `$post`)        |
| `cel_schema_breadcrumbs`      | `array`           | BreadcrumbList JSON-LD schema                    |
| `cel_schema_type`             | `string`          | Schema @type for singular CPTs (receives post type) |
| `cel_sitemap_post_urls`       | `array`           | Sitemap post type URL array (receives post type) |
| `cel_sitemap_taxonomy_urls`   | `array`           | Sitemap taxonomy URL array (receives taxonomy)   |
| `cel_sitemap_news_urls`       | `array`           | News sitemap URL array                           |
| `cel_sitemap_video_urls`      | `array`           | Video sitemap URL array                          |
| `cel_sitemap_image_urls`      | `array`           | Image sitemap URL array                          |

### Filter Examples

```php
// Change schema type for WooCommerce products
add_filter('cel_schema_type', function (string $type, string $postType): string {
    if ($postType === 'product') {
        return 'Product';
    }
    return $type;
}, 10, 2);

// Add custom OGP tags (fb:app_id and twitter:site are now built-in settings)
add_filter('cel_head_ogp', function (array $og): array {
    $og['og:video'] = 'https://example.com/video.mp4';
    return $og;
});

// Override canonical URL for specific conditions
add_filter('cel_canonical_url', function (string $url): string {
    if (is_tax('product_cat')) {
        return home_url('/shop/');
    }
    return $url;
});
```

## DB Usage

**Custom Tables (created on activation via `dbDelta`):**
- `{prefix}cel_redirects` — redirect rules
- `{prefix}cel_404_log` — 404 error log (deduped by URL)

**Options:**
- `cel_*` — all prefixed, ~24 options
- Frequently-read options (title, noindex, schema, OGP flags) are `autoload=true`
- Infrequently-read options (GSC credentials, sitemap lists) are `autoload=false`
- All options are bulk-loaded in a single query on first access via `Options::loadAll()`

**Post Meta:**
- `_cel_title`, `_cel_description`, `_cel_canonical`, `_cel_og_image`, `_cel_noindex`

**Term Meta:**
- `_cel_title`, `_cel_description`, `_cel_canonical`, `_cel_noindex`

**Transients:**
- `cel_sm_v{N}_*` — versioned sitemap cache (1 hour TTL, invalidated via version counter)
- `cel_redirect_compiled` — compiled redirect map (exact/prefix/regex, 1 hour TTL, ≤10,000 entries)
- `cel_404_rate_count` — 404 rate limiter counter (60s window)
- `cel_404_row_count` — 404 table row count cache (5 min TTL)

**File Cache:**
- `wp-content/cache/cel-sitemaps/{blog_id}/` — pre-generated sitemap XML files (multisite-safe, per-site subdirectory)

## Term Meta Admin UI

SEO fields are available on all public taxonomy add/edit screens:

- **SEO Title** — Custom title tag for the term archive
- **Meta Description** — Custom meta description for the term archive
- **Canonical URL** — Custom canonical URL override
- **Noindex** — Prevent search engines from indexing this term archive

Fields are rendered via `{$taxonomy}_add_form_fields` and `{$taxonomy}_edit_form_fields`.
Saved via `created_{$taxonomy}` and `edited_{$taxonomy}` with nonce verification and sanitization.

## Archive Pagination Canonical Strategy

Paginated archive pages (taxonomy, post type, author, home) use a **self-referencing canonical**:

- `/category/news/` → canonical: `/category/news/`
- `/category/news/page/2/` → canonical: `/category/news/page/2/`
- `/category/news/page/3/` → canonical: `/category/news/page/3/`

This ensures paginated pages are not incorrectly canonicalized to page 1.
`rel="prev"` and `rel="next"` links are output for both singular paginated posts and paginated archives.

## Competing SEO Plugin Detection

On admin pages, the plugin checks for:

| Plugin        | Detection Method                                  |
|---------------|---------------------------------------------------|
| Yoast SEO     | `defined('WPSEO_VERSION')` or `class_exists('WPSEO_Options')` |
| Rank Math     | `defined('RANK_MATH_VERSION')` or `class_exists('RankMath')` |
| All in One SEO| `defined('AIOSEO_VERSION')` or `function_exists('aioseo')` |
| SEOPress      | `defined('SEOPRESS_VERSION')` or `function_exists('seopress_init')` |

If detected, a warning notice is displayed. The plugin does **not** automatically deactivate
competing plugins or remove their hooks.

## Custom Post Type Schema Support

All public singular post types receive structured data:

- **`post`** — Article schema (unchanged)
- **`page`** — WebPage schema (unchanged)
- **Any other public CPT** — WebPage schema by default

Use the `cel_schema_type` filter to set CPT-specific schema types:

```php
add_filter('cel_schema_type', function (string $type, string $postType): string {
    return match ($postType) {
        'product' => 'Product',
        'event'   => 'Event',
        'recipe'  => 'Recipe',
        default   => $type,
    };
}, 10, 2);
```

The full schema array can be further customized via `cel_schema_webpage`.

## Security Model

1. **Nonce verification** — every AJAX handler calls `check_ajax_referer()`, meta boxes and term fields use `wp_verify_nonce()`
2. **Capability check** — `current_user_can('manage_options')`, `edit_post`, or `manage_categories`
3. **Input sanitization** — `sanitize_text_field`, `esc_url_raw`, `absint`, `sanitize_textarea_field`
4. **URL field validation** — canonical and OG image fields use `esc_url_raw()` (rejects `javascript:` URIs)
5. **Output escaping** — `esc_html`, `esc_attr`, `esc_url` throughout templates
6. **SQL injection prevention** — all queries via `$wpdb->prepare()`
7. **XSS prevention** — no raw `$_GET`/`$_POST` in output; all escaped
8. **CSRF prevention** — nonce on all forms and AJAX
9. **Credential encryption** — GSC client secret and access token encrypted via AES-256-CBC with random IV and `wp_salt()`-derived key
10. **Password-protected content** — meta descriptions not auto-generated for password-protected posts
11. **Options key whitelist** — `Options::set()` only accepts known `cel_*` keys
12. **404 rate limiting** — prevents attackers from flooding the database with unique 404 URLs

## Performance Design

1. **Zero frontend JS** — no scripts enqueued on public pages
2. **Zero frontend CSS** — no stylesheets on public pages
3. **Admin assets conditional** — loaded only on plugin pages, post editor, and taxonomy screens
4. **Two-tier sitemap caching** — file cache (zero DB queries) + transient fallback, 1 hour TTL, versioned cache keys
5. **Sitemap pre-generation** — cron-based background pre-generation (debounced 30s after content changes) ensures frontend requests are instant
6. **Sitemap batch queries** — `_prime_post_caches()` + `update_post_meta_cache()` eliminates N+1 queries
7. **ETag + 304** — sitemaps support conditional requests
8. **Unified head builder** — `HeadManager::collect()` resolves all metadata (description, canonical, OGP, schema) in a single pass at `template_redirect`; `outputHead()` at `wp_head` outputs the pre-built HTML with zero DB queries
9. **Options bulk-loaded** — all `cel_*` options loaded in a single DB query, cached in memory per request
10. **Compiled redirect map** — three match types (exact O(1) hash, prefix longest-first, regex PCRE) cached in a single transient; direct indexed DB fallback for tables >10,000 entries
11. **Versioned cache invalidation** — sitemap caches invalidated by incrementing a version counter (no LIKE queries)
12. **Multisite-safe file cache** — per-site cache directory (`cel-sitemaps/{blog_id}/`) prevents cross-site collisions
11. **404 dedup** — `ON DUPLICATE KEY UPDATE` avoids row explosion
12. **404 rate limiting** — transient-based per-minute rate limiter prevents log flooding
13. **404 table cap** — configurable max row count prevents unbounded growth
14. **90-day auto-purge** — cron-based cleanup of old 404 entries
15. **WP core sitemaps disabled** — prevents duplicate sitemap systems

## Changelog

### 3.5.1

**Bug Fix — Settings not reflected in sitemaps:**
- **Sitemap cache invalidation on settings save** — `AdminController::ajaxSaveSettings()` now calls `SitemapRouter::invalidateAll()` after saving settings. Previously, changing sitemap post types, taxonomies, or other settings had no effect until the cache expired naturally (1 hour) or was manually flushed via the "Flush Sitemap Cache" button (`AdminController.php`)
- **autoload flag preservation** — All `update_option()` calls in `ajaxSaveSettings()` and `Options::set()` no longer pass `false` as the third argument. Previously, every settings save silently downgraded `autoload` from `true` (set by `Activator::setDefaultOptions()`) to `false` for all options, increasing frontend DB queries on subsequent requests (`AdminController.php`, `Options.php`)

**Files Modified:**
- `celestial-sitemap.php` — version bump to 3.5.1
- `includes/Admin/AdminController.php` — sitemap cache invalidation on save, `update_option()` autoload fix
- `includes/Core/Options.php` — `update_option()` autoload fix in `set()`

### 3.5.0

**New Features — Specialized Google Sitemaps:**
- **Google News sitemap** (`cel-sitemap-news.xml`) — Dedicated sitemap conforming to Google News sitemap spec. Only includes `post` type articles published within the last 48 hours (per spec requirement). Max 1,000 URLs. Includes `news:publication` (name + language), `news:publication_date`, `news:title`, and optional `image:image` extension for featured images (`SitemapRouter.php`)
- **Image sitemap** (`cel-sitemap-image.xml`) — Standalone image sitemap listing all published posts with featured images across all configured post types. Uses `image:image` extension with `image:loc` and `image:title`. Paginated at 2,500 URLs per file (`SitemapRouter.php`)
- **Video sitemap** (`cel-sitemap-video.xml`) — Dedicated video sitemap for posts containing YouTube embeds. Extracts YouTube video IDs from post content via regex. Includes `video:thumbnail_loc`, `video:title`, `video:description`, `video:player_loc`, and `video:publication_date`. Paginated at 2,500 URLs per file (`SitemapRouter.php`)

**Sitemap Index Integration:**
- `cel-sitemap.xml` index now conditionally includes entries for news, image, and video sitemaps when qualifying content exists
- News entry appears only when posts exist within the 48-hour window
- Image entry appears only when posts with featured images exist
- Video entry appears only when posts containing YouTube embeds exist
- All specialized sitemaps support pagination in the index (e.g., `cel-sitemap-image-1.xml`, `cel-sitemap-image-2.xml`)

**Background Pre-generation:**
- `pregenerateSitemaps()` extended to pre-generate news, image, and video sitemaps via cron, ensuring zero-latency frontend responses

**Runtime Safety Hardening:**
- **Fatal error fix** — `update_post_meta_cache()` (non-existent) replaced with correct `\update_postmeta_cache()` WordPress API
- **Global namespace resolution** — All WordPress core function calls in namespaced code prefixed with `\` to prevent fatal errors from incorrect namespace resolution
- **Shared thumbnail bug fix** — `batchLoadThumbnails()` rewritten to use post→thumbnail mapping instead of thumbnail→post, correctly handling multiple posts sharing the same featured image
- **`strtotime()` false guard** — `post_modified_gmt` value `'0000-00-00 00:00:00'` no longer causes fatal error; falls back to current time
- **`get_permalink()` false guard** — Posts returning `false` from `get_permalink()` are safely skipped
- **XML control character stripping** — `escXml()` helper strips illegal XML 1.0 characters (`\x00-\x08`, `\x0B`, `\x0C`, `\x0E-\x1F`) using `esc_xml()` (WP 5.5+) with `esc_html()` fallback
- **Defensive array checks** — `is_array()` guards on filter results and nested image/video arrays throughout sitemap builders

**XSL Stylesheet Update:**
- `sitemap-xsl.php` updated with `xmlns:news` and `xmlns:video` namespace declarations
- URL table column renamed from "Images" to "Info" to accommodate News and Video badges
- Badge rendering for News (`badge-news`) and Video (`badge-video`) entries alongside existing Image badges

**New Extensibility Filters:**
- `cel_sitemap_news_urls` — filter news sitemap URL array
- `cel_sitemap_video_urls` — filter video sitemap URL array
- `cel_sitemap_image_urls` — filter image sitemap URL array

**Files Modified:**
- `celestial-sitemap.php` — version bump to 3.5.0
- `includes/Sitemap/SitemapRouter.php` — news/image/video sitemap builders, data fetchers, count helpers, routing, index integration, pre-generation, `escXml()`, runtime safety fixes
- `templates/sitemap-xsl.php` — namespace declarations, badge rendering for news/video

### 3.4.0

**Plugin Identity Refactor — Full Rename:**
- **Plugin renamed** from "Ultimate SEO Pro" to "Celestial Sitemap" across the entire source tree. Plugin slug, text domain, namespace, constants, menu titles, and all user-facing strings updated
- **Namespace renamed** — `UltimateSEOPro\` → `CelestialSitemap\` (all PHP files under `includes/`)
- **Constant prefix renamed** — `USP_*` → `CEL_*` (`CEL_VERSION`, `CEL_FILE`, `CEL_DIR`, `CEL_URL`, `CEL_BASENAME`, `CEL_MIN_WP`, `CEL_MIN_PHP`)
- **Option key prefix renamed** — `usp_*` → `cel_*` (all 24 options in `Options::ALLOWED_KEYS`)
- **Post/term meta prefix renamed** — `_usp_*` → `_cel_*` (`_cel_title`, `_cel_description`, `_cel_canonical`, `_cel_noindex`, `_cel_og_image`)
- **DB table prefix renamed** — `usp_redirects` → `cel_redirects`, `usp_404_log` → `cel_404_log`
- **Transient prefix renamed** — `usp_*` → `cel_*` (`cel_sm_v{N}_*`, `cel_redirect_compiled`, `cel_404_rate_count`, `cel_404_row_count`)
- **Cache directory renamed** — `usp-sitemaps/` → `cel-sitemaps/`
- **Sitemap endpoint renamed** — `/sitemap.xml` → `/cel-sitemap.xml`, `/sitemap-{type}.xml` → `/cel-sitemap-{type}.xml`, `/sitemap.xsl` → `/cel-sitemap.xsl`
- **Query vars renamed** — `usp_sitemap` → `cel_sitemap`, `usp_sitemap_page` → `cel_sitemap_page`, `usp_xsl` → `cel_xsl`
- **Cron events renamed** — `usp_cleanup_404_log` → `cel_cleanup_404_log`, `usp_pregenerate_sitemaps` → `cel_pregenerate_sitemaps`
- **AJAX actions renamed** — `usp_save_settings` → `cel_save_settings`, `usp_flush_sitemap` → `cel_flush_sitemap`, `usp_add_redirect` → `cel_add_redirect`, `usp_delete_redirect` → `cel_delete_redirect`, `usp_delete_404` → `cel_delete_404`, `usp_clear_404` → `cel_clear_404`
- **Admin menu slugs renamed** — `usp-dashboard` → `cel-dashboard`, `usp-redirects` → `cel-redirects`, `usp-404-log` → `cel-404-log`
- **Asset handles renamed** — `usp-admin` → `cel-admin`
- **JS global renamed** — `uspAdmin` → `celAdmin`
- **Nonce action renamed** — `usp_admin_nonce` → `cel_admin_nonce`, `usp_save_meta` → `cel_save_meta`, `usp_save_term_meta` → `cel_save_term_meta`
- **All extensibility filters renamed** — `usp_*` → `cel_*` (13 filters: `cel_head_title`, `cel_head_description`, `cel_head_ogp`, `cel_canonical_url`, `cel_robots_directives`, `cel_schema_website`, `cel_schema_organization`, `cel_schema_article`, `cel_schema_webpage`, `cel_schema_breadcrumbs`, `cel_schema_type`, `cel_sitemap_post_urls`, `cel_sitemap_taxonomy_urls`)
- **HTML/CSS prefix renamed** — `usp-*` → `cel-*` in all templates, CSS classes, and element IDs
- **Text domain renamed** — `ultimate-seo-pro` → `celestial-sitemap`
- **Main entry file renamed** — `ultimate-seo-pro.php` → `celestial-sitemap.php`

**Integrity Verification:**
- Zero legacy identifiers (`usp_`, `USP_`, `UltimateSEOPro`, `uspAdmin`, `ultimate-seo-pro`) remain in any file
- Full cross-reference audit passed: all hooks, meta keys, option keys, AJAX actions, HTML IDs, CSS classes, nonces, DB tables, cache keys, cron events, rewrite rules, and constants are internally consistent

**Files Modified:** All 19 PHP files, 1 JS file, 1 CSS file, 3 template files, `README.md`

### 3.3.0

**Architecture — Unified Head Builder:**
- **Single-pass metadata generation** — `HeadManager::collect()` runs at `template_redirect` and resolves ALL head metadata (meta description, canonical, prev/next, OGP, Twitter Card, JSON-LD schema) in one pass. `outputHead()` at `wp_head` echoes the pre-built HTML fragment with zero DB queries or computation at render time (`HeadManager.php`, `CanonicalManager.php`, `SchemaManager.php`, `Plugin.php`)
- **CanonicalManager refactored** — no longer hooks `wp_head` independently. Provides `getCanonicalHtml()` returning canonical + prev/next as an HTML string, called by HeadManager during the collect phase (`CanonicalManager.php`)
- **SchemaManager refactored** — no longer hooks `wp_head` independently. Provides `getSchemaHtml()` returning all `<script type="application/ld+json">` blocks as an HTML string, called by HeadManager during the collect phase (`SchemaManager.php`)

**Architecture — Sitemap Pre-generation:**
- **Two-tier file cache** — sitemaps are first served from filesystem (`wp-content/cache/cel-sitemaps/{blog_id}/`), falling back to transient cache, then generation. File cache eliminates all DB queries for cached sitemaps (`SitemapRouter.php`)
- **Background pre-generation via cron** — `cel_pregenerate_sitemaps` single event fires 30 seconds after content changes (debounced), pre-generating all sitemap segments so frontend requests are instant (`SitemapRouter.php`)
- **Per-type cache files** — each sitemap segment has its own cache file (`v{N}-idx.xml`, `v{N}-post_p1.xml`, `v{N}-category_p1.xml`), avoiding full-cache rebuilds when only one segment changes (`SitemapRouter.php`)
- **Directory listing protection** — `index.php` auto-created in cache directory (`SitemapRouter.php`)

**Architecture — Compiled Redirect Map:**
- **Three match types** — redirect rules now support `exact` (O(1) hash lookup), `prefix` (longest-prefix-first matching), and `regex` (PCRE with backreference substitution in target URLs) (`RedirectManager.php`)
- **Compiled map cache** — all redirect rules compiled into a structured map (`['exact' => [...], 'prefix' => [...], 'regex' => [...]]`) and cached in a single transient. Prefix rules sorted by source length for correct matching (`RedirectManager.php`)
- **Large table fallback** — tables exceeding 10,000 entries skip compiled map and use direct indexed DB queries for exact matches (`RedirectManager.php`)
- **match_type column** — `VARCHAR(10) NOT NULL DEFAULT 'exact'` added to `{prefix}cel_redirects` table with index. Backward-compatible: missing column detected via `SHOW COLUMNS` and cached statically (`Activator.php`, `RedirectManager.php`)
- **Loop detection** — exact-match redirects check for simple redirect loops (A→B→A) before inserting (`RedirectManager.php`)
- **Regex validation** — regex patterns validated via `preg_match()` before insert (`RedirectManager.php`)

**Security — Encryption Upgrade:**
- **Random IV per encryption** — AES-256-CBC now uses `openssl_random_pseudo_bytes()` for each encryption instead of a deterministic IV derived from the key. Format: `enc:{base64_iv}:{base64_cipher}`. Backward-compatible: legacy format (`enc:{base64_cipher}`) with deterministic IV still decrypted correctly (`Options.php`)

**Robustness — Glitch Audit:**
- **Safe filesystem cleanup** — `glob()` + `array_map('unlink')` pattern replaced with `scandir()` + `is_file()` guard. Prevents PHP warnings in restricted environments (`open_basedir`) and correctly handles non-XML files (`index.php`, `.htaccess`) left in cache directories (`Deactivator.php`, `uninstall.php`, `SitemapRouter.php`)
- **Multisite-safe cache paths** — sitemap file cache now uses per-site subdirectories (`cel-sitemaps/{blog_id}/`) to prevent cross-site cache collisions on multisite installations (`SitemapRouter.php`, `Deactivator.php`, `uninstall.php`)
- **Robust cron unscheduling** — `wp_clear_scheduled_hook()` replaced with explicit `while (wp_next_scheduled()) { wp_unschedule_event() }` loop to ensure all scheduled instances are removed (`Deactivator.php`, `uninstall.php`)
- **Complete deactivation cleanup** — cache directory removal now deletes all files (not just `*.xml`), so `rmdir()` actually succeeds. Parent `cel-sitemaps/` directory removed on uninstall if empty (`Deactivator.php`, `uninstall.php`)
- **Cleanup symmetry** — `Deactivator.php` and `uninstall.php` now perform identical cleanup: cron unscheduling, file cache removal, transient deletion (`Deactivator.php`, `uninstall.php`)

**Files Modified:**
- `celestial-sitemap.php` — version bump to 3.3.0
- `includes/Plugin.php` — SchemaManager injected into HeadManager constructor; SchemaManager no longer calls `register()`
- `includes/Core/Options.php` — random IV encryption, backward-compatible decryption
- `includes/Core/Activator.php` — `match_type` column and index added to redirects table
- `includes/Core/Deactivator.php` — safe cleanup with `scandir()`, multisite-safe cache path, explicit cron unscheduling
- `includes/SEO/HeadManager.php` — unified head builder: `collect()` at `template_redirect`, `outputHead()` at `wp_head`
- `includes/SEO/CanonicalManager.php` — `getCanonicalHtml()` method, no direct `wp_head` hook
- `includes/Schema/SchemaManager.php` — `getSchemaHtml()` method, no direct `wp_head` hook
- `includes/Sitemap/SitemapRouter.php` — two-tier file cache, cron pre-generation, per-type cache, multisite-safe paths, safe `flushFileCache()`
- `includes/Redirect/RedirectManager.php` — compiled map with exact/prefix/regex, loop detection, regex validation
- `uninstall.php` — safe cleanup, multisite-safe cache path, parent directory cleanup

### 3.2.0

**Performance — Critical (production-grade for 100k+ DAU):**
- **Sitemap N+1 query fix** — `getPostTypeUrls()` now batch-primes post caches via `_prime_post_caches()` and `update_post_meta_cache()`, and batch-loads thumbnail URLs. Eliminates 12,500+ individual DB queries per 2,500-post sitemap chunk (`SitemapRouter.php`)
- **Options bulk loading** — All `cel_*` options loaded in a single DB query on first access and cached in memory. Frequently-used options (title, noindex flags, schema, OGP) set to `autoload=true` (`Options.php`, `Activator.php`)
- **Versioned sitemap cache invalidation** — Replaced `DELETE FROM wp_options WHERE option_name LIKE ...` with a version counter (`cel_sitemap_cache_version`). Incrementing the counter invalidates all sitemap caches; old entries expire naturally via TTL. Eliminates expensive LIKE scans on every `save_post` (`SitemapRouter.php`)
- **Redirect tiered caching** — 3-tier strategy: full map transient (≤5,000), partitioned bucket cache by first path segment (5,001–20,000), direct indexed DB query (>20,000). Eliminates the performance cliff at 5,001 entries (`RedirectManager.php`)

**Security:**
- **404 log rate limiting** — Transient-based per-minute rate limiter prevents attackers from flooding the database with unique 404 URLs. Configurable via `cel_404_rate_limit` option (default: 100 new URLs/min). Existing URL upserts (hit count increments) are always allowed (`NotFoundLogger.php`)
- **404 table size cap** — Configurable maximum row count (`cel_404_max_rows`, default: 50,000). Inserts are skipped when the cap is exceeded. Row count cached in a 5-minute transient to avoid COUNT(*) on every 404 (`NotFoundLogger.php`)
- **GSC credential encryption** — `cel_gsc_client_secret` and `cel_gsc_access_token` are now encrypted at rest using AES-256-CBC with keys derived from `wp_salt()`. Backward-compatible: existing plaintext values are read as-is and encrypted on next save (`Options.php`, `AdminController.php`)
- **Options key whitelist** — `Options::set()` now validates the key against an allowed list and throws `\InvalidArgumentException` for unknown keys (`Options.php`)
- **URL field validation hardened** — Canonical URL and OG image meta fields now use `esc_url_raw()` instead of `sanitize_text_field()`, rejecting `javascript:` and other non-HTTP URIs (`AdminController.php`)
- **Password-protected content protection** — Auto-generated meta descriptions are suppressed for password-protected posts to prevent content leakage via cached pages (`HeadManager.php`)

**SEO Correctness:**
- **WP core sitemaps disabled** — `wp_sitemaps_enabled` filter set to `false` during plugin boot to prevent two competing sitemap systems (`Plugin.php`)
- **Paginated prev/next fix** — `CanonicalManager::outputPrevNext()` now uses `_wp_link_page()` for correct URL construction under both pretty and default (`?p=123`) permalink structures (`CanonicalManager.php`)
- **Empty og:url prevention** — OGP tags are now entirely suppressed when no valid `og:url` can be determined, avoiding invalid Open Graph markup (`HeadManager.php`)
- **URL-encoded redirect paths** — `RedirectManager::normalise()` now applies `rawurldecode()` to match requests for encoded URLs (e.g., `/café/` vs `/caf%C3%A9/`) (`RedirectManager.php`)

**New Features:**
- **Facebook App ID** — `fb:app_id` meta tag, configurable via Settings → Open Graph / Social (`cel_fb_app_id` option)
- **Twitter/X site handle** — `twitter:site` meta tag, configurable via Settings → Open Graph / Social (`cel_twitter_site` option)

**Robustness:**
- **Migration error handling** — `Migrator::maybeRun()` wrapped in try/catch. On failure, the version is NOT updated (migration retries on next request) and the error is logged when `WP_DEBUG` is enabled (`Migrator.php`)
- **TRUNCATE replaced with DELETE** — `NotFoundLogger::clearAll()` now uses `DELETE FROM` instead of `TRUNCATE TABLE` to avoid requiring `DROP` privilege on shared hosting (`NotFoundLogger.php`)
- **Multisite uninstall** — `uninstall.php` now iterates all sites via `get_sites()` on multisite installations (`uninstall.php`)

**Files Modified:**
- `celestial-sitemap.php` — version bump to 3.2.0
- `includes/Plugin.php` — `wp_sitemaps_enabled` filter
- `includes/Core/Options.php` — bulk loader, encryption, key whitelist, `invalidateCache()`, `fbAppId()`, `twitterSite()`
- `includes/Core/Activator.php` — autoload flags, new default options (`cel_fb_app_id`, `cel_twitter_site`, `cel_404_rate_limit`, `cel_404_max_rows`)
- `includes/Core/Deactivator.php` — cron hook name fix
- `includes/Core/NotFoundLogger.php` — rate limiter, table cap, DELETE instead of TRUNCATE
- `includes/Core/Migrator.php` — try/catch, conditional version update
- `includes/Sitemap/SitemapRouter.php` — batch cache priming, versioned cache keys, `batchLoadThumbnails()`
- `includes/Redirect/RedirectManager.php` — `rawurldecode()`, 3-tier cache strategy
- `includes/SEO/HeadManager.php` — password protection check, empty og:url guard, `fb:app_id`, `twitter:site`
- `includes/SEO/CanonicalManager.php` — `singularPageUrl()` method, `get_the_ID()` validation
- `includes/Admin/AdminController.php` — `esc_url_raw()` for URL fields, GSC encryption on save, new social settings
- `templates/admin/dashboard.php` — Facebook App ID and Twitter/X handle fields
- `uninstall.php` — multisite cleanup via `get_sites()`

### 3.1.0

**New Features:**
- **Term Meta Admin UI** — SEO fields (title, description, canonical, noindex) on all public taxonomy add/edit screens via `TaxonomyFields` class
- **Competing SEO Plugin Detection** — Admin notice when Yoast SEO, Rank Math, All in One SEO, or SEOPress is active simultaneously via `ConflictDetector` class
- **Custom Post Type Schema** — All public singular CPTs now receive WebPage structured data by default; override via `cel_schema_type` filter
- **Archive Pagination Canonical** — Paginated archives self-reference their own URL instead of incorrectly canonicalizing to page 1; `rel="prev"` / `rel="next"` output for archives
- **Extensibility Filters** — 13 `apply_filters()` hooks added across all output paths (title, description, OGP, canonical, robots, schema, sitemap)

**Improvements:**
- `cel_head_title` filter applied in `filterTitleParts()` for full context coverage (singular, front page, taxonomy)
- `resolveDescription()` result cached per request to prevent double filter execution
- `cel_canonical` term field uses `esc_url_raw()` for proper URL sanitization
- Admin CSS enqueued on taxonomy screens (`edit-tags.php`, `term.php`)

**Files Added:**
- `includes/Admin/TaxonomyFields.php`
- `includes/Admin/ConflictDetector.php`

**Files Modified:**
- `celestial-sitemap.php` — version bump to 3.1.0
- `includes/Plugin.php` — wires TaxonomyFields and ConflictDetector
- `includes/SEO/HeadManager.php` — filters + description caching
- `includes/SEO/CanonicalManager.php` — pagination strategy + filter
- `includes/SEO/RobotsManager.php` — filter
- `includes/Schema/SchemaManager.php` — CPT support + filters
- `includes/Sitemap/SitemapRouter.php` — filters
- `includes/Admin/AdminController.php` — taxonomy screen CSS enqueue

### 3.0.0

- Initial release with title/meta/canonical/OGP/Schema/Sitemap/Redirect/404 log

## Known Limitations / Planned Improvements

1. **GSC API integration** — designed (Options ready) but not connected; requires OAuth2 flow
2. **Internal link analysis** — requires content parsing; planned as background cron job
3. **IndexNow** — key generation designed but ping-on-publish not yet wired
4. **WooCommerce** — product schema not implemented (use `cel_schema_type` filter with `'Product'`)
5. **Multilingual (WPML/Polylang)** — hreflang sitemap extension not yet active
6. **Bulk editor** — per-post SEO fields editable only in post editor, not quick-edit
