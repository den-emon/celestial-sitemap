<?php

declare(strict_types=1);

namespace CelestialSitemap\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Centralised, typed access to plugin options.
 *
 * All cel_* options are bulk-loaded in a single DB query on first access
 * and cached for the duration of the request. This eliminates per-option
 * DB hits for non-autoloaded options.
 *
 * Frequently-used options are also set to autoload=true (see Activator).
 */
final class Options
{
    /**
     * In-memory cache of all cel_* options, keyed by option name.
     * Populated lazily on first access via loadAll().
     *
     * @var array<string, mixed>|null
     */
    private ?array $cache = null;

    /**
     * Options that are allowed to be set via the generic setter.
     *
     * @var string[]
     */
    private const ALLOWED_KEYS = [
        'cel_title_separator',
        'cel_homepage_title',
        'cel_homepage_desc',
        'cel_noindex_archives',
        'cel_noindex_tags',
        'cel_noindex_author',
        'cel_noindex_date',
        'cel_noindex_search',
        'cel_sitemap_post_types',
        'cel_sitemap_taxonomies',
        'cel_schema_org',
        'cel_schema_org_type',
        'cel_schema_org_name',
        'cel_schema_org_logo',
        'cel_ogp_enabled',
        'cel_ogp_default_image',
        'cel_breadcrumbs',
        'cel_gsc_client_id',
        'cel_gsc_client_secret',
        'cel_gsc_access_token',
        'cel_indexnow_key',
        'cel_fb_app_id',
        'cel_twitter_site',
    ];

    // ── Bulk loader ─────────────────────────────────────────────────

    /**
     * Load all cel_* options in a single query and cache them.
     */
    private function loadAll(): void
    {
        if ($this->cache !== null) {
            return;
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'cel\_%'",
            ARRAY_A
        );

        $this->cache = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $this->cache[$row['option_name']] = maybe_unserialize($row['option_value']);
            }
        }
    }

    /**
     * Get a cached option value with fallback default.
     */
    private function get(string $key, mixed $default = ''): mixed
    {
        $this->loadAll();
        return $this->cache[$key] ?? $default;
    }

    // ── Title / Meta ─────────────────────────────────────────────────
    public function titleSeparator(): string
    {
        return (string) $this->get('cel_title_separator', '|');
    }

    public function homepageTitle(): string
    {
        return (string) $this->get('cel_homepage_title', '');
    }

    public function homepageDesc(): string
    {
        return (string) $this->get('cel_homepage_desc', '');
    }

    // ── Noindex ──────────────────────────────────────────────────────
    public function noindexArchives(): bool
    {
        return (bool) $this->get('cel_noindex_archives', false);
    }

    public function noindexTags(): bool
    {
        return (bool) $this->get('cel_noindex_tags', false);
    }

    public function noindexAuthor(): bool
    {
        return (bool) $this->get('cel_noindex_author', true);
    }

    public function noindexDate(): bool
    {
        return (bool) $this->get('cel_noindex_date', true);
    }

    public function noindexSearch(): bool
    {
        return (bool) $this->get('cel_noindex_search', true);
    }

    // ── Sitemap ──────────────────────────────────────────────────────
    /** @return string[] */
    public function sitemapPostTypes(): array
    {
        $val = $this->get('cel_sitemap_post_types', ['post', 'page']);
        return is_array($val) ? $val : ['post', 'page'];
    }

    /** @return string[] */
    public function sitemapTaxonomies(): array
    {
        $val = $this->get('cel_sitemap_taxonomies', ['category']);
        return is_array($val) ? $val : ['category'];
    }

    // ── Schema ───────────────────────────────────────────────────────
    public function schemaEnabled(): bool
    {
        return (bool) $this->get('cel_schema_org', true);
    }

    public function schemaOrgType(): string
    {
        return (string) $this->get('cel_schema_org_type', 'Organization');
    }

    public function schemaOrgName(): string
    {
        return (string) $this->get('cel_schema_org_name', '');
    }

    public function schemaOrgLogo(): string
    {
        return (string) $this->get('cel_schema_org_logo', '');
    }

    // ── OGP ──────────────────────────────────────────────────────────
    public function ogpEnabled(): bool
    {
        return (bool) $this->get('cel_ogp_enabled', true);
    }

    public function ogpDefaultImage(): string
    {
        return (string) $this->get('cel_ogp_default_image', '');
    }

    // ── Breadcrumbs ──────────────────────────────────────────────────
    public function breadcrumbsEnabled(): bool
    {
        return (bool) $this->get('cel_breadcrumbs', true);
    }

    // ── Social ───────────────────────────────────────────────────────
    public function fbAppId(): string
    {
        return (string) $this->get('cel_fb_app_id', '');
    }

    public function twitterSite(): string
    {
        return (string) $this->get('cel_twitter_site', '');
    }

    // ── GSC (encrypted) ─────────────────────────────────────────────
    public function gscClientId(): string
    {
        return (string) $this->get('cel_gsc_client_id', '');
    }

    public function gscClientSecret(): string
    {
        return self::decrypt((string) $this->get('cel_gsc_client_secret', ''));
    }

    public function gscAccessToken(): string
    {
        return self::decrypt((string) $this->get('cel_gsc_access_token', ''));
    }

    // ── IndexNow ─────────────────────────────────────────────────────
    public function indexNowKey(): string
    {
        return (string) $this->get('cel_indexnow_key', '');
    }

    // ── Generic setter ───────────────────────────────────────────────

    /**
     * Set an option value. Only keys in the ALLOWED_KEYS list are accepted.
     *
     * @throws \InvalidArgumentException If the key is not in the allowed list.
     */
    public function set(string $key, mixed $value): void
    {
        if (! in_array($key, self::ALLOWED_KEYS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Option key "%s" is not in the allowed list.', $key)
            );
        }

        update_option($key, $value);

        // Update in-memory cache
        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
    }

    /**
     * Invalidate the in-memory cache so the next access reloads from DB.
     */
    public function invalidateCache(): void
    {
        $this->cache = null;
    }

    // ── Encryption helpers (for GSC credentials) ─────────────────────

    private const CIPHER_ALGO = 'aes-256-cbc';

    /**
     * Encrypt a plaintext value using AES-256-CBC with a random IV.
     *
     * Format: enc:{base64(iv)}:{base64(ciphertext)}
     *
     * Each call generates a unique random IV, so the same plaintext
     * produces different ciphertext (non-deterministic encryption).
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        if (! function_exists('openssl_encrypt')) {
            return $plaintext;
        }

        $key    = hash('sha256', wp_salt('auth'), true);
        $ivLen  = openssl_cipher_iv_length(self::CIPHER_ALGO);
        $iv     = openssl_random_pseudo_bytes($ivLen ?: 16);
        $cipher = openssl_encrypt($plaintext, self::CIPHER_ALGO, $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            return $plaintext;
        }

        return 'enc:' . base64_encode($iv) . ':' . base64_encode($cipher);
    }

    /**
     * Decrypt a value encrypted by self::encrypt().
     *
     * Supports two formats for backward compatibility:
     *  - New: enc:{base64_iv}:{base64_cipher}  (random IV)
     *  - Legacy: enc:{base64_cipher}            (deterministic IV from wp_salt)
     *  - Plaintext: returned as-is
     */
    public static function decrypt(string $ciphertext): string
    {
        if ($ciphertext === '' || ! str_starts_with($ciphertext, 'enc:')) {
            return $ciphertext;
        }

        if (! function_exists('openssl_decrypt')) {
            return $ciphertext;
        }

        $payload = substr($ciphertext, 4);
        $key     = hash('sha256', wp_salt('auth'), true);

        // New format: enc:{base64_iv}:{base64_cipher}
        if (substr_count($payload, ':') === 1) {
            [$ivB64, $cipherB64] = explode(':', $payload, 2);
            $iv     = base64_decode($ivB64, true);
            $cipher = base64_decode($cipherB64, true);

            if ($iv !== false && $cipher !== false) {
                $plain = openssl_decrypt($cipher, self::CIPHER_ALGO, $key, OPENSSL_RAW_DATA, $iv);
                if ($plain !== false) {
                    return $plain;
                }
            }
        }

        // Legacy format: enc:{base64_cipher} with deterministic IV
        $iv    = substr(hash('sha256', wp_salt('secure_auth'), true), 0, 16);
        $plain = openssl_decrypt($payload, self::CIPHER_ALGO, $key, 0, $iv);

        return $plain !== false ? $plain : $ciphertext;
    }
}
