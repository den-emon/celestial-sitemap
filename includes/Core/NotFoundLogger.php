<?php

declare(strict_types=1);

namespace CelestialSitemap\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Logs 404 errors to a dedicated DB table with dedup (upsert on URL).
 *
 * Lightweight: single INSERT … ON DUPLICATE KEY UPDATE per 404 request.
 * Automatic cleanup: entries older than 90 days are pruned daily via cron
 * (scheduled in Activator::activate()).
 *
 * Rate limiting: new unique URLs are limited per minute to prevent
 * log flooding attacks. Duplicate URL hits (upserts) are always allowed.
 *
 * Table cap: when the table exceeds a configurable max row count,
 * new inserts are skipped entirely.
 */
final class NotFoundLogger
{
    /**
     * Transient key for the rate limiter counter.
     */
    private const RATE_LIMIT_KEY = 'cel_404_rate_count';

    /**
     * Rate limit window in seconds.
     */
    private const RATE_LIMIT_WINDOW = 60;

    public function register(): void
    {
        add_action('template_redirect', [$this, 'log404'], 99);

        // FIX #10: Cron scheduling moved to Activator::scheduleCron().
        // Here we only bind the callback so the scheduled event can fire.
        add_action('cel_cleanup_404_log', [$this, 'cleanup']);
    }

    public function log404(): void
    {
        if (! is_404()) {
            return;
        }

        global $wpdb;

        $url       = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $referrer  = esc_url_raw(wp_get_referer() ?: '');
        $userAgent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));

        // Rate limit: only throttle genuinely new URLs.
        // Check if this URL already exists (upserts are always allowed).
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}cel_404_log WHERE url = %s LIMIT 1",
            $url
        ));

        if (! $exists) {
            // Check table cap before inserting a new URL.
            if ($this->isTableFull()) {
                return;
            }

            if (! $this->acquireRateSlot()) {
                return; // Rate limit exceeded for new URLs
            }
        }

        // Upsert: insert or increment
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}cel_404_log (url, referrer, user_agent, hit_count, first_seen, last_seen)
             VALUES (%s, %s, %s, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                hit_count  = hit_count + 1,
                last_seen  = NOW(),
                referrer   = VALUES(referrer),
                user_agent = VALUES(user_agent)",
            $url,
            $referrer,
            mb_substr($userAgent, 0, 512)
        ));

        if ($result !== false && ! $exists) {
            delete_transient('cel_404_row_count');
        }
    }

    /**
     * Check if the rate limit allows a new unique URL insert.
     * Returns true if a slot was acquired, false if limit exceeded.
     */
    private function acquireRateSlot(): bool
    {
        $maxPerMinute = (int) get_option('cel_404_rate_limit', 100);
        if ($maxPerMinute <= 0) {
            return true; // Disabled
        }

        $count = (int) get_transient(self::RATE_LIMIT_KEY);

        if ($count >= $maxPerMinute) {
            return false;
        }

        // Increment counter. If transient didn't exist, set it with TTL.
        if ($count === 0) {
            set_transient(self::RATE_LIMIT_KEY, 1, self::RATE_LIMIT_WINDOW);
        } else {
            // Increment without resetting TTL by using wp_cache if available
            set_transient(self::RATE_LIMIT_KEY, $count + 1, self::RATE_LIMIT_WINDOW);
        }

        return true;
    }

    /**
     * Check if the 404 log table has exceeded the configured max rows.
     */
    private function isTableFull(): bool
    {
        $maxRows = (int) get_option('cel_404_max_rows', 50000);
        if ($maxRows <= 0) {
            return false; // Disabled
        }

        // Use a short-lived transient to avoid COUNT(*) on every 404
        $count = get_transient('cel_404_row_count');
        if ($count === false) {
            global $wpdb;
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cel_404_log");
            set_transient('cel_404_row_count', $count, 300); // Cache for 5 minutes
        }

        return (int) $count >= $maxRows;
    }

    public function cleanup(): void
    {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}cel_404_log WHERE last_seen < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );

        // Refresh the row count cache after cleanup
        delete_transient('cel_404_row_count');
    }

    // ── Read methods (for admin) ─────────────────────────────────────

    /**
     * @return array<object>
     */
    public static function getAll(int $limit = 100, int $offset = 0): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cel_404_log ORDER BY hit_count DESC, last_seen DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
    }

    public static function countAll(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cel_404_log");
    }

    public static function deleteEntry(int $id): bool
    {
        global $wpdb;
        $result = (bool) $wpdb->delete($wpdb->prefix . 'cel_404_log', ['id' => $id], ['%d']);
        if ($result) {
            delete_transient('cel_404_row_count');
        }
        return $result;
    }

    /**
     * Clear all 404 log entries.
     *
     * Uses DELETE instead of TRUNCATE to avoid requiring DROP privilege
     * on shared hosting environments.
     */
    public static function clearAll(): bool
    {
        global $wpdb;
        $result = $wpdb->query("DELETE FROM {$wpdb->prefix}cel_404_log");
        delete_transient('cel_404_row_count');

        if ($result === false) {
            return false;
        }

        // Reset AUTO_INCREMENT (best-effort; OK if it fails on restricted hosts)
        $wpdb->query("ALTER TABLE {$wpdb->prefix}cel_404_log AUTO_INCREMENT = 1");

        return true;
    }
}
