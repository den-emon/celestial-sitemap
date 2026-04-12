<?php

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use CelestialSitemap\Admin\TaxonomyFields;

final class TaxonomyFieldsTest extends CelestialSitemap_TestCase
{
    public function test_edited_taxonomy_hook_uses_taxonomy_specific_capability(): void
    {
        $fields = new TaxonomyFields();

        $GLOBALS['cel_test_taxonomies']['genre'] = (object) [
            'name' => 'genre',
            'cap'  => (object) [
                'manage_terms' => 'manage_genres',
                'edit_terms'   => 'edit_genres',
            ],
        ];
        $GLOBALS['cel_test_user_caps'] = [
            'manage_categories' => false,
            'edit_genres'       => true,
        ];

        $_POST['cel_term_meta_nonce'] = 'nonce-cel_save_term_meta';
        $_POST['cel_title'] = 'Custom Genre Title';
        $_POST['cel_description'] = 'Custom Genre Description';
        $_POST['cel_canonical'] = 'https://example.org/genres/custom/';
        $_POST['cel_noindex'] = '1';

        $fields->registerTaxonomyHooks();

        $callback = $GLOBALS['cel_wp_actions']['edited_genre'][10][0][0] ?? null;

        $this->assertIsCallable($callback);
        $callback(55);

        // manage_categories がなくても、taxonomy 固有 capability で保存できる。
        $this->assertSame('Custom Genre Title', get_term_meta(55, '_cel_title', true));
        $this->assertSame('Custom Genre Description', get_term_meta(55, '_cel_description', true));
        $this->assertSame('https://example.org/genres/custom/', get_term_meta(55, '_cel_canonical', true));
        $this->assertSame('1', get_term_meta(55, '_cel_noindex', true));
    }
}
