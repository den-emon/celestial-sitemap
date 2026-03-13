<?php

declare(strict_types=1);

namespace CelestialSitemap\Admin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Term meta SEO fields for all public taxonomies.
 *
 * Renders fields on both the "add new term" and "edit term" screens.
 * Fields: _cel_title, _cel_description, _cel_canonical, _cel_noindex.
 *
 * Uses {$taxonomy}_add_form_fields, {$taxonomy}_edit_form_fields,
 * created_{$taxonomy}, and edited_{$taxonomy} hooks.
 */
final class TaxonomyFields
{
    private const NONCE_ACTION = 'cel_save_term_meta';
    private const NONCE_FIELD  = 'cel_term_meta_nonce';

    public function register(): void
    {
        add_action('admin_init', [$this, 'registerTaxonomyHooks']);
    }

    public function registerTaxonomyHooks(): void
    {
        $taxonomies = get_taxonomies(['public' => true], 'names');
        foreach ($taxonomies as $taxonomy) {
            add_action("{$taxonomy}_add_form_fields", [$this, 'renderAddFields']);
            add_action("{$taxonomy}_edit_form_fields", [$this, 'renderEditFields'], 10, 2);
            add_action("created_{$taxonomy}", [$this, 'saveFields']);
            add_action("edited_{$taxonomy}", [$this, 'saveFields']);
        }
    }

    /**
     * Render fields on the "Add New Term" screen.
     *
     * @param string $taxonomy Taxonomy slug.
     */
    public function renderAddFields(string $taxonomy): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
        <div class="form-field">
            <label for="cel_title"><?php esc_html_e('SEO Title', 'celestial-sitemap'); ?></label>
            <input type="text" id="cel_title" name="cel_title" value="" maxlength="70" />
            <p><?php esc_html_e('Recommended: 30-60 characters. Leave empty for auto-generated title.', 'celestial-sitemap'); ?></p>
        </div>
        <div class="form-field">
            <label for="cel_description"><?php esc_html_e('Meta Description', 'celestial-sitemap'); ?></label>
            <textarea id="cel_description" name="cel_description" rows="3" maxlength="160"></textarea>
            <p><?php esc_html_e('Recommended: 120-155 characters. Leave empty for auto-generated description.', 'celestial-sitemap'); ?></p>
        </div>
        <div class="form-field">
            <label for="cel_canonical"><?php esc_html_e('Canonical URL', 'celestial-sitemap'); ?></label>
            <input type="url" id="cel_canonical" name="cel_canonical" value="" />
            <p><?php esc_html_e('Leave empty for default.', 'celestial-sitemap'); ?></p>
        </div>
        <div class="form-field">
            <label>
                <input type="checkbox" name="cel_noindex" value="1" />
                <?php esc_html_e('noindex this term', 'celestial-sitemap'); ?>
            </label>
            <p><?php esc_html_e('Tells search engines not to index this term archive.', 'celestial-sitemap'); ?></p>
        </div>
        <?php
    }

    /**
     * Render fields on the "Edit Term" screen (table layout).
     *
     * @param \WP_Term $term     Term object.
     * @param string   $taxonomy Taxonomy slug.
     */
    public function renderEditFields(\WP_Term $term, string $taxonomy): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $title       = (string) get_term_meta($term->term_id, '_cel_title', true);
        $description = (string) get_term_meta($term->term_id, '_cel_description', true);
        $canonical   = (string) get_term_meta($term->term_id, '_cel_canonical', true);
        $noindex     = (string) get_term_meta($term->term_id, '_cel_noindex', true);
        ?>
        <tr class="form-field">
            <th scope="row"><label for="cel_title"><?php esc_html_e('SEO Title', 'celestial-sitemap'); ?></label></th>
            <td>
                <input type="text" id="cel_title" name="cel_title" value="<?php echo esc_attr($title); ?>" class="large-text" maxlength="70" />
                <p class="description"><?php esc_html_e('Recommended: 30-60 characters. Leave empty for auto-generated title.', 'celestial-sitemap'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="cel_description"><?php esc_html_e('Meta Description', 'celestial-sitemap'); ?></label></th>
            <td>
                <textarea id="cel_description" name="cel_description" class="large-text" rows="3" maxlength="160"><?php echo esc_textarea($description); ?></textarea>
                <p class="description"><?php esc_html_e('Recommended: 120-155 characters. Leave empty for auto-generated description.', 'celestial-sitemap'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="cel_canonical"><?php esc_html_e('Canonical URL', 'celestial-sitemap'); ?></label></th>
            <td>
                <input type="url" id="cel_canonical" name="cel_canonical" value="<?php echo esc_attr($canonical); ?>" class="large-text" />
                <p class="description"><?php esc_html_e('Leave empty for default.', 'celestial-sitemap'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><?php esc_html_e('Noindex', 'celestial-sitemap'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="cel_noindex" value="1" <?php checked($noindex, '1'); ?> />
                    <?php esc_html_e('noindex this term', 'celestial-sitemap'); ?>
                </label>
                <p class="description"><?php esc_html_e('Tells search engines not to index this term archive.', 'celestial-sitemap'); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Save term meta fields.
     *
     * @param int $termId Term ID.
     */
    public function saveFields(int $termId): void
    {
        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]))
            : '';

        if ($nonce === '' || ! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        if (! current_user_can('manage_categories')) {
            return;
        }

        $textFields = ['cel_title', 'cel_description'];
        foreach ($textFields as $field) {
            $value = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
            if ($value !== '') {
                update_term_meta($termId, "_{$field}", $value);
            } else {
                delete_term_meta($termId, "_{$field}");
            }
        }

        // Canonical URL — use esc_url_raw for URL sanitization
        $canonical = isset($_POST['cel_canonical']) ? esc_url_raw(wp_unslash($_POST['cel_canonical'])) : '';
        if ($canonical !== '') {
            update_term_meta($termId, '_cel_canonical', $canonical);
        } else {
            delete_term_meta($termId, '_cel_canonical');
        }

        if (! empty($_POST['cel_noindex'])) {
            update_term_meta($termId, '_cel_noindex', '1');
        } else {
            delete_term_meta($termId, '_cel_noindex');
        }
    }
}
