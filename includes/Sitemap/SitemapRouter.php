<?php

declare(strict_types=1);

namespace CelestialSitemap\Sitemap;

use CelestialSitemap\Core\Options;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * XML Sitemap system with file-based pre-generation.
 *
 * Architecture:
 *  - Registers rewrite rules at `init:1`.
 *  - Serves sitemaps early at `init:99` via URI detection (pre-empts competing plugins).
 *  - Flushes rewrite rules at `wp_loaded` (after all plugins register their rules).
 *  - Two-tier caching: file cache (primary) + transient fallback.
 *  - Background pre-generation via cron (debounced 30s after content changes).
 *  - Auto-invalidates on post save/delete/term changes via version counter.
 *  - 2,500 URLs per sitemap file (conservative; spec allows 50,000).
 *  - ETag + 304 Not Modified.
 *  - Image sitemap extension.
 *  - Separate cache files per type: index, post-1, page-1, category, etc.
 *
 * Cache layout:
 *   wp-content/cache/cel-sitemaps/{blog_id}/v{N}-index.xml
 *   wp-content/cache/cel-sitemaps/{blog_id}/v{N}-post-p1.xml
 *   wp-content/cache/cel-sitemaps/{blog_id}/v{N}-category-p1.xml
 *
 * On single-site, {blog_id} is always 1.
 *
 * Filters:
 *  - `cel_sitemap_post_urls`     — post type URL array
 *  - `cel_sitemap_taxonomy_urls` — taxonomy URL array
 */
final class SitemapRouter
{
    private const MAX_URLS = 2500;

    private const CACHE_VERSION_KEY = 'cel_sitemap_cache_version';

    /**
     * Debounce delay for background pre-generation (seconds).
     */
    private const PREGENERATE_DELAY = 30;

    /**
     * Google News sitemap: max 1,000 URLs, articles within the last 48 hours.
     */
    private const NEWS_MAX_URLS      = 1000;
    private const NEWS_MAX_AGE_HOURS = 48;

    private int $cacheTtl;

    private Options $opts;

    public function __construct(Options $opts)
    {
        $this->opts     = $opts;
        $this->cacheTtl = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
    }

    public function register(): void
    {
        \add_action('init', [$this, 'addRewriteRules'], 1);
        \add_filter('query_vars', [$this, 'addQueryVars']);

        // Serve cel-sitemap URLs at init:99 — after post types are registered
        // (init:10) but before other sitemap plugins can intercept the request
        // at wp_loaded / parse_request / template_redirect. This allows
        // coexistence with plugins like Google XML Sitemaps.
        \add_action('init', [$this, 'maybeServeEarly'], 99);

        // Flush rewrite rules at wp_loaded — AFTER all plugins have registered
        // their rewrite rules during init. Flushing earlier (e.g., at init:1)
        // would persist an incomplete rule set, breaking other plugins' URLs.
        \add_action('wp_loaded', [$this, 'maybeFlushRewriteRules']);

        // Cache invalidation + background pre-generation
        \add_action('save_post', [$this, 'invalidateOnSave'], 10, 2);
        \add_action('delete_post', [$this, 'invalidateAll']);
        \add_action('created_term', [$this, 'invalidateAll']);
        \add_action('edited_term', [$this, 'invalidateAll']);
        \add_action('delete_term', [$this, 'invalidateAll']);

        // Cron callback for background pre-generation
        \add_action('cel_pregenerate_sitemaps', [$this, 'pregenerateSitemaps']);
    }

    // ── Rewrite rules ────────────────────────────────────────────────

    public function addRewriteRules(): void
    {
        \add_rewrite_rule('^cel-sitemap\.xml/?$', 'index.php?cel_sitemap=index', 'top');
        \add_rewrite_rule(
            '^cel-sitemap-([a-z0-9_-]+?)-(\d+)\.xml/?$',
            'index.php?cel_sitemap=$matches[1]&cel_sitemap_page=$matches[2]',
            'top'
        );
        \add_rewrite_rule(
            '^cel-sitemap-([a-z0-9_-]+?)\.xml/?$',
            'index.php?cel_sitemap=$matches[1]',
            'top'
        );
        \add_rewrite_rule('^cel-sitemap\.xsl/?$', 'index.php?cel_xsl=1', 'top');
    }

    /**
     * Flush rewrite rules when needed — runs at wp_loaded so ALL plugins
     * have registered their rewrite rules during init first.
     *
     * Two triggers:
     *  1. Flag-based: cel_flush_rewrite_rules option set by Migrator after
     *     a plugin version update. Fires once, then clears the flag.
     *  2. Self-healing: sitemap rules missing from the persisted rewrite_rules
     *     option (manual deploy, failed activation, corrupted option).
     */
    public function maybeFlushRewriteRules(): void
    {
        // 1. Flag-based flush (one-shot after plugin update)
        if (\get_option('cel_flush_rewrite_rules')) {
            \delete_option('cel_flush_rewrite_rules');
            \flush_rewrite_rules(false);
            return;
        }

        // 2. Self-healing: check if our rules are persisted.
        //    Guarded by a transient to prevent flush loops when object cache
        //    returns stale rewrite_rules data (flush would fire every request).
        if (\get_transient('cel_self_heal_done')) {
            return;
        }

        $rules = \get_option('rewrite_rules');
        if (\is_array($rules) && ! \in_array('index.php?cel_sitemap=index', $rules, true)) {
            \flush_rewrite_rules(false);
            \set_transient('cel_self_heal_done', 1, HOUR_IN_SECONDS);
        }
    }

    public function addQueryVars(array $vars): array
    {
        $vars[] = 'cel_sitemap';
        $vars[] = 'cel_sitemap_page';
        $vars[] = 'cel_xsl';
        return $vars;
    }

    // ── Request handler ──────────────────────────────────────────────

    /**
     * Early intercept at init:99 — serves cel-sitemap URLs before other
     * sitemap plugins (Google XML Sitemaps, etc.) can intercept the request.
     *
     * At init:99 all standard post types and custom post types (registered
     * at init:10) are available, so buildIndex/buildSitemap work correctly.
     */
    public function maybeServeEarly(): void
    {
        [$type, $page, $isXsl] = $this->detectFromUri();

        if ($type === '' && ! $isXsl) {
            return;
        }

        if ($isXsl) {
            $this->serveXsl();
            exit;
        }

        $this->serveSitemap($type, $page);
    }

    /**
     * Generate and send the sitemap XML for the given type and page.
     * Sends appropriate headers and exits. Does not return.
     */
    private function serveSitemap(string $type, int $page): void
    {
        $response = $this->resolveSitemapResponse($type, $page);
        $this->sendXml($response['xml'], $response['status']);
        exit;
    }

    /**
     * @return array{status: int, xml: string}
     */
    private function resolveSitemapResponse(string $type, int $page): array
    {
        $xml = $this->resolveSitemapXml($type, $page);
        if ($xml === '') {
            return [
                'status' => 404,
                'xml'    => $this->buildEmptySitemapXml(),
            ];
        }

        return [
            'status' => 200,
            'xml'    => $xml,
        ];
    }

    private function resolveSitemapXml(string $type, int $page): string
    {
        if (! $this->isValidSitemapRequest($type, $page)) {
            return '';
        }

        if ($type === 'index') {
            return $this->getCachedOrGenerate('idx', fn() => $this->buildIndex());
        }

        if ($type === 'news') {
            return $this->getCachedOrGenerate('news', fn() => $this->buildNewsSitemap());
        }

        if ($type === 'video') {
            return $this->getCachedOrGenerate("video_p{$page}", fn() => $this->buildVideoSitemap($page));
        }

        if ($type === 'image') {
            return $this->getCachedOrGenerate("image_p{$page}", fn() => $this->buildImageSitemap($page));
        }

        return $this->getCachedOrGenerate("{$type}_p{$page}", fn() => $this->buildSitemap($type, $page));
    }

    private function isValidSitemapRequest(string $type, int $page): bool
    {
        if ($page < 1) {
            return false;
        }

        if ($type === 'index' || $type === 'news') {
            return $page === 1;
        }

        if ($type === 'video') {
            return $this->hasSitemapPage($this->countVideoPosts(), $page);
        }

        if ($type === 'image') {
            return $this->hasSitemapPage($this->countImagePosts(), $page);
        }

        if (\post_type_exists($type)) {
            return $this->hasSitemapPage($this->countPostType($type), $page);
        }

        if (\taxonomy_exists($type)) {
            return $this->hasSitemapPage($this->countTaxonomy($type), $page);
        }

        return false;
    }

    private function hasSitemapPage(int $count, int $page): bool
    {
        if ($count <= 0) {
            return false;
        }

        return $page <= (int) \ceil($count / self::MAX_URLS);
    }

    private function buildEmptySitemapXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
    }

    // ── URI-based fallback detection ───────────────────────────────

    /**
     * Parse REQUEST_URI to detect sitemap requests when rewrite rules fail.
     *
     * @return array{0: string, 1: int, 2: bool} [type, page, isXsl]
     */
    private function detectFromUri(): array
    {
        $uri  = \wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $path = $uri !== null && $uri !== false ? \rawurldecode(\basename($uri)) : '';

        if ($path === 'cel-sitemap.xml') {
            return ['index', 1, false];
        }

        if ($path === 'cel-sitemap.xsl') {
            return ['', 1, true];
        }

        if (\preg_match('/^cel-sitemap-([a-z0-9_-]+?)-(\d+)\.xml$/', $path, $m)) {
            return [$m[1], \max(1, (int) $m[2]), false];
        }

        if (\preg_match('/^cel-sitemap-([a-z0-9_-]+?)\.xml$/', $path, $m)) {
            return [$m[1], 1, false];
        }

        return ['', 1, false];
    }

    // ── Two-tier cache: file → transient → generate ─────────────────

    /**
     * Try file cache, then transient, then generate and cache both.
     *
     * @param string   $suffix  Cache key suffix (e.g., "idx", "post_p1")
     * @param callable $builder Returns XML string
     */
    private function getCachedOrGenerate(string $suffix, callable $builder): string
    {
        $cacheKey = $this->cacheKey($suffix);

        // Tier 1: File cache (zero DB queries)
        $xml = $this->readFileCache($cacheKey);
        if ($xml !== null) {
            return $xml;
        }

        // Tier 2: Transient (1 DB query, useful when file system is read-only)
        $cached = \get_transient($cacheKey);
        if (\is_string($cached) && $cached !== '') {
            // Backfill file cache
            $this->writeFileCache($cacheKey, $cached);
            return $cached;
        }

        // Tier 3: Generate
        $xml = $builder();
        if ($xml === '') {
            return '';
        }

        // Store in both tiers
        \set_transient($cacheKey, $xml, $this->cacheTtl);
        $this->writeFileCache($cacheKey, $xml);

        return $xml;
    }

    // ── Cache version ───────────────────────────────────────────────

    private function getCacheVersion(): int
    {
        return (int) \get_option(self::CACHE_VERSION_KEY, 1);
    }

    private function cacheKey(string $suffix): string
    {
        $ver = $this->getCacheVersion();
        return "cel_sm_v{$ver}_{$suffix}";
    }

    // ── File cache ──────────────────────────────────────────────────

    /**
     * Return the per-site cache directory path.
     *
     * On multisite, each site gets its own subdirectory to prevent
     * cross-site cache collisions.
     */
    private function getCacheDir(): string
    {
        $blogId = \get_current_blog_id();
        return WP_CONTENT_DIR . '/cache/cel-sitemaps/' . $blogId . '/';
    }

    private function readFileCache(string $key): ?string
    {
        $file = $this->getCacheDir() . $key . '.xml';
        if (\is_readable($file) && \filemtime($file) > \time() - $this->cacheTtl) {
            $content = \file_get_contents($file);
            return $content !== false ? $content : null;
        }
        return null;
    }

    private function writeFileCache(string $key, string $xml): void
    {
        $dir = $this->getCacheDir();
        if (! \wp_mkdir_p($dir)) {
            return; // Read-only filesystem; transient cache is the fallback
        }

        // Add index.php to prevent directory listing
        $indexFile = $dir . 'index.php';
        if (! \file_exists($indexFile)) {
            \file_put_contents($indexFile, "<?php\n// Silence is golden.\n");
        }

        \file_put_contents($dir . $key . '.xml', $xml, LOCK_EX);
    }

    private function flushFileCache(): void
    {
        $dir = $this->getCacheDir();
        if (! \is_dir($dir)) {
            return;
        }

        $files = \glob($dir . '*.xml');
        if (! \is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (\is_file($file)) {
                \unlink($file);
            }
        }
    }

    // ── Index builder ───────────────────────────────────────────────

    private function buildIndex(): string
    {
        $entries = [];

        foreach ($this->opts->sitemapPostTypes() as $pt) {
            $count = $this->countPostType($pt);
            if ($count === 0) {
                continue;
            }
            $chunks = (int) \ceil($count / self::MAX_URLS);
            for ($i = 1; $i <= $chunks; $i++) {
                $slug    = $chunks > 1 ? "{$pt}-{$i}" : $pt;
                $lastmod = $this->lastModifiedPostType($pt);
                $this->maybeAppendIndexEntry(
                    $entries,
                    "{$pt}_p{$i}",
                    fn() => $this->buildSitemap($pt, $i),
                    \home_url("/cel-sitemap-{$slug}.xml"),
                    $lastmod
                );
            }
        }

        foreach ($this->opts->sitemapTaxonomies() as $tax) {
            $count = $this->countTaxonomy($tax);
            if ($count === 0) {
                continue;
            }
            $chunks = (int) \ceil($count / self::MAX_URLS);
            for ($i = 1; $i <= $chunks; $i++) {
                $slug = $chunks > 1 ? "{$tax}-{$i}" : $tax;
                $this->maybeAppendIndexEntry(
                    $entries,
                    "{$tax}_p{$i}",
                    fn() => $this->buildSitemap($tax, $i),
                    \home_url("/cel-sitemap-{$slug}.xml"),
                    \gmdate('c')
                );
            }
        }

        // News sitemap — always listed in the index so crawlers can
        // discover it even when no articles were published in the last 48 h.
        $entries[] = [
            'loc'     => \home_url('/cel-sitemap-news.xml'),
            'lastmod' => \gmdate('c'),
        ];

        // Image sitemap (posts with featured images)
        $imageCount = $this->countImagePosts();
        if ($imageCount > 0) {
            $chunks = (int) \ceil($imageCount / self::MAX_URLS);
            for ($i = 1; $i <= $chunks; $i++) {
                $slug = $chunks > 1 ? "image-{$i}" : 'image';
                $this->maybeAppendIndexEntry(
                    $entries,
                    "image_p{$i}",
                    fn() => $this->buildImageSitemap($i),
                    \home_url("/cel-sitemap-{$slug}.xml"),
                    \gmdate('c')
                );
            }
        }

        // Video sitemap (posts containing YouTube embeds)
        $videoCount = $this->countVideoPosts();
        if ($videoCount > 0) {
            $chunks = (int) \ceil($videoCount / self::MAX_URLS);
            for ($i = 1; $i <= $chunks; $i++) {
                $slug = $chunks > 1 ? "video-{$i}" : 'video';
                $this->maybeAppendIndexEntry(
                    $entries,
                    "video_p{$i}",
                    fn() => $this->buildVideoSitemap($i),
                    \home_url("/cel-sitemap-{$slug}.xml"),
                    \gmdate('c')
                );
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?xml-stylesheet type="text/xsl" href="' . \esc_url(\home_url('/cel-sitemap.xsl')) . '"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($entries as $e) {
            $loc = \esc_url($e['loc'] ?? '');
            if ($loc === '') {
                continue;
            }
            $xml .= "  <sitemap>\n";
            $xml .= '    <loc>' . $loc . "</loc>\n";
            $xml .= '    <lastmod>' . $this->escXml($e['lastmod'] ?? '') . "</lastmod>\n";
            $xml .= "  </sitemap>\n";
        }

        $xml .= '</sitemapindex>';
        return $xml;
    }

    /**
     * @param array<int, array{loc: string, lastmod: string}> $entries
     */
    private function maybeAppendIndexEntry(array &$entries, string $cacheSuffix, callable $builder, string $loc, string $lastmod): void
    {
        $xml = $this->getCachedOrGenerate($cacheSuffix, $builder);
        if ($xml === '') {
            return;
        }

        $entries[] = [
            'loc'     => $loc,
            'lastmod' => $lastmod,
        ];
    }

    // ── Per-type sitemap builder ────────────────────────────────────

    private function buildSitemap(string $type, int $page): string
    {
        $offset = ($page - 1) * self::MAX_URLS;

        if (\post_type_exists($type)) {
            $urls = $this->getPostTypeUrls($type, self::MAX_URLS, $offset);
        } elseif (\taxonomy_exists($type)) {
            $urls = $this->getTaxonomyUrls($type, self::MAX_URLS, $offset);
        } else {
            return '';
        }

        if (empty($urls)) {
            return '';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?xml-stylesheet type="text/xsl" href="' . \esc_url(\home_url('/cel-sitemap.xsl')) . '"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        $xml .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        foreach ($urls as $u) {
            if (! \is_array($u) || empty($u['loc'])) {
                continue;
            }

            $loc = \esc_url($u['loc']);
            if ($loc === '') {
                continue;
            }

            $xml .= "  <url>\n";
            $xml .= '    <loc>' . $loc . "</loc>\n";
            if (! empty($u['lastmod'])) {
                $xml .= '    <lastmod>' . $this->escXml($u['lastmod']) . "</lastmod>\n";
            }

            if (! empty($u['images']) && \is_array($u['images'])) {
                foreach (\array_slice($u['images'], 0, 5) as $img) {
                    if (! \is_array($img) || empty($img['url'])) {
                        continue;
                    }
                    $imgLoc = \esc_url($img['url']);
                    if ($imgLoc === '') {
                        continue;
                    }
                    $xml .= "    <image:image>\n";
                    $xml .= '      <image:loc>' . $imgLoc . "</image:loc>\n";
                    if (! empty($img['title'])) {
                        $xml .= '      <image:title>' . $this->escXml($img['title']) . "</image:title>\n";
                    }
                    $xml .= "    </image:image>\n";
                }
            }

            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';
        return $xml;
    }

    // ── Google News sitemap builder ─────────────────────────────────

    /**
     * Build a Google News sitemap.
     *
     * Spec: https://developers.google.com/search/docs/crawling-indexing/sitemaps/news-sitemap
     * - Only articles published within the last 48 hours.
     * - Max 1,000 URLs.
     * - Required: news:publication (name + language), news:publication_date, news:title.
     */
    private function buildNewsSitemap(): string
    {
        $urls = $this->getNewsUrls();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';

        // Only declare news/image namespaces when entries exist.
        // An empty <urlset> with xmlns:news declared but no <news:news> tags
        // causes Google Search Console to report "XML tag not specified".
        if ($urls) {
            $xml .= ' xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"';
            $xml .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
        }
        $xml .= '>' . "\n";

        $siteName = \get_bloginfo('name');
        $language = \substr(\get_locale(), 0, 2);

        foreach ($urls as $u) {
            $loc = \esc_url($u['loc'] ?? '');
            if ($loc === '') {
                continue;
            }

            $xml .= "  <url>\n";
            $xml .= '    <loc>' . $loc . "</loc>\n";
            $xml .= "    <news:news>\n";
            $xml .= "      <news:publication>\n";
            $xml .= '        <news:name>' . $this->escXml($siteName) . "</news:name>\n";
            $xml .= '        <news:language>' . $this->escXml($language) . "</news:language>\n";
            $xml .= "      </news:publication>\n";
            $xml .= '      <news:publication_date>' . $this->escXml($u['publication_date']) . "</news:publication_date>\n";
            $xml .= '      <news:title>' . $this->escXml($u['title']) . "</news:title>\n";
            $xml .= "    </news:news>\n";

            if (! empty($u['images']) && \is_array($u['images'])) {
                foreach (\array_slice($u['images'], 0, 1) as $img) {
                    if (! \is_array($img) || empty($img['url'])) {
                        continue;
                    }
                    $imgLoc = \esc_url($img['url']);
                    if ($imgLoc === '') {
                        continue;
                    }
                    $xml .= "    <image:image>\n";
                    $xml .= '      <image:loc>' . $imgLoc . "</image:loc>\n";
                    if (! empty($img['title'])) {
                        $xml .= '      <image:title>' . $this->escXml($img['title']) . "</image:title>\n";
                    }
                    $xml .= "    </image:image>\n";
                }
            }

            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';
        return $xml;
    }

    // ── Video sitemap builder ───────────────────────────────────────

    /**
     * Build a Video sitemap for posts containing YouTube embeds.
     *
     * Spec: https://developers.google.com/search/docs/appearance/video
     * Required per entry: video:thumbnail_loc, video:title, video:description,
     * and one of video:content_loc or video:player_loc.
     */
    private function buildVideoSitemap(int $page): string
    {
        $urls = $this->getVideoUrls(self::MAX_URLS, ($page - 1) * self::MAX_URLS);
        if (empty($urls)) {
            return '';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        $xml .= ' xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

        foreach ($urls as $u) {
            $loc = \esc_url($u['loc'] ?? '');
            if ($loc === '' || empty($u['videos'])) {
                continue;
            }

            $xml .= "  <url>\n";
            $xml .= '    <loc>' . $loc . "</loc>\n";

            foreach ($u['videos'] as $video) {
                $playerLoc    = \esc_url($video['player_loc'] ?? '');
                $thumbnailLoc = \esc_url($video['thumbnail_loc'] ?? '');
                if ($playerLoc === '' || $thumbnailLoc === '') {
                    continue;
                }
                $xml .= "    <video:video>\n";
                $xml .= '      <video:thumbnail_loc>' . $thumbnailLoc . "</video:thumbnail_loc>\n";
                $xml .= '      <video:title>' . $this->escXml($video['title']) . "</video:title>\n";
                $xml .= '      <video:description>' . $this->escXml($video['description']) . "</video:description>\n";
                $xml .= '      <video:player_loc>' . $playerLoc . "</video:player_loc>\n";
                if (! empty($video['publication_date'])) {
                    $xml .= '      <video:publication_date>' . $this->escXml($video['publication_date']) . "</video:publication_date>\n";
                }
                $xml .= "    </video:video>\n";
            }

            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';
        return $xml;
    }

    // ── Image sitemap builder ──────────────────────────────────────

    /**
     * Build a dedicated Image sitemap.
     *
     * Lists all posts with featured images across configured post types.
     * Each <url> contains the page URL and its <image:image> entries.
     */
    private function buildImageSitemap(int $page): string
    {
        $urls = $this->getImageUrls(self::MAX_URLS, ($page - 1) * self::MAX_URLS);
        if (empty($urls)) {
            return '';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?xml-stylesheet type="text/xsl" href="' . \esc_url(\home_url('/cel-sitemap.xsl')) . '"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        $xml .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        foreach ($urls as $u) {
            $loc = \esc_url($u['loc'] ?? '');
            if ($loc === '' || empty($u['images'])) {
                continue;
            }

            $xml .= "  <url>\n";
            $xml .= '    <loc>' . $loc . "</loc>\n";

            foreach ($u['images'] as $img) {
                if (! \is_array($img) || empty($img['url'])) {
                    continue;
                }
                $imgLoc = \esc_url($img['url']);
                if ($imgLoc === '') {
                    continue;
                }
                $xml .= "    <image:image>\n";
                $xml .= '      <image:loc>' . $imgLoc . "</image:loc>\n";
                if (! empty($img['title'])) {
                    $xml .= '      <image:title>' . $this->escXml($img['title']) . "</image:title>\n";
                }
                $xml .= "    </image:image>\n";
            }

            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';
        return $xml;
    }

    // ── Data fetchers ────────────────────────────────────────────────

    /**
     * @return array<int, array{loc: string, lastmod: string, images: array}>
     */
    private function getPostTypeUrls(string $postType, int $limit, int $offset): array
    {
        global $wpdb;

        $ids = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} noidx
               ON p.ID = noidx.post_id
              AND noidx.meta_key = '_cel_noindex'
              AND noidx.meta_value = '1'
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND noidx.post_id IS NULL
             ORDER BY p.post_modified_gmt DESC
             LIMIT %d OFFSET %d",
            $postType,
            $limit,
            $offset
        ));

        if (empty($ids)) {
            $fallbackIds = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'post' AND post_status = 'publish'
                 ORDER BY post_date_gmt DESC
                 LIMIT %d",
                1
            ));

            if (empty($fallbackIds)) {
                return [];
            }

            $ids = $fallbackIds;
        }

        $intIds = \array_map('intval', $ids);

        if (! empty($intIds)) {
            if (\function_exists('_prime_post_caches')) {
                \_prime_post_caches($intIds, false, false);
            }
            \update_postmeta_cache($intIds);
        }

        $thumbnailMap = $this->batchLoadThumbnails($intIds);

        $urls = [];
        foreach ($intIds as $id) {
            if (\get_post_meta($id, '_cel_noindex', true) === '1') {
                continue;
            }

            $post = \get_post($id);
            if (! $post) {
                continue;
            }

            $permalink = \get_permalink($post);
            if (! $permalink) {
                continue;
            }

            $timestamp = \strtotime($post->post_modified_gmt);
            $lastmod   = $timestamp ? \gmdate('c', $timestamp) : \gmdate('c');

            $images = [];
            if (isset($thumbnailMap[$id])) {
                $images[] = $thumbnailMap[$id];
            }

            $urls[] = [
                'loc'     => $permalink,
                'lastmod' => $lastmod,
                'images'  => $images,
            ];
        }

        return (array) \apply_filters('cel_sitemap_post_urls', $urls, $postType);
    }

    /**
     * @param int[] $postIds
     * @return array<int, array{url: string, title: string}>
     */
    private function batchLoadThumbnails(array $postIds): array
    {
        if (empty($postIds)) {
            return [];
        }

        $thumbIds = [];
        $postToThumb = [];
        foreach ($postIds as $postId) {
            $thumbId = (int) \get_post_meta($postId, '_thumbnail_id', true);
            if ($thumbId > 0) {
                $thumbIds[]            = $thumbId;
                $postToThumb[$postId]  = $thumbId;
            }
        }

        if (empty($thumbIds)) {
            return [];
        }

        $thumbIds = \array_unique($thumbIds);

        if (\function_exists('_prime_post_caches')) {
            \_prime_post_caches($thumbIds, false, false);
        }
        \update_postmeta_cache($thumbIds);

        // Resolve each unique thumbnail URL once, then map back to posts.
        $resolvedUrls = [];
        foreach ($thumbIds as $thumbId) {
            $thumbUrl = \wp_get_attachment_url($thumbId);
            if ($thumbUrl && \filter_var($thumbUrl, FILTER_VALIDATE_URL)) {
                $resolvedUrls[$thumbId] = [
                    'url'   => $thumbUrl,
                    'title' => \get_the_title($thumbId),
                ];
            }
        }

        $map = [];
        foreach ($postToThumb as $postId => $thumbId) {
            if (! isset($resolvedUrls[$thumbId])) {
                continue;
            }
            $entry = $resolvedUrls[$thumbId];
            $map[$postId] = [
                'url'   => $entry['url'],
                'title' => $entry['title'] !== '' ? $entry['title'] : \get_the_title($postId),
            ];
        }

        return $map;
    }

    /**
     * @return array<int, array{loc: string, lastmod: string, images: array}>
     */
    private function getTaxonomyUrls(string $taxonomy, int $limit, int $offset): array
    {
        $terms = \get_terms($this->taxonomyQueryArgs($taxonomy, $limit, $offset));

        if (\is_wp_error($terms) || ! \is_array($terms)) {
            return [];
        }

        $urls = [];
        foreach ($terms as $term) {
            if (\get_term_meta($term->term_id, '_cel_noindex', true) === '1') {
                continue;
            }

            $link = \get_term_link($term);
            if (\is_wp_error($link)) {
                continue;
            }

            $urls[] = [
                'loc'     => $link,
                'lastmod' => '',
                'images'  => [],
            ];
        }

        return (array) \apply_filters('cel_sitemap_taxonomy_urls', $urls, $taxonomy);
    }

    // ── News data fetcher ──────────────────────────────────────────

    /**
     * @return array<int, array{loc: string, publication_date: string, title: string, images: array}>
     */
    private function getNewsUrls(): array
    {
        global $wpdb;

        $cutoff = \gmdate('Y-m-d H:i:s', \time() - self::NEWS_MAX_AGE_HOURS * 3600);

        $ids = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'post' AND post_status = 'publish'
             AND post_date_gmt >= %s
             ORDER BY post_date_gmt DESC
             LIMIT %d",
            $cutoff,
            self::NEWS_MAX_URLS
        ));

        if (empty($ids)) {
            $ids = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'post' AND post_status = 'publish'
                 ORDER BY post_date_gmt DESC
                 LIMIT %d",
                1
            ));

            if (empty($ids)) {
                return [];
            }
        }

        $intIds = \array_map('intval', $ids);

        if (\function_exists('_prime_post_caches')) {
            \_prime_post_caches($intIds, false, false);
        }
        \update_postmeta_cache($intIds);

        $thumbnailMap = $this->batchLoadThumbnails($intIds);

        $urls = [];
        foreach ($intIds as $id) {
            if (\get_post_meta($id, '_cel_noindex', true) === '1') {
                continue;
            }

            $post = \get_post($id);
            if (! $post) {
                continue;
            }

            $permalink = \get_permalink($post);
            if (! $permalink) {
                continue;
            }

            $pubTimestamp = \strtotime($post->post_date_gmt);
            $pubDate      = $pubTimestamp ? \gmdate('c', $pubTimestamp) : \gmdate('c');

            $images = [];
            if (isset($thumbnailMap[$id])) {
                $images[] = $thumbnailMap[$id];
            }

            $urls[] = [
                'loc'              => $permalink,
                'publication_date' => $pubDate,
                'title'            => \get_the_title($post),
                'images'           => $images,
            ];
        }

        return (array) \apply_filters('cel_sitemap_news_urls', $urls);
    }

    // ── Video data fetcher ─────────────────────────────────────────

    /**
     * @return array<int, array{loc: string, videos: array}>
     */
    private function getVideoUrls(int $limit, int $offset): array
    {
        global $wpdb;

        $postTypes = $this->opts->sitemapPostTypes();
        if (empty($postTypes)) {
            return [];
        }

        $placeholders = \implode(',', \array_fill(0, \count($postTypes), '%s'));
        $args         = $postTypes;
        $args[]       = $limit;
        $args[]       = $offset;

        // Find posts containing YouTube embeds via indexed post_type/post_status
        // then filter by content pattern. The LIKE is applied after index narrowing.
        $ids = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             LEFT JOIN {$wpdb->postmeta} noidx
               ON {$wpdb->posts}.ID = noidx.post_id
              AND noidx.meta_key = '_cel_noindex'
              AND noidx.meta_value = '1'
             WHERE post_type IN ({$placeholders}) AND post_status = 'publish'
             AND noidx.post_id IS NULL
             AND (post_content LIKE '%%youtube.com%%' OR post_content LIKE '%%youtu.be%%')
             ORDER BY post_modified_gmt DESC
             LIMIT %d OFFSET %d",
            ...$args
        ));

        if (empty($ids)) {
            return [];
        }

        $intIds = \array_map('intval', $ids);

        if (\function_exists('_prime_post_caches')) {
            \_prime_post_caches($intIds, false, false);
        }
        \update_postmeta_cache($intIds);

        $urls = [];
        foreach ($intIds as $id) {
            if (\get_post_meta($id, '_cel_noindex', true) === '1') {
                continue;
            }

            $post = \get_post($id);
            if (! $post) {
                continue;
            }

            $permalink = \get_permalink($post);
            if (! $permalink) {
                continue;
            }

            $videoIds = $this->extractYouTubeIds($post->post_content);
            if (empty($videoIds)) {
                continue;
            }

            $pubTimestamp = \strtotime($post->post_date_gmt);
            $pubDate      = $pubTimestamp ? \gmdate('c', $pubTimestamp) : \gmdate('c');

            $excerpt = \get_post_meta($id, '_cel_description', true);
            if (! $excerpt || $excerpt === '') {
                $excerpt = \has_excerpt($post)
                    ? \get_the_excerpt($post)
                    : \wp_trim_words(\wp_strip_all_tags($post->post_content), 30);
            }
            if ($excerpt === '') {
                $excerpt = \get_the_title($post);
            }

            $videos = [];
            foreach (\array_slice($videoIds, 0, 5) as $ytId) {
                $videos[] = [
                    'thumbnail_loc'    => "https://img.youtube.com/vi/{$ytId}/hqdefault.jpg",
                    'title'            => \get_the_title($post),
                    'description'      => $excerpt,
                    'player_loc'       => "https://www.youtube.com/embed/{$ytId}",
                    'publication_date' => $pubDate,
                ];
            }

            $urls[] = [
                'loc'    => $permalink,
                'videos' => $videos,
            ];
        }

        return (array) \apply_filters('cel_sitemap_video_urls', $urls);
    }

    /**
     * Extract YouTube video IDs from post content.
     *
     * Matches:
     *  - https://www.youtube.com/watch?v=VIDEO_ID
     *  - https://youtu.be/VIDEO_ID
     *  - https://www.youtube.com/embed/VIDEO_ID
     *
     * @return string[] Unique YouTube video IDs.
     */
    private function extractYouTubeIds(string $content): array
    {
        if (\preg_match_all(
            '/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i',
            $content,
            $matches
        )) {
            return \array_values(\array_unique($matches[1]));
        }
        return [];
    }

    // ── Image data fetcher ─────────────────────────────────────────

    /**
     * @return array<int, array{loc: string, images: array}>
     */
    private function getImageUrls(int $limit, int $offset): array
    {
        global $wpdb;

        $postTypes = $this->opts->sitemapPostTypes();
        if (empty($postTypes)) {
            return [];
        }

        $placeholders = \implode(',', \array_fill(0, \count($postTypes), '%s'));
        $args         = $postTypes;
        $args[]       = $limit;
        $args[]       = $offset;

        // Find published posts that have a _thumbnail_id meta (featured image)
        $ids = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
             LEFT JOIN {$wpdb->postmeta} noidx
               ON p.ID = noidx.post_id
              AND noidx.meta_key = '_cel_noindex'
              AND noidx.meta_value = '1'
             WHERE p.post_type IN ({$placeholders}) AND p.post_status = 'publish'
             AND pm.meta_value > 0
             AND noidx.post_id IS NULL
             ORDER BY p.post_modified_gmt DESC
             LIMIT %d OFFSET %d",
            ...$args
        ));

        if (empty($ids)) {
            return [];
        }

        $intIds = \array_map('intval', $ids);

        if (\function_exists('_prime_post_caches')) {
            \_prime_post_caches($intIds, false, false);
        }
        \update_postmeta_cache($intIds);

        $thumbnailMap = $this->batchLoadThumbnails($intIds);

        $urls = [];
        foreach ($intIds as $id) {
            if (\get_post_meta($id, '_cel_noindex', true) === '1') {
                continue;
            }

            if (! isset($thumbnailMap[$id])) {
                continue;
            }

            $post = \get_post($id);
            if (! $post) {
                continue;
            }

            $permalink = \get_permalink($post);
            if (! $permalink) {
                continue;
            }

            $urls[] = [
                'loc'    => $permalink,
                'images' => [$thumbnailMap[$id]],
            ];
        }

        return (array) \apply_filters('cel_sitemap_image_urls', $urls);
    }

    private function countImagePosts(): int
    {
        global $wpdb;

        $postTypes = $this->opts->sitemapPostTypes();
        if (empty($postTypes)) {
            return 0;
        }

        $placeholders = \implode(',', \array_fill(0, \count($postTypes), '%s'));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
             LEFT JOIN {$wpdb->postmeta} noidx
               ON p.ID = noidx.post_id
              AND noidx.meta_key = '_cel_noindex'
              AND noidx.meta_value = '1'
             WHERE p.post_type IN ({$placeholders}) AND p.post_status = 'publish'
             AND pm.meta_value > 0
             AND noidx.post_id IS NULL",
            ...$postTypes
        ));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function countPostType(string $pt): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} noidx
               ON p.ID = noidx.post_id
              AND noidx.meta_key = '_cel_noindex'
              AND noidx.meta_value = '1'
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND noidx.post_id IS NULL",
            $pt
        ));
    }

    private function countTaxonomy(string $tax): int
    {
        $result = \get_terms($this->taxonomyQueryArgs($tax, 0, 0, 'ids'));
        return \is_wp_error($result) || ! \is_array($result) ? 0 : \count($result);
    }

    private function countRecentPosts(int $maxAgeHours): int
    {
        global $wpdb;
        $cutoff = \gmdate('Y-m-d H:i:s', \time() - $maxAgeHours * 3600);
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'post' AND post_status = 'publish'
             AND post_date_gmt >= %s",
            $cutoff
        ));
    }

    private function countVideoPosts(): int
    {
        global $wpdb;

        $postTypes = $this->opts->sitemapPostTypes();
        if (empty($postTypes)) {
            return 0;
        }

        $placeholders = \implode(',', \array_fill(0, \count($postTypes), '%s'));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             LEFT JOIN {$wpdb->postmeta} noidx
               ON {$wpdb->posts}.ID = noidx.post_id
              AND noidx.meta_key = '_cel_noindex'
              AND noidx.meta_value = '1'
             WHERE post_type IN ({$placeholders}) AND post_status = 'publish'
             AND noidx.post_id IS NULL
             AND (post_content LIKE '%%youtube.com%%' OR post_content LIKE '%%youtu.be%%')",
            ...$postTypes
        ));
    }

    private function lastModifiedPostType(string $pt): string
    {
        global $wpdb;
        $ts = $wpdb->get_var($wpdb->prepare(
            "SELECT p.post_modified_gmt FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} noidx
               ON p.ID = noidx.post_id
              AND noidx.meta_key = '_cel_noindex'
              AND noidx.meta_value = '1'
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND noidx.post_id IS NULL
             ORDER BY p.post_modified_gmt DESC LIMIT 1",
            $pt
        ));
        $timestamp = $ts ? \strtotime($ts) : false;
        return $timestamp ? \gmdate('c', $timestamp) : \gmdate('c');
    }

    /**
     * @return array<string, mixed>
     */
    private function taxonomyQueryArgs(string $taxonomy, int $number = 0, int $offset = 0, string $fields = 'all'): array
    {
        return [
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'number'     => $number,
            'offset'     => $offset,
            'fields'     => $fields,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key'     => '_cel_noindex',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_cel_noindex',
                    'value'   => '1',
                    'compare' => '!=',
                ],
            ],
        ];
    }

    // ── Cache invalidation + pre-generation ─────────────────────────

    public function invalidateOnSave(int $postId, \WP_Post $post): void
    {
        if (\wp_is_post_revision($postId) || \wp_is_post_autosave($postId)) {
            return;
        }
        if (! \in_array($post->post_status, ['publish', 'trash'], true)) {
            return;
        }
        $this->invalidateAll();
    }

    /**
     * Invalidate all sitemap caches and schedule background pre-generation.
     *
     * 1. Increments version counter (invalidates transient cache keys).
     * 2. Flushes file cache.
     * 3. Schedules a debounced cron event for background pre-generation.
     */
    public function invalidateAll(): void
    {
        $current = (int) \get_option(self::CACHE_VERSION_KEY, 1);
        \update_option(self::CACHE_VERSION_KEY, $current + 1, true);

        $this->flushFileCache();

        // Schedule background pre-generation (debounced)
        if (! \wp_next_scheduled('cel_pregenerate_sitemaps')) {
            \wp_schedule_single_event(\time() + self::PREGENERATE_DELAY, 'cel_pregenerate_sitemaps');
        }
    }

    /**
     * Pre-generate all sitemaps in the background (cron callback).
     * Builds and caches every sitemap segment so frontend requests are instant.
     */
    public function pregenerateSitemaps(): void
    {
        // Generate index
        $this->getCachedOrGenerate('idx', fn() => $this->buildIndex());

        // Generate each post type sitemap
        foreach ($this->opts->sitemapPostTypes() as $pt) {
            $count = $this->countPostType($pt);
            if ($count === 0) {
                continue;
            }
            $chunks = (int) \ceil($count / self::MAX_URLS);
            for ($i = 1; $i <= $chunks; $i++) {
                $this->getCachedOrGenerate("{$pt}_p{$i}", fn() => $this->buildSitemap($pt, $i));
            }
        }

        // Generate each taxonomy sitemap
        foreach ($this->opts->sitemapTaxonomies() as $tax) {
            $count = $this->countTaxonomy($tax);
            if ($count === 0) {
                continue;
            }
            $chunks = (int) \ceil($count / self::MAX_URLS);
            for ($i = 1; $i <= $chunks; $i++) {
                $this->getCachedOrGenerate("{$tax}_p{$i}", fn() => $this->buildSitemap($tax, $i));
            }
        }

        // Generate news sitemap (always, even when empty)
        $this->getCachedOrGenerate('news', fn() => $this->buildNewsSitemap());

        // Generate image sitemap
        $imageCount = $this->countImagePosts();
        if ($imageCount > 0) {
            $chunks = (int) \ceil($imageCount / self::MAX_URLS);
            for ($i = 1; $i <= $chunks; $i++) {
                $this->getCachedOrGenerate("image_p{$i}", fn() => $this->buildImageSitemap($i));
            }
        }

        // Generate video sitemap
        $videoCount = $this->countVideoPosts();
        if ($videoCount > 0) {
            $chunks = (int) \ceil($videoCount / self::MAX_URLS);
            for ($i = 1; $i <= $chunks; $i++) {
                $this->getCachedOrGenerate("video_p{$i}", fn() => $this->buildVideoSitemap($i));
            }
        }
    }

    // ── XSL ──────────────────────────────────────────────────────────

    private function serveXsl(): void
    {
        $this->cleanOutputBuffer();
        \header('Content-Type: text/xsl; charset=UTF-8');
        \header('Cache-Control: public, max-age=86400');
        \header('X-Robots-Tag: noindex');
        require CEL_DIR . 'templates/sitemap-xsl.php';
    }

    // ── XML escaping ────────────────────────────────────────────────

    /**
     * Escape a string for safe XML output.
     *
     * Uses esc_xml() (WP 5.5+) which strips illegal XML 1.0 characters
     * (control chars \x00-\x08, \x0B, \x0C, \x0E-\x1F). Falls back to
     * manual stripping + esc_html() on older installs.
     */
    private function escXml(string $value): string
    {
        if (\function_exists('esc_xml')) {
            return \esc_xml($value);
        }

        // Strip characters illegal in XML 1.0
        $value = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
        return \esc_html($value);
    }

    // ── XML output ───────────────────────────────────────────────────

    private function sendXml(string $xml, int $status = 200): void
    {
        $this->cleanOutputBuffer();

        \status_header($status);
        \header('Content-Type: application/xml; charset=UTF-8');
        \header('Cache-Control: public, max-age=3600');
        \header('X-Robots-Tag: noindex');
        \header('X-Content-Type-Options: nosniff');

        if ($status === 200) {
            $etag = '"' . \md5($xml) . '"';
            \header('ETag: ' . $etag);

            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && \trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
                \status_header(304);
                return;
            }
        }

        echo $xml;
    }

    /**
     * Discard any output generated before the sitemap handler.
     *
     * Stray output from plugins, themes, or PHP notices would corrupt
     * the XML response and cause browsers to render it as invisible HTML.
     */
    private function cleanOutputBuffer(): void
    {
        if (! \headers_sent()) {
            while (\ob_get_level() > 0) {
                \ob_end_clean();
            }
        }
    }
}
