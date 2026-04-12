<?php

declare(strict_types=1);

use CelestialSitemap\Core\Activator;

abstract class CelestialSitemap_TestCase extends WP_UnitTestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        Activator::ensureSchema();
    }

    protected function set_up(): void
    {
        parent::set_up();
        $this->resetPluginState();
        Activator::ensureSchema();
    }

    protected function tear_down(): void
    {
        $this->resetPluginState();
        parent::tear_down();
    }

    /**
     * 共通で使う private メソッド呼び出し用ヘルパー。
     */
    protected function invokePrivateMethod(object|string $target, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);

        return $reflection->invokeArgs(
            is_string($target) ? null : $target,
            $args
        );
    }

    /**
     * プラグインのテーブルが存在するかを調べる。
     */
    protected function tableExists(string $tableName): bool
    {
        global $wpdb;

        $table = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . $tableName)
        );

        return $table === $wpdb->prefix . $tableName;
    }

    /**
     * テストごとにプラグイン状態をリセットする。
     */
    private function resetPluginState(): void
    {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE 'cel\_%'
                OR option_name LIKE '_transient_cel\_%'
                OR option_name LIKE '_transient_timeout_cel\_%'"
        );

        $wpdb->query("DELETE FROM {$wpdb->prefix}cel_redirects");
        $wpdb->query("DELETE FROM {$wpdb->prefix}cel_404_log");

        delete_transient('cel_404_rate_count');
        delete_transient('cel_404_row_count');
        delete_transient('cel_redirect_compiled');

        $this->clearScheduledHook('cel_cleanup_404_log');
        $this->clearScheduledHook('cel_pregenerate_sitemaps');
        $this->removePluginCacheDirectory();

        $GLOBALS['cel_wp_enqueued_styles'] = [];
        $GLOBALS['cel_wp_enqueued_scripts'] = [];
        $GLOBALS['cel_wp_localized_scripts'] = [];
        $GLOBALS['cel_test_term_counts'] = [];
        $GLOBALS['cel_test_terms'] = [];
        $GLOBALS['cel_test_term_meta'] = [];
        $GLOBALS['cel_test_posts'] = [];
        $GLOBALS['cel_test_post_meta'] = [];
        $GLOBALS['cel_test_taxonomies'] = [];
        $GLOBALS['cel_test_user_caps'] = [];
        $GLOBALS['cel_test_conditionals'] = [];
        $GLOBALS['cel_test_query_vars'] = [];
        $GLOBALS['cel_test_queried_object'] = null;
        $GLOBALS['cel_test_current_post'] = null;
        $GLOBALS['cel_test_current_post_id'] = 0;
        $GLOBALS['cel_test_document_title'] = '';
        $GLOBALS['cel_test_registered_post_types'] = [];
        $GLOBALS['cel_last_json_response'] = null;
        $GLOBALS['cel_last_status_header'] = null;
        $_POST = [];

        wp_cache_flush();
    }

    /**
     * 登録済み cron をテスト間で持ち越さないようにする。
     */
    private function clearScheduledHook(string $hook): void
    {
        while ($timestamp = wp_next_scheduled($hook)) {
            wp_unschedule_event($timestamp, $hook);
        }
    }

    /**
     * ディスク上の sitemap キャッシュも再帰的に掃除する。
     */
    private function removePluginCacheDirectory(): void
    {
        $dir = WP_CONTENT_DIR . '/cache/cel-sitemaps/' . get_current_blog_id();
        $this->removeDirectoryRecursive($dir);
    }

    private function removeDirectoryRecursive(string $dir): void
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
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}
