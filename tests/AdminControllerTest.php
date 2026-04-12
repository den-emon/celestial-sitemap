<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use CelestialSitemap\Admin\AdminController;
use CelestialSitemap\Core\Options;
use CelestialSitemap\Redirect\RedirectManager;

final class AdminControllerTest extends CelestialSitemap_TestCase
{
    public function test_enqueue_assets_loads_scripts_on_submenu_pages(): void
    {
        $controller = new AdminController(new Options());

        $controller->enqueueAssets('cel-dashboard_page_cel-redirects');

        $this->assertArrayHasKey('cel-admin', $GLOBALS['cel_wp_enqueued_styles']);
        $this->assertArrayHasKey('cel-admin', $GLOBALS['cel_wp_enqueued_scripts']);
        $this->assertArrayHasKey('cel-admin', $GLOBALS['cel_wp_localized_scripts']);

        $this->assertSame(
            'http://example.org/wp-content/plugins/celestial-sitemap/assets/js/admin.js',
            $GLOBALS['cel_wp_enqueued_scripts']['cel-admin']['src']
        );
        $this->assertSame(
            'https://example.org/wp-admin/admin-ajax.php',
            $GLOBALS['cel_wp_localized_scripts']['cel-admin']['data']['ajaxUrl']
        );
    }

    public function test_ajax_add_redirect_respects_the_selected_match_type(): void
    {
        $controller = new AdminController(new Options());

        $_POST = [
            '_ajax_nonce' => 'nonce-cel_admin_nonce',
            'source'      => '/docs',
            'target'      => 'https://example.org/manual/',
            'status_code' => '301',
            'match_type'  => 'prefix',
        ];

        try {
            $controller->ajaxAddRedirect();
            $this->fail('Expected AJAX response to terminate execution.');
        } catch (CelestialSitemapAjaxExit) {
        }

        $response = $GLOBALS['cel_last_json_response'];
        $this->assertTrue($response['success']);

        $rows = RedirectManager::getAll();
        $this->assertCount(1, $rows);
        $this->assertSame('prefix', $rows[0]->match_type);
    }
}
