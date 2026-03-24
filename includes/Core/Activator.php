<?php

declare(strict_types=1);

namespace CelestialSitemap\Core;

if (! defined('ABSPATH')) {
    exit;
}

final class Activator
{
    public static function activate(): void
    {
        self::createTables();
        self::setDefaultOptions();
        self::addRewriteRules();
        self::scheduleCron();
        flush_rewrite_rules(false);
    }

    /**
     * Run only DB schema updates (safe to call from Migrator at plugins_loaded).
     * Does NOT flush rewrite rules or schedule cron — those require full init context.
     */
    public static function ensureSchema(): void
    {
        self::createTables();
        self::setDefaultOptions();
    }

    private static function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // FIX #8: dbDelta requires exact format WITHOUT "IF NOT EXISTS"
        // dbDelta uses internal diff to decide CREATE vs ALTER.
        $sql_redirects = "CREATE TABLE {$wpdb->prefix}cel_redirects (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_url  VARCHAR(2048) NOT NULL,
            target_url  VARCHAR(2048) NOT NULL,
            status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
            match_type  VARCHAR(10) NOT NULL DEFAULT 'exact',
            hit_count   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_source (source_url(191)),
            KEY idx_match_type (match_type)
        ) {$charset};";

        $sql_404 = "CREATE TABLE {$wpdb->prefix}cel_404_log (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            url         VARCHAR(2048) NOT NULL,
            referrer    VARCHAR(2048) DEFAULT '',
            user_agent  VARCHAR(512) DEFAULT '',
            hit_count   BIGINT UNSIGNED NOT NULL DEFAULT 1,
            first_seen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_url (url(191)),
            KEY idx_hits (hit_count, last_seen),
            KEY idx_last_seen (last_seen)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_redirects);
        dbDelta($sql_404);
    }

    private static function setDefaultOptions(): void
    {
        // Options that are read on nearly every frontend request should autoload.
        // Format: key => [default, autoload]
        $defaults = [
            'cel_version'            => [CEL_VERSION, true],
            'cel_title_separator'    => ['|', true],
            'cel_homepage_title'     => ['', true],
            'cel_homepage_desc'      => ['', true],
            'cel_noindex_archives'   => [0, true],
            'cel_noindex_tags'       => [0, true],
            'cel_noindex_author'     => [1, true],
            'cel_noindex_date'       => [1, true],
            'cel_noindex_search'     => [1, true],
            'cel_sitemap_post_types' => [['post', 'page'], false],
            'cel_sitemap_taxonomies' => [['category'], false],
            'cel_schema_org'         => [1, true],
            'cel_schema_org_type'    => ['Organization', true],
            'cel_schema_org_name'    => ['', true],
            'cel_schema_org_logo'    => ['', true],
            'cel_ogp_enabled'        => [1, true],
            'cel_ogp_default_image'  => ['', true],
            'cel_breadcrumbs'        => [1, true],
            'cel_fb_app_id'          => ['', true],
            'cel_twitter_site'       => ['', true],
            'cel_gsc_client_id'      => ['', false],
            'cel_gsc_client_secret'  => ['', false],
            'cel_gsc_access_token'   => ['', false],
            'cel_indexnow_key'       => ['', false],
            'cel_404_rate_limit'     => [100, false],
            'cel_404_max_rows'       => [50000, false],
        ];

        foreach ($defaults as $key => [$value, $autoload]) {
            if (get_option($key) === false) {
                add_option($key, $value, '', $autoload);
            }
        }
    }

    private static function addRewriteRules(): void
    {
        add_rewrite_rule('^cel-sitemap\.xml/?$', 'index.php?cel_sitemap=index', 'top');
        add_rewrite_rule('^cel-sitemap-([a-z0-9_-]+?)-(\d+)\.xml/?$', 'index.php?cel_sitemap=$matches[1]&cel_sitemap_page=$matches[2]', 'top');
        add_rewrite_rule('^cel-sitemap-([a-z0-9_-]+?)\.xml/?$', 'index.php?cel_sitemap=$matches[1]', 'top');
        add_rewrite_rule('^cel-sitemap\.xsl/?$', 'index.php?cel_xsl=1', 'top');
    }

    /**
     * FIX #10: Schedule cron at activation, not on every page load.
     */
    private static function scheduleCron(): void
    {
        if (! wp_next_scheduled('cel_cleanup_404_log')) {
            wp_schedule_event(time(), 'daily', 'cel_cleanup_404_log');
        }
    }
}
