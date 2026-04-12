<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use CelestialSitemap\Core\NotFoundLogger;

final class NotFoundLoggerTest extends CelestialSitemap_TestCase
{
    private NotFoundLogger $logger;

    protected function set_up(): void
    {
        parent::set_up();
        $this->logger = new NotFoundLogger();
    }

    public function test_log404_inserts_and_updates_the_same_url(): void
    {
        global $wpdb;

        $this->prime404Request('/missing-page/', 'https://example.org/from', 'Agent/1.0');
        $this->logger->log404();

        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}cel_404_log LIMIT 1", ARRAY_A);

        // 初回は 1 件挿入され、URL とメタ情報が保存される。
        $this->assertSame('/missing-page/', $row['url']);
        $this->assertSame('https://example.org/from', $row['referrer']);
        $this->assertSame('Agent/1.0', $row['user_agent']);
        $this->assertSame(1, (int) $row['hit_count']);

        $this->prime404Request('/missing-page/', 'https://example.org/updated', 'Agent/2.0');
        $this->logger->log404();

        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}cel_404_log LIMIT 1", ARRAY_A);

        // 同じ URL は upsert され、hit_count 増加と最新メタ情報への更新が行われる。
        $this->assertSame(1, NotFoundLogger::countAll());
        $this->assertSame('https://example.org/updated', $row['referrer']);
        $this->assertSame('Agent/2.0', $row['user_agent']);
        $this->assertSame(2, (int) $row['hit_count']);
    }

    public function test_rate_limit_blocks_new_urls_after_the_limit_is_reached(): void
    {
        update_option('cel_404_rate_limit', 1);

        $this->prime404Request('/first-miss/', 'https://example.org/from-1', 'Agent/1.0');
        $this->logger->log404();

        $this->prime404Request('/second-miss/', 'https://example.org/from-2', 'Agent/2.0');
        $this->logger->log404();

        // 新規 URL は上限を超えると追加されない。
        $this->assertSame(1, NotFoundLogger::countAll());
        $this->assertSame(1, (int) get_transient('cel_404_rate_count'));
    }

    public function test_table_cap_still_allows_existing_urls_to_be_updated(): void
    {
        global $wpdb;

        update_option('cel_404_max_rows', 1);

        $wpdb->insert(
            $wpdb->prefix . 'cel_404_log',
            [
                'url'        => '/already-known/',
                'referrer'   => 'https://example.org/original',
                'user_agent' => 'Agent A',
                'hit_count'  => 1,
                'first_seen' => current_time('mysql', true),
                'last_seen'  => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s']
        );
        set_transient('cel_404_row_count', 1, 300);

        $this->prime404Request('/already-known/', 'https://example.org/updated', 'Agent B');
        $this->logger->log404();

        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}cel_404_log LIMIT 1", ARRAY_A);

        // 上限到達後も既存 URL の upsert は継続されるべき。
        $this->assertSame(1, NotFoundLogger::countAll());
        $this->assertSame(2, (int) $row['hit_count']);
        $this->assertSame('https://example.org/updated', $row['referrer']);
        $this->assertSame('Agent B', $row['user_agent']);
    }

    public function test_new_inserts_clear_the_cached_row_count_before_the_next_cap_check(): void
    {
        update_option('cel_404_max_rows', 1);
        set_transient('cel_404_row_count', 0, 300);

        $this->prime404Request('/first-miss/', 'https://example.org/first', 'Agent/1.0');
        $this->logger->log404();

        $this->assertFalse(get_transient('cel_404_row_count'));

        $this->prime404Request('/second-miss/', 'https://example.org/second', 'Agent/2.0');
        $this->logger->log404();

        // 新規 insert 後に件数キャッシュを捨て、次の判定では DB 件数で上限を守る。
        $this->assertSame(1, NotFoundLogger::countAll());
    }

    public function test_cleanup_removes_stale_rows_and_clears_cached_count(): void
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cel_404_log',
            [
                'url'        => '/old-entry/',
                'referrer'   => 'https://example.org/old',
                'user_agent' => 'Old Agent',
                'hit_count'  => 3,
                'first_seen' => gmdate('Y-m-d H:i:s', time() - (100 * DAY_IN_SECONDS)),
                'last_seen'  => gmdate('Y-m-d H:i:s', time() - (100 * DAY_IN_SECONDS)),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s']
        );

        $wpdb->insert(
            $wpdb->prefix . 'cel_404_log',
            [
                'url'        => '/recent-entry/',
                'referrer'   => 'https://example.org/recent',
                'user_agent' => 'Recent Agent',
                'hit_count'  => 1,
                'first_seen' => current_time('mysql', true),
                'last_seen'  => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s']
        );

        set_transient('cel_404_row_count', 99, 300);
        $this->logger->cleanup();

        // 90 日超の行だけ削除され、件数キャッシュも消える。
        $rows = NotFoundLogger::getAll();
        $this->assertCount(1, $rows);
        $this->assertSame('/recent-entry/', $rows[0]->url);
        $this->assertFalse(get_transient('cel_404_row_count'));
    }

    public function test_admin_helpers_can_list_delete_and_clear_entries(): void
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cel_404_log',
            [
                'url'        => '/less-popular/',
                'referrer'   => '',
                'user_agent' => 'Agent A',
                'hit_count'  => 1,
                'first_seen' => current_time('mysql', true),
                'last_seen'  => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s']
        );

        $wpdb->insert(
            $wpdb->prefix . 'cel_404_log',
            [
                'url'        => '/more-popular/',
                'referrer'   => '',
                'user_agent' => 'Agent B',
                'hit_count'  => 5,
                'first_seen' => current_time('mysql', true),
                'last_seen'  => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s']
        );

        // 管理画面向け helper は件数・並び順・削除系を提供する。
        $rows = NotFoundLogger::getAll();
        $this->assertCount(2, $rows);
        $this->assertSame('/more-popular/', $rows[0]->url);
        $this->assertSame(2, NotFoundLogger::countAll());

        $this->assertTrue(NotFoundLogger::deleteEntry((int) $rows[0]->id));
        $this->assertSame(1, NotFoundLogger::countAll());

        $this->assertTrue(NotFoundLogger::clearAll());
        $this->assertSame(0, NotFoundLogger::countAll());
    }

    /**
     * 404 状態とサーバー変数をテスト用に揃える。
     */
    private function prime404Request(string $uri, string $referrer, string $userAgent): void
    {
        $this->go_to($uri);
        $GLOBALS['wp_query']->set_404();

        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_REFERER'] = $referrer;
        $_SERVER['HTTP_USER_AGENT'] = $userAgent;
    }
}
