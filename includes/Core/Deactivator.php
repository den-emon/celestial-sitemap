<?php

declare(strict_types=1);

namespace CelestialSitemap\Core;

if (! defined('ABSPATH')) {
    exit;
}

final class Deactivator
{
    public static function deactivate(): void
    {
        // Clear plugin transients
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_cel\_%'
                OR option_name LIKE '_transient_timeout_cel\_%'"
        );

        // Unschedule cron events (loop ensures all instances are cleared)
        while ($ts = wp_next_scheduled('cel_cleanup_404_log')) {
            wp_unschedule_event($ts, 'cel_cleanup_404_log');
        }
        while ($ts = wp_next_scheduled('cel_pregenerate_sitemaps')) {
            wp_unschedule_event($ts, 'cel_pregenerate_sitemaps');
        }

        // Remove sitemap file cache (per-site directory)
        $blogId   = get_current_blog_id();
        $cacheDir = WP_CONTENT_DIR . '/cache/cel-sitemaps/' . $blogId;
        self::removeDirectory($cacheDir);

        flush_rewrite_rules(false);
    }

    /**
     * Safely remove a cache directory and all files inside it.
     * Skips subdirectories. Silently handles restricted environments.
     */
    public static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_file($path)) {
                unlink($path);
            }
        }

        @rmdir($dir);
    }
}
