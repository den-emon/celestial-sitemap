<?php

declare(strict_types=1);

namespace CelestialSitemap\Redirect;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Redirect engine with compiled redirect map.
 *
 * Supports three match types:
 *  - exact:  Hash-based O(1) lookup for exact path matches.
 *  - prefix: Longest-prefix-first matching (e.g., /blog/ → /news/).
 *  - regex:  PCRE pattern matching with backreference substitution.
 *
 * Compiled map structure (cached in transient):
 *   [
 *     'exact'  => ['/path/' => ['id'=>1, 'target_url'=>'...', 'status_code'=>301], ...],
 *     'prefix' => [['source'=>'/blog/', 'target_url'=>'/news/', ...], ...],
 *     'regex'  => [['pattern'=>'/old-(\d+)/', 'target_url'=>'/new-$1/', ...], ...],
 *   ]
 *
 * Prefix rules are sorted by source length (longest first) for correct matching.
 * The compiled map is rebuilt when redirect rules change (add/delete).
 *
 * For tables > CACHE_LIMIT, falls back to direct indexed DB queries.
 */
final class RedirectManager
{
    private const CACHE_KEY    = 'cel_redirect_compiled';
    private const CACHE_TTL    = 3600;
    private const CACHE_LIMIT  = 10000;

    public function register(): void
    {
        add_action('template_redirect', [$this, 'handleRedirect'], 1);
    }

    public function handleRedirect(): void
    {
        $requestPath = $this->normalise(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        if ($requestPath === '' || $requestPath === '/') {
            return;
        }

        $redirect = $this->findRedirect($requestPath);
        if ($redirect === null) {
            return;
        }

        // Increment counter
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}cel_redirects SET hit_count = hit_count + 1 WHERE id = %d",
            $redirect['id']
        ));

        wp_redirect(esc_url_raw($redirect['target_url']), $redirect['status_code']);
        exit;
    }

    /**
     * Find a redirect for the given path using the compiled map.
     *
     * @return array{id: int, target_url: string, status_code: int}|null
     */
    private function findRedirect(string $path): ?array
    {
        // Try compiled map from cache
        $map = get_transient(self::CACHE_KEY);
        if (is_array($map)) {
            return $this->matchCompiledMap($map, $path);
        }

        // Cache miss — check table size
        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cel_redirects");

        if ($count <= self::CACHE_LIMIT) {
            $map = $this->buildCompiledMap();
            set_transient(self::CACHE_KEY, $map, self::CACHE_TTL);
            return $this->matchCompiledMap($map, $path);
        }

        // Large table: direct DB lookup (exact only; prefix/regex require full scan)
        return $this->findViaDirect($path);
    }

    // ── Compiled map ────────────────────────────────────────────────

    /**
     * Build the compiled redirect map from all redirect rules.
     *
     * @return array{exact: array, prefix: array, regex: array}
     */
    private function buildCompiledMap(): array
    {
        global $wpdb;

        $map = [
            'exact'  => [],
            'prefix' => [],
            'regex'  => [],
        ];

        // Use SELECT * to gracefully handle missing match_type column
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}cel_redirects",
            ARRAY_A
        );

        if (! is_array($rows)) {
            return $map;
        }

        foreach ($rows as $row) {
            $matchType = $row['match_type'] ?? 'exact';
            $entry = [
                'id'          => (int) $row['id'],
                'target_url'  => $row['target_url'],
                'status_code' => (int) $row['status_code'],
            ];

            switch ($matchType) {
                case 'prefix':
                    $entry['source'] = $row['source_url'];
                    $map['prefix'][] = $entry;
                    break;

                case 'regex':
                    $entry['pattern'] = $row['source_url'];
                    $map['regex'][]   = $entry;
                    break;

                default: // 'exact'
                    $map['exact'][$row['source_url']] = $entry;
                    break;
            }
        }

        // Sort prefix rules by source length (longest first) for correct matching
        usort($map['prefix'], static fn(array $a, array $b) => strlen($b['source']) <=> strlen($a['source']));

        return $map;
    }

    /**
     * Match a path against the compiled map.
     * Order: exact → prefix → regex (fast to slow).
     *
     * @param array{exact: array, prefix: array, regex: array} $map
     * @return array{id: int, target_url: string, status_code: int}|null
     */
    private function matchCompiledMap(array $map, string $path): ?array
    {
        // 1. Exact match (O(1) hash lookup)
        if (isset($map['exact'][$path])) {
            return $map['exact'][$path];
        }

        // 2. Prefix match (longest-prefix-first)
        foreach ($map['prefix'] as $rule) {
            if (str_starts_with($path, $rule['source'])) {
                $remainder = substr($path, strlen($rule['source']));
                $targetUrl = rtrim($rule['target_url'], '/') . '/' . ltrim($remainder, '/');
                return [
                    'id'          => $rule['id'],
                    'target_url'  => $targetUrl,
                    'status_code' => $rule['status_code'],
                ];
            }
        }

        // 3. Regex match
        foreach ($map['regex'] as $rule) {
            $pattern = '#' . str_replace('#', '\\#', $rule['pattern']) . '#';
            if (@preg_match($pattern, $path, $matches)) {
                // Substitute backreferences ($1, $2, ...) in target URL
                $targetUrl = $rule['target_url'];
                for ($i = 1; $i < count($matches); $i++) {
                    $targetUrl = str_replace('$' . $i, $matches[$i], $targetUrl);
                }
                return [
                    'id'          => $rule['id'],
                    'target_url'  => $targetUrl,
                    'status_code' => $rule['status_code'],
                ];
            }
        }

        return null;
    }

    /**
     * Direct indexed DB lookup for large tables (exact match only).
     */
    private function findViaDirect(string $path): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, target_url, status_code FROM {$wpdb->prefix}cel_redirects
             WHERE source_url = %s AND (match_type = 'exact' OR match_type IS NULL)
             LIMIT 1",
            $path
        ), ARRAY_A);

        if (! $row) {
            // Fallback: try without match_type filter (column may not exist yet)
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, target_url, status_code FROM {$wpdb->prefix}cel_redirects
                 WHERE source_url = %s LIMIT 1",
                $path
            ), ARRAY_A);
        }

        if (! $row) {
            return null;
        }

        return [
            'id'          => (int) $row['id'],
            'target_url'  => $row['target_url'],
            'status_code' => (int) $row['status_code'],
        ];
    }

    /**
     * Invalidate the compiled redirect map cache.
     */
    public static function invalidateCache(): void
    {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Normalise a request URI: strip query strings, URL-decode, ensure trailing slash.
     */
    private function normalise(string $uri): string
    {
        $parsed = wp_parse_url($uri);
        $path   = $parsed['path'] ?? '/';
        $path   = rawurldecode($path);
        return rtrim($path, '/') . '/';
    }

    // ── CRUD (used by admin) ─────────────────────────────────────────

    /**
     * @return array<object>
     */
    public static function getAll(int $limit = 100, int $offset = 0): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cel_redirects ORDER BY updated_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
    }

    public static function countAll(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cel_redirects");
    }

    /**
     * Add a redirect rule.
     *
     * @param string $source    Source URL path (exact), prefix, or regex pattern.
     * @param string $target    Target URL.
     * @param int    $code      HTTP status code (301, 302, 307, 308).
     * @param string $matchType Match type: 'exact', 'prefix', or 'regex'.
     * @return true on success.
     * @throws \InvalidArgumentException on validation failure.
     */
    public static function add(string $source, string $target, int $code = 301, string $matchType = 'exact'): bool
    {
        if (! in_array($matchType, ['exact', 'prefix', 'regex'], true)) {
            $matchType = 'exact';
        }

        // Normalise source for exact/prefix matches
        if ($matchType !== 'regex') {
            $source = rtrim(wp_parse_url($source, PHP_URL_PATH) ?: $source, '/') . '/';
        }

        // Validate regex pattern
        if ($matchType === 'regex') {
            $testPattern = '#' . str_replace('#', '\\#', $source) . '#';
            if (@preg_match($testPattern, '') === false) {
                throw new \InvalidArgumentException(
                    __('Invalid regex pattern.', 'celestial-sitemap')
                );
            }
        }

        // Normalise target path for comparison (exact only)
        if ($matchType === 'exact') {
            $targetPath = wp_parse_url($target, PHP_URL_PATH);
            $targetNorm = $targetPath !== null && $targetPath !== false
                ? rtrim($targetPath, '/') . '/'
                : '';

            if ($targetNorm === $source) {
                throw new \InvalidArgumentException(
                    __('Source and target resolve to the same URL.', 'celestial-sitemap')
                );
            }
        }

        // Reject duplicate source
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cel_redirects WHERE source_url = %s LIMIT 1",
            $source
        ));
        if ($exists !== null) {
            throw new \InvalidArgumentException(
                __('A redirect for this source URL already exists.', 'celestial-sitemap')
            );
        }

        // Detect simple loop (exact only)
        if ($matchType === 'exact') {
            $targetNorm = $targetNorm ?? '';
            $loopTarget = $wpdb->get_var($wpdb->prepare(
                "SELECT target_url FROM {$wpdb->prefix}cel_redirects WHERE source_url = %s LIMIT 1",
                $targetNorm
            ));
            if ($loopTarget !== null) {
                $loopTargetPath = wp_parse_url($loopTarget, PHP_URL_PATH);
                $loopTargetNorm = $loopTargetPath !== null && $loopTargetPath !== false
                    ? rtrim($loopTargetPath, '/') . '/'
                    : '';
                if ($loopTargetNorm === $source) {
                    throw new \InvalidArgumentException(
                        __('This redirect would create a loop: the target already redirects back to the source.', 'celestial-sitemap')
                    );
                }
            }
        }

        // Build insert data — handle missing match_type column gracefully
        $insertData = [
            'source_url'  => $source,
            'target_url'  => $target,
            'status_code' => $code,
        ];
        $insertFormats = ['%s', '%s', '%d'];

        // Check if match_type column exists before including it
        if (self::hasMatchTypeColumn()) {
            $insertData['match_type'] = $matchType;
            $insertFormats[] = '%s';
        }

        $result = (bool) $wpdb->insert(
            $wpdb->prefix . 'cel_redirects',
            $insertData,
            $insertFormats
        );

        if ($result) {
            self::invalidateCache();
        }

        return $result;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        $result = (bool) $wpdb->delete($wpdb->prefix . 'cel_redirects', ['id' => $id], ['%d']);

        if ($result) {
            self::invalidateCache();
        }

        return $result;
    }

    /**
     * Check if the match_type column exists in the redirects table.
     * Result is cached statically for the duration of the request.
     */
    private static function hasMatchTypeColumn(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }

        global $wpdb;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}cel_redirects");
        $has = in_array('match_type', $columns, true);
        return $has;
    }
}
