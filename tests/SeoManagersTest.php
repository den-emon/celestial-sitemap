<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use CelestialSitemap\Core\Options;
use CelestialSitemap\Schema\SchemaManager;
use CelestialSitemap\SEO\CanonicalManager;
use CelestialSitemap\SEO\HeadManager;

final class SeoManagersTest extends CelestialSitemap_TestCase
{
    public function test_resolve_uses_the_posts_page_permalink_for_static_blog_indexes(): void
    {
        update_option('page_for_posts', 42);
        $GLOBALS['cel_test_conditionals']['is_home'] = true;
        $GLOBALS['cel_test_posts'][42] = (object) [
            'ID'        => 42,
            'post_type' => 'page',
            'permalink' => 'https://example.org/news/',
        ];

        $manager = new CanonicalManager(new Options());

        $this->assertSame('https://example.org/news/', $manager->resolve());
    }

    public function test_build_ogp_uses_website_type_for_non_post_singular_pages(): void
    {
        $post = (object) [
            'ID'         => 77,
            'post_type'  => 'page',
            'post_status'=> 'publish',
            'post_title' => 'Landing Page',
            'permalink'  => 'https://example.org/landing/',
        ];

        $GLOBALS['cel_test_conditionals']['is_singular'] = true;
        $GLOBALS['cel_test_posts'][77] = $post;
        $GLOBALS['cel_test_current_post'] = $post;
        $GLOBALS['cel_test_current_post_id'] = 77;
        $GLOBALS['cel_test_document_title'] = 'Landing Page';
        $GLOBALS['cel_test_post_meta'][77]['_cel_canonical'] = 'https://example.org/landing/';
        $GLOBALS['cel_test_post_meta'][77]['_cel_description'] = 'Landing description';

        $options   = new Options();
        $head      = new HeadManager($options, new CanonicalManager($options), new SchemaManager($options));
        $ogp       = $this->invokePrivateMethod($head, 'buildOgp');

        $this->assertSame('website', $ogp['og:type']);
        $this->assertSame('https://example.org/landing/', $ogp['og:url']);
        $this->assertArrayNotHasKey('article:published_time', $ogp);
        $this->assertArrayNotHasKey('article:modified_time', $ogp);
    }
}
