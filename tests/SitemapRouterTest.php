<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use CelestialSitemap\Core\Options;
use CelestialSitemap\Sitemap\SitemapRouter;

final class SitemapRouterTest extends CelestialSitemap_TestCase
{
    private Options $options;
    private SitemapRouter $router;

    protected function set_up(): void
    {
        parent::set_up();

        $this->options = new Options();
        $this->options->set('cel_sitemap_post_types', []);
        $this->options->set('cel_sitemap_taxonomies', ['category']);

        $this->router = new SitemapRouter($this->options);
    }

    public function test_build_index_lists_all_taxonomy_pages_when_term_count_exceeds_max_urls(): void
    {
        $this->seedTaxonomy('category', 5001);

        $xml = $this->invokePrivateMethod($this->router, 'buildIndex');

        $this->assertStringContainsString('https://example.org/cel-sitemap-category-1.xml', $xml);
        $this->assertStringContainsString('https://example.org/cel-sitemap-category-2.xml', $xml);
        $this->assertStringContainsString('https://example.org/cel-sitemap-category-3.xml', $xml);
        $this->assertStringNotContainsString('https://example.org/cel-sitemap-category.xml', $xml);
    }

    public function test_pregenerate_sitemaps_warms_every_taxonomy_page(): void
    {
        $this->seedTaxonomy('category', 5001);

        $this->router->pregenerateSitemaps();

        $page1 = get_transient('cel_sm_v1_category_p1');
        $page2 = get_transient('cel_sm_v1_category_p2');
        $page3 = get_transient('cel_sm_v1_category_p3');

        $this->assertIsString($page1);
        $this->assertIsString($page2);
        $this->assertIsString($page3);

        $this->assertStringContainsString('https://example.org/term-1/', $page1);
        $this->assertStringContainsString('https://example.org/term-2501/', $page2);
        $this->assertStringContainsString('https://example.org/term-5001/', $page3);
    }

    public function test_build_index_omits_filtered_empty_taxonomy_pages(): void
    {
        $this->seedTaxonomy('category', 2501);
        $GLOBALS['cel_test_term_meta'][2501]['_cel_noindex'] = '1';

        $xml = $this->invokePrivateMethod($this->router, 'buildIndex');

        $this->assertStringContainsString('https://example.org/cel-sitemap-category.xml', $xml);
        $this->assertStringNotContainsString('https://example.org/cel-sitemap-category-2.xml', $xml);
    }

    public function test_pregenerate_sitemaps_skips_filtered_empty_taxonomy_pages(): void
    {
        $this->seedTaxonomy('category', 2501);
        $GLOBALS['cel_test_term_meta'][2501]['_cel_noindex'] = '1';

        $this->router->pregenerateSitemaps();

        $this->assertIsString(get_transient('cel_sm_v1_category_p1'));
        $this->assertFalse(get_transient('cel_sm_v1_category_p2'));
    }

    public function test_get_news_urls_falls_back_to_latest_post_when_news_window_is_empty(): void
    {
        $oldDate = gmdate('Y-m-d H:i:s', time() - (72 * HOUR_IN_SECONDS));

        $GLOBALS['cel_test_posts'][101] = (object) [
            'ID'             => 101,
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'post_date_gmt'  => $oldDate,
            'post_title'     => 'Old News',
            'permalink'      => 'https://example.org/old-news/',
        ];

        $urls = $this->invokePrivateMethod($this->router, 'getNewsUrls');

        $this->assertCount(1, $urls);
        $this->assertSame('https://example.org/old-news/', $urls[0]['loc']);
        $this->assertSame('Old News', $urls[0]['title']);
    }

    public function test_get_news_urls_returns_empty_when_no_published_posts_exist(): void
    {
        $urls = $this->invokePrivateMethod($this->router, 'getNewsUrls');

        $this->assertSame([], $urls);
    }

    public function test_resolve_sitemap_response_returns_404_for_unknown_types(): void
    {
        $response = $this->invokePrivateMethod($this->router, 'resolveSitemapResponse', ['unknown-type', 1]);

        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('<urlset', $response['xml']);
    }

    public function test_resolve_sitemap_response_returns_404_for_out_of_range_taxonomy_pages(): void
    {
        $this->seedTaxonomy('category', 1);

        $response = $this->invokePrivateMethod($this->router, 'resolveSitemapResponse', ['category', 2]);

        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('<urlset', $response['xml']);
    }

    private function seedTaxonomy(string $taxonomy, int $count): void
    {
        $GLOBALS['cel_test_term_counts'][$taxonomy] = $count;
        $GLOBALS['cel_test_terms'][$taxonomy] = [];

        for ($i = 1; $i <= $count; $i++) {
            $GLOBALS['cel_test_terms'][$taxonomy][] = (object) [
                'term_id' => $i,
                'name'    => "Term {$i}",
            ];
        }
    }
}
