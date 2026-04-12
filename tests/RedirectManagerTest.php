<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use CelestialSitemap\Redirect\RedirectManager;

final class RedirectManagerTest extends CelestialSitemap_TestCase
{
    private RedirectManager $manager;

    protected function set_up(): void
    {
        parent::set_up();
        $this->manager = new RedirectManager();
    }

    public function test_add_normalises_exact_sources_and_delete_removes_rows(): void
    {
        // exact ルールは末尾スラッシュ付きで保存される。
        $this->assertTrue(RedirectManager::add('/old-path', 'https://example.org/new-path', 302, 'exact'));

        $rows = RedirectManager::getAll();
        $this->assertCount(1, $rows);
        $this->assertSame('/old-path/', $rows[0]->source_url);
        $this->assertSame('https://example.org/new-path', $rows[0]->target_url);
        $this->assertSame(302, (int) $rows[0]->status_code);
        $this->assertSame(1, RedirectManager::countAll());

        // delete() 後は一覧件数からも消える。
        $this->assertTrue(RedirectManager::delete((int) $rows[0]->id));
        $this->assertSame(0, RedirectManager::countAll());
    }

    public function test_add_rejects_duplicate_sources(): void
    {
        RedirectManager::add('/duplicate', 'https://example.org/first', 301, 'exact');

        // 同じ source は重複登録できない。
        $this->expectException(\InvalidArgumentException::class);
        RedirectManager::add('/duplicate', 'https://example.org/second', 301, 'exact');
    }

    public function test_add_rejects_invalid_regex_patterns(): void
    {
        // 壊れた正規表現は保存前に弾く。
        $this->expectException(\InvalidArgumentException::class);
        RedirectManager::add('([a-z]+', 'https://example.org/target', 301, 'regex');
    }

    public function test_add_rejects_exact_redirects_that_resolve_to_the_same_url(): void
    {
        // source と target が同じ URL になる exact ルールは無効。
        $this->expectException(\InvalidArgumentException::class);
        RedirectManager::add('/same-path', 'https://example.org/same-path/', 301, 'exact');
    }

    public function test_add_detects_simple_two_way_loops_for_exact_redirects(): void
    {
        RedirectManager::add('/source', 'https://example.org/target/', 301, 'exact');

        // target 側から source へ戻るルールはループとして拒否する。
        $this->expectException(\InvalidArgumentException::class);
        RedirectManager::add('/target', 'https://example.org/source/', 301, 'exact');
    }

    public function test_add_rejects_prefix_redirects_that_resolve_to_the_same_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RedirectManager::add('/docs', 'https://example.org/docs/', 301, 'prefix');
    }

    public function test_add_detects_loops_between_prefix_and_existing_exact_redirects(): void
    {
        RedirectManager::add('/manual', 'https://example.org/docs/', 301, 'exact');

        $this->expectException(\InvalidArgumentException::class);
        RedirectManager::add('/docs', 'https://example.org/manual/', 301, 'prefix');
    }

    public function test_add_rejects_regex_redirects_that_resolve_back_to_the_same_match(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RedirectManager::add('/products/(\\d+)/', 'https://example.org/products/$1/', 301, 'regex');
    }

    public function test_find_redirect_prefers_exact_and_supports_prefix_and_regex_matches(): void
    {
        RedirectManager::add('/docs', 'https://example.org/manual', 301, 'prefix');
        RedirectManager::add('/docs/api', 'https://example.org/reference', 302, 'exact');
        RedirectManager::add('/products/(\\d+)/', 'https://example.org/items/$1/', 307, 'regex');

        // より具体的な exact ルールが prefix より優先される。
        $exact = $this->invokePrivateMethod($this->manager, 'findRedirect', ['/docs/api/']);
        $this->assertSame('https://example.org/reference', $exact['target_url']);
        $this->assertSame(302, $exact['status_code']);

        // prefix ルールは残りのパスを target の後ろへ連結する。
        $prefix = $this->invokePrivateMethod($this->manager, 'findRedirect', ['/docs/guide/getting-started/']);
        $this->assertSame('https://example.org/manual/guide/getting-started/', $prefix['target_url']);
        $this->assertSame(301, $prefix['status_code']);

        // regex ルールはキャプチャを $1 形式で差し替える。
        $regex = $this->invokePrivateMethod($this->manager, 'findRedirect', ['/products/42/']);
        $this->assertSame('https://example.org/items/42/', $regex['target_url']);
        $this->assertSame(307, $regex['status_code']);
    }

    public function test_find_via_direct_supports_prefix_and_regex_matches(): void
    {
        RedirectManager::add('/docs', 'https://example.org/manual', 301, 'prefix');
        RedirectManager::add('/docs/api', 'https://example.org/reference', 302, 'exact');
        RedirectManager::add('/products/(\\d+)/', 'https://example.org/items/$1/', 307, 'regex');

        // large-table fallback でも exact / prefix / regex の優先順を保つ。
        $exact = $this->invokePrivateMethod($this->manager, 'findViaDirect', ['/docs/api/']);
        $this->assertSame('https://example.org/reference', $exact['target_url']);
        $this->assertSame(302, $exact['status_code']);

        $prefix = $this->invokePrivateMethod($this->manager, 'findViaDirect', ['/docs/guide/getting-started/']);
        $this->assertSame('https://example.org/manual/guide/getting-started/', $prefix['target_url']);
        $this->assertSame(301, $prefix['status_code']);

        $regex = $this->invokePrivateMethod($this->manager, 'findViaDirect', ['/products/42/']);
        $this->assertSame('https://example.org/items/42/', $regex['target_url']);
        $this->assertSame(307, $regex['status_code']);
    }
}
