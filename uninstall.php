<?php
/**
 * Uninstall – runs only when the plugin is deleted via WP admin.
 *
 * Handles both single-site and multisite installations.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up all plugin data for the current site.
 */
function cel_uninstall_site(): void
{
    global $wpdb;

    // Remove all options
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cel\_%'");

    // Remove transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_cel\_%'
            OR option_name LIKE '_transient_timeout_cel\_%'"
    );

    // Remove post meta
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_cel\_%'");

    // Remove term meta
    $wpdb->query("DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '_cel\_%'");

    // Drop custom tables
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cel_redirects");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cel_404_log");

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
    cel_remove_directory($cacheDir);
}

/**
 * Safely remove a cache directory and all files inside it.
 * Skips subdirectories. Silently handles restricted environments.
 */
function cel_remove_directory(string $dir): void
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

// Handle multisite: clean up all sites in the network
if (is_multisite()) {
    $siteIds = get_sites([
        'fields'     => 'ids',
        'number'     => 0, // all sites
        'network_id' => get_current_network_id(),
    ]);

    foreach ($siteIds as $siteId) {
        switch_to_blog($siteId);
        cel_uninstall_site();
        restore_current_blog();
    }

    // Remove parent cache directory if now empty
    $parentDir = WP_CONTENT_DIR . '/cache/cel-sitemaps';
    if (is_dir($parentDir)) {
        @rmdir($parentDir);
    }
} else {
    cel_uninstall_site();

    // Remove parent cache directory if now empty
    $parentDir = WP_CONTENT_DIR . '/cache/cel-sitemaps';
    if (is_dir($parentDir)) {
        @rmdir($parentDir);
    }
}

// Flush rewrite rules (only once, on the current site)
flush_rewrite_rules(false);
