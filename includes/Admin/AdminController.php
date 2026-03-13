<?php

declare(strict_types=1);

namespace CelestialSitemap\Admin;

use CelestialSitemap\Core\NotFoundLogger;
use CelestialSitemap\Core\Options;
use CelestialSitemap\Redirect\RedirectManager;
use CelestialSitemap\Sitemap\SitemapRouter;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin UI: menu pages, settings, meta boxes, AJAX.
 */
final class AdminController
{
    private Options $opts;

    public function __construct(Options $opts)
    {
        $this->opts = $opts;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('add_meta_boxes', [$this, 'addMetaBoxes']);
        add_action('save_post', [$this, 'saveMetaBox'], 10, 2);

        // AJAX
        add_action('wp_ajax_cel_save_settings', [$this, 'ajaxSaveSettings']);
        add_action('wp_ajax_cel_add_redirect', [$this, 'ajaxAddRedirect']);
        add_action('wp_ajax_cel_delete_redirect', [$this, 'ajaxDeleteRedirect']);
        add_action('wp_ajax_cel_delete_404', [$this, 'ajaxDelete404']);
        add_action('wp_ajax_cel_clear_404', [$this, 'ajaxClear404']);
        add_action('wp_ajax_cel_flush_sitemap', [$this, 'ajaxFlushSitemap']);
    }

    // ── Menu ─────────────────────────────────────────────────────────

    public function registerMenu(): void
    {
        add_menu_page(
            __('Celestial Sitemap', 'celestial-sitemap'),
            __('Celestial Sitemap', 'celestial-sitemap'),
            'manage_options',
            'cel-dashboard',
            [$this, 'renderDashboard'],
            'dashicons-chart-area',
            80
        );

        add_submenu_page('cel-dashboard', __('General Settings', 'celestial-sitemap'), __('Settings', 'celestial-sitemap'), 'manage_options', 'cel-dashboard', [$this, 'renderDashboard']);
        add_submenu_page('cel-dashboard', __('Redirects', 'celestial-sitemap'), __('Redirects', 'celestial-sitemap'), 'manage_options', 'cel-redirects', [$this, 'renderRedirects']);
        add_submenu_page('cel-dashboard', __('404 Log', 'celestial-sitemap'), __('404 Log', 'celestial-sitemap'), 'manage_options', 'cel-404-log', [$this, 'render404Log']);
    }

    // ── Assets ───────────────────────────────────────────────────────

    public function enqueueAssets(string $hook): void
    {
        $adminPages = ['toplevel_page_cel-dashboard', 'celestial-sitemap_page_cel-redirects', 'celestial-sitemap_page_cel-404-log'];

        $needsCss = in_array($hook, $adminPages, true)
            || $hook === 'post.php'
            || $hook === 'post-new.php'
            || $hook === 'edit-tags.php'
            || $hook === 'term.php';

        if ($needsCss) {
            wp_enqueue_style('cel-admin', CEL_URL . 'assets/css/admin.css', [], CEL_VERSION);
        }

        if (in_array($hook, $adminPages, true)) {
            wp_enqueue_script('cel-admin', CEL_URL . 'assets/js/admin.js', ['jquery'], CEL_VERSION, true);
            wp_localize_script('cel-admin', 'celAdmin', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('cel_admin_nonce'),
            ]);
        }
    }

    // ── Page renderers ───────────────────────────────────────────────

    public function renderDashboard(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'celestial-sitemap'));
        }
        $opts = $this->opts;
        require CEL_DIR . 'templates/admin/dashboard.php';
    }

    private const PER_PAGE = 50;

    public function renderRedirects(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'celestial-sitemap'));
        }
        $currentPage = max(1, absint($_GET['paged'] ?? 1));
        $perPage     = self::PER_PAGE;
        $total       = RedirectManager::countAll();
        $totalPages  = max(1, (int) ceil($total / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset      = ($currentPage - 1) * $perPage;
        $redirects   = RedirectManager::getAll($perPage, $offset);
        $pageUrl     = admin_url('admin.php?page=cel-redirects');
        require CEL_DIR . 'templates/admin/redirects.php';
    }

    public function render404Log(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'celestial-sitemap'));
        }
        $currentPage = max(1, absint($_GET['paged'] ?? 1));
        $perPage     = self::PER_PAGE;
        $total       = NotFoundLogger::countAll();
        $totalPages  = max(1, (int) ceil($total / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset      = ($currentPage - 1) * $perPage;
        $entries     = NotFoundLogger::getAll($perPage, $offset);
        $pageUrl     = admin_url('admin.php?page=cel-404-log');
        require CEL_DIR . 'templates/admin/404-log.php';
    }

    // ── Meta box ─────────────────────────────────────────────────────

    public function addMetaBoxes(): void
    {
        $postTypes = get_post_types(['public' => true], 'names');
        foreach ($postTypes as $pt) {
            add_meta_box(
                'cel-seo-meta',
                __('SEO Settings', 'celestial-sitemap'),
                [$this, 'renderMetaBox'],
                $pt,
                'normal',
                'high'
            );
        }
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        wp_nonce_field('cel_save_meta', 'cel_meta_nonce');

        $title       = (string) get_post_meta($post->ID, '_cel_title', true);
        $description = (string) get_post_meta($post->ID, '_cel_description', true);
        $canonical   = (string) get_post_meta($post->ID, '_cel_canonical', true);
        $noindex     = (string) get_post_meta($post->ID, '_cel_noindex', true);
        $ogImage     = (string) get_post_meta($post->ID, '_cel_og_image', true);
        ?>
        <div class="cel-metabox">
            <p>
                <label for="cel_title"><strong><?php esc_html_e('SEO Title', 'celestial-sitemap'); ?></strong></label><br>
                <input type="text" id="cel_title" name="cel_title" value="<?php echo esc_attr($title); ?>" class="widefat" maxlength="70" placeholder="<?php esc_attr_e('Leave empty for auto-generated title', 'celestial-sitemap'); ?>" />
                <span class="description"><?php esc_html_e('Recommended: 30-60 characters', 'celestial-sitemap'); ?></span>
            </p>
            <p>
                <label for="cel_description"><strong><?php esc_html_e('Meta Description', 'celestial-sitemap'); ?></strong></label><br>
                <textarea id="cel_description" name="cel_description" class="widefat" rows="3" maxlength="160" placeholder="<?php esc_attr_e('Leave empty for auto-generated description', 'celestial-sitemap'); ?>"><?php echo esc_textarea($description); ?></textarea>
                <span class="description"><?php esc_html_e('Recommended: 120-155 characters', 'celestial-sitemap'); ?></span>
            </p>
            <p>
                <label for="cel_canonical"><strong><?php esc_html_e('Canonical URL', 'celestial-sitemap'); ?></strong></label><br>
                <input type="url" id="cel_canonical" name="cel_canonical" value="<?php echo esc_attr($canonical); ?>" class="widefat" placeholder="<?php esc_attr_e('Leave empty for default', 'celestial-sitemap'); ?>" />
            </p>
            <p>
                <label for="cel_og_image"><strong><?php esc_html_e('OG Image URL', 'celestial-sitemap'); ?></strong></label><br>
                <input type="url" id="cel_og_image" name="cel_og_image" value="<?php echo esc_attr($ogImage); ?>" class="widefat" placeholder="<?php esc_attr_e('Leave empty to use featured image', 'celestial-sitemap'); ?>" />
            </p>
            <p>
                <label>
                    <input type="checkbox" name="cel_noindex" value="1" <?php checked($noindex, '1'); ?> />
                    <strong><?php esc_html_e('noindex this page', 'celestial-sitemap'); ?></strong>
                </label>
                <span class="description"><?php esc_html_e('Tells search engines not to index this page.', 'celestial-sitemap'); ?></span>
            </p>
        </div>
        <?php
    }

    public function saveMetaBox(int $postId, \WP_Post $post): void
    {
        // FIX #11: Sanitize and unslash nonce before verification.
        $nonce = isset($_POST['cel_meta_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['cel_meta_nonce']))
            : '';
        if ($nonce === '' || ! wp_verify_nonce($nonce, 'cel_save_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (! current_user_can('edit_post', $postId)) {
            return;
        }

        // Text fields
        $textFields = ['cel_title', 'cel_description'];
        foreach ($textFields as $field) {
            $value = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
            if ($value !== '') {
                update_post_meta($postId, "_{$field}", $value);
            } else {
                delete_post_meta($postId, "_{$field}");
            }
        }

        // URL fields — use esc_url_raw to only accept valid http/https URLs
        $urlFields = ['cel_canonical', 'cel_og_image'];
        foreach ($urlFields as $field) {
            $value = isset($_POST[$field]) ? esc_url_raw(wp_unslash($_POST[$field])) : '';
            if ($value !== '') {
                update_post_meta($postId, "_{$field}", $value);
            } else {
                delete_post_meta($postId, "_{$field}");
            }
        }

        // noindex checkbox
        if (! empty($_POST['cel_noindex'])) {
            update_post_meta($postId, '_cel_noindex', '1');
        } else {
            delete_post_meta($postId, '_cel_noindex');
        }
    }

    // ── AJAX: Save Settings ──────────────────────────────────────────

    public function ajaxSaveSettings(): void
    {
        check_ajax_referer('cel_admin_nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        // FIX #3: Text/URL fields – save when present.
        $textFields = [
            'cel_title_separator'    => 'sanitize_text_field',
            'cel_homepage_title'     => 'sanitize_text_field',
            'cel_homepage_desc'      => 'sanitize_textarea_field',
            'cel_schema_org_type'    => 'sanitize_text_field',
            'cel_schema_org_name'    => 'sanitize_text_field',
            'cel_schema_org_logo'    => 'esc_url_raw',
            'cel_ogp_default_image'  => 'esc_url_raw',
            'cel_fb_app_id'          => 'sanitize_text_field',
            'cel_twitter_site'       => 'sanitize_text_field',
        ];

        foreach ($textFields as $key => $sanitizer) {
            if (isset($_POST[$key])) {
                $value = call_user_func($sanitizer, wp_unslash($_POST[$key]));
                update_option($key, $value);
            }
        }

        // FIX #3: Checkbox fields – absent = unchecked = 0.
        $checkboxFields = [
            'cel_noindex_archives',
            'cel_noindex_tags',
            'cel_noindex_author',
            'cel_noindex_date',
            'cel_noindex_search',
            'cel_schema_org',
            'cel_ogp_enabled',
            'cel_breadcrumbs',
        ];

        foreach ($checkboxFields as $key) {
            $value = isset($_POST[$key]) ? 1 : 0;
            update_option($key, $value);
        }

        // Post types (array)
        if (isset($_POST['cel_sitemap_post_types'])) {
            $pts = array_map('sanitize_key', (array) $_POST['cel_sitemap_post_types']);
            update_option('cel_sitemap_post_types', $pts);
        } else {
            update_option('cel_sitemap_post_types', []);
        }

        // Taxonomies (array)
        if (isset($_POST['cel_sitemap_taxonomies'])) {
            $taxs = array_map('sanitize_key', (array) $_POST['cel_sitemap_taxonomies']);
            update_option('cel_sitemap_taxonomies', $taxs);
        } else {
            update_option('cel_sitemap_taxonomies', []);
        }

        // GSC credentials — encrypt before storing
        $gscSecretFields = ['cel_gsc_client_secret', 'cel_gsc_access_token'];
        foreach ($gscSecretFields as $key) {
            if (isset($_POST[$key])) {
                $raw = sanitize_text_field(wp_unslash($_POST[$key]));
                $encrypted = Options::encrypt($raw);
                update_option($key, $encrypted);
            }
        }

        // GSC client ID (not secret, no encryption needed)
        if (isset($_POST['cel_gsc_client_id'])) {
            $value = sanitize_text_field(wp_unslash($_POST['cel_gsc_client_id']));
            update_option('cel_gsc_client_id', $value);
        }

        // Invalidate the Options in-memory cache so subsequent reads see new values
        $this->opts->invalidateCache();

        // Invalidate sitemap caches so new settings (post types, taxonomies, etc.) take effect
        (new SitemapRouter($this->opts))->invalidateAll();

        wp_send_json_success(['message' => __('Settings saved.', 'celestial-sitemap')]);
    }

    // ── AJAX: Redirects ──────────────────────────────────────────────

    public function ajaxAddRedirect(): void
    {
        check_ajax_referer('cel_admin_nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $source = sanitize_text_field(wp_unslash($_POST['source'] ?? ''));
        $target = esc_url_raw(wp_unslash($_POST['target'] ?? ''));
        $code   = absint($_POST['status_code'] ?? 301);

        if ($source === '' || $target === '') {
            wp_send_json_error(['message' => __('Source and target are required.', 'celestial-sitemap')]);
        }
        if (! in_array($code, [301, 302, 307, 308], true)) {
            $code = 301;
        }

        try {
            $ok = RedirectManager::add($source, $target, $code);
        } catch (\InvalidArgumentException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
            return;
        }

        if ($ok) {
            wp_send_json_success(['message' => __('Redirect added.', 'celestial-sitemap')]);
        } else {
            wp_send_json_error(['message' => __('Failed to add redirect.', 'celestial-sitemap')]);
        }
    }

    public function ajaxDeleteRedirect(): void
    {
        check_ajax_referer('cel_admin_nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        $id = absint($_POST['id'] ?? 0);
        RedirectManager::delete($id);
        wp_send_json_success();
    }

    // ── AJAX: 404 ────────────────────────────────────────────────────

    public function ajaxDelete404(): void
    {
        check_ajax_referer('cel_admin_nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        $id = absint($_POST['id'] ?? 0);
        NotFoundLogger::deleteEntry($id);
        wp_send_json_success();
    }

    public function ajaxClear404(): void
    {
        check_ajax_referer('cel_admin_nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        NotFoundLogger::clearAll();
        wp_send_json_success(['message' => __('404 log cleared.', 'celestial-sitemap')]);
    }

    // ── AJAX: Sitemap ────────────────────────────────────────────────

    public function ajaxFlushSitemap(): void
    {
        check_ajax_referer('cel_admin_nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        (new SitemapRouter($this->opts))->invalidateAll();
        wp_send_json_success(['message' => __('Sitemap cache cleared.', 'celestial-sitemap')]);
    }
}
