<?php
/**
 * Admin dashboard template.
 *
 * @var \CelestialSitemap\Core\Options $opts
 */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap cel-wrap">
    <h1><?php esc_html_e('Celestial Sitemap — Settings', 'celestial-sitemap'); ?></h1>

    <div id="cel-settings-notice" class="notice" style="display:none;"></div>

    <form id="cel-settings-form">

        <!-- Title / Meta -->
        <div class="cel-card">
            <h2><?php esc_html_e('Title & Meta', 'celestial-sitemap'); ?></h2>
            <p class="description"><?php esc_html_e('Search engine results and browser tabs use these values. Empty fields fall back to WordPress defaults.', 'celestial-sitemap'); ?></p>
            <table class="form-table">
                <tr>
                    <th><label for="cel_title_separator"><?php esc_html_e('Title Separator', 'celestial-sitemap'); ?></label></th>
                    <td>
                        <input type="text" id="cel_title_separator" name="cel_title_separator" value="<?php echo esc_attr($opts->titleSeparator()); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Character inserted between the page title and site name (e.g. "Page Title | Site Name").', 'celestial-sitemap'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cel_homepage_title"><?php esc_html_e('Homepage Title', 'celestial-sitemap'); ?></label></th>
                    <td>
                        <input type="text" id="cel_homepage_title" name="cel_homepage_title" value="<?php echo esc_attr($opts->homepageTitle()); ?>" class="large-text" />
                        <p class="description"><?php esc_html_e('Custom title tag for the front page. Leave empty to use the default WordPress title.', 'celestial-sitemap'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cel_homepage_desc"><?php esc_html_e('Homepage Description', 'celestial-sitemap'); ?></label></th>
                    <td>
                        <textarea id="cel_homepage_desc" name="cel_homepage_desc" class="large-text" rows="3"><?php echo esc_textarea($opts->homepageDesc()); ?></textarea>
                        <p class="description"><?php esc_html_e('Meta description for the front page. Displayed as a snippet in search results. Recommended: 120-155 characters.', 'celestial-sitemap'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Noindex -->
        <div class="cel-card">
            <h2><?php esc_html_e('Noindex Rules', 'celestial-sitemap'); ?></h2>
            <p class="description"><?php esc_html_e('Checked pages will have a "noindex" meta tag, telling search engines not to include them in results. Useful for preventing low-value pages from being indexed.', 'celestial-sitemap'); ?></p>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Noindex Archives', 'celestial-sitemap'); ?></th>
                    <td><label><input type="checkbox" name="cel_noindex_archives" value="1" <?php checked($opts->noindexArchives()); ?> /> <?php esc_html_e('Category & taxonomy archives', 'celestial-sitemap'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Noindex Tags', 'celestial-sitemap'); ?></th>
                    <td><label><input type="checkbox" name="cel_noindex_tags" value="1" <?php checked($opts->noindexTags()); ?> /> <?php esc_html_e('Tag archives', 'celestial-sitemap'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Noindex Author', 'celestial-sitemap'); ?></th>
                    <td><label><input type="checkbox" name="cel_noindex_author" value="1" <?php checked($opts->noindexAuthor()); ?> /> <?php esc_html_e('Author archives', 'celestial-sitemap'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Noindex Date', 'celestial-sitemap'); ?></th>
                    <td><label><input type="checkbox" name="cel_noindex_date" value="1" <?php checked($opts->noindexDate()); ?> /> <?php esc_html_e('Date archives', 'celestial-sitemap'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Noindex Search', 'celestial-sitemap'); ?></th>
                    <td><label><input type="checkbox" name="cel_noindex_search" value="1" <?php checked($opts->noindexSearch()); ?> /> <?php esc_html_e('Search results', 'celestial-sitemap'); ?></label></td>
                </tr>
            </table>
        </div>

        <!-- Sitemap -->
        <div class="cel-card">
            <h2><?php esc_html_e('XML Sitemap', 'celestial-sitemap'); ?></h2>
            <p class="description"><?php esc_html_e('Select which content types to include in the XML sitemap submitted to search engines. News, image, and video sitemaps are generated automatically when qualifying content exists.', 'celestial-sitemap'); ?></p>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Post Types', 'celestial-sitemap'); ?></th>
                    <td>
                        <?php
                        $selectedPts = $opts->sitemapPostTypes();
                        foreach (get_post_types(['public' => true], 'objects') as $pt) :
                            if ($pt->name === 'attachment') {
                                continue;
                            }
                            ?>
                            <label style="display:block;margin-bottom:4px;">
                                <input type="checkbox" name="cel_sitemap_post_types[]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $selectedPts, true)); ?> />
                                <?php echo esc_html($pt->labels->name); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Taxonomies', 'celestial-sitemap'); ?></th>
                    <td>
                        <?php
                        $selectedTax = $opts->sitemapTaxonomies();
                        foreach (get_taxonomies(['public' => true], 'objects') as $tax) :
                            ?>
                            <label style="display:block;margin-bottom:4px;">
                                <input type="checkbox" name="cel_sitemap_taxonomies[]" value="<?php echo esc_attr($tax->name); ?>" <?php checked(in_array($tax->name, $selectedTax, true)); ?> />
                                <?php echo esc_html($tax->labels->name); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
            </table>
            <p>
                <a href="<?php echo esc_url(home_url('/cel-sitemap.xml')); ?>" target="_blank" class="button"><?php esc_html_e('View Sitemap', 'celestial-sitemap'); ?></a>
                <button type="button" id="cel-flush-sitemap" class="button"><?php esc_html_e('Flush Sitemap Cache', 'celestial-sitemap'); ?></button>
            </p>
        </div>

        <!-- Schema -->
        <div class="cel-card">
            <h2><?php esc_html_e('Schema / Structured Data', 'celestial-sitemap'); ?></h2>
            <p class="description"><?php esc_html_e('Outputs JSON-LD structured data that helps search engines understand your site. Enables rich results (rich snippets) in Google Search.', 'celestial-sitemap'); ?></p>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Enable Schema', 'celestial-sitemap'); ?></th>
                    <td><label><input type="checkbox" name="cel_schema_org" value="1" <?php checked($opts->schemaEnabled()); ?> /> <?php esc_html_e('Output JSON-LD structured data', 'celestial-sitemap'); ?></label></td>
                </tr>
                <tr>
                    <th><label for="cel_schema_org_type"><?php esc_html_e('Entity Type', 'celestial-sitemap'); ?></label></th>
                    <td>
                        <select id="cel_schema_org_type" name="cel_schema_org_type">
                            <option value="Organization" <?php selected($opts->schemaOrgType(), 'Organization'); ?>><?php esc_html_e('Organization (company / group)', 'celestial-sitemap'); ?></option>
                            <option value="Person" <?php selected($opts->schemaOrgType(), 'Person'); ?>><?php esc_html_e('Person (individual)', 'celestial-sitemap'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Choose "Organization" for a company or brand site, "Person" for a personal blog.', 'celestial-sitemap'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cel_schema_org_name"><?php esc_html_e('Name', 'celestial-sitemap'); ?></label></th>
                    <td>
                        <input type="text" id="cel_schema_org_name" name="cel_schema_org_name" value="<?php echo esc_attr($opts->schemaOrgName()); ?>" class="regular-text" placeholder="<?php esc_attr_e('Defaults to Site Title', 'celestial-sitemap'); ?>" />
                        <p class="description"><?php esc_html_e('Name displayed in structured data. Leave empty to use the WordPress site title.', 'celestial-sitemap'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cel_schema_org_logo"><?php esc_html_e('Logo URL', 'celestial-sitemap'); ?></label></th>
                    <td>
                        <input type="url" id="cel_schema_org_logo" name="cel_schema_org_logo" value="<?php echo esc_attr($opts->schemaOrgLogo()); ?>" class="large-text" />
                        <p class="description"><?php esc_html_e('Organization logo image URL. Leave empty to use the site icon. Not used for "Person" type.', 'celestial-sitemap'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Breadcrumbs', 'celestial-sitemap'); ?></th>
                    <td>
                        <label><input type="checkbox" name="cel_breadcrumbs" value="1" <?php checked($opts->breadcrumbsEnabled()); ?> /> <?php esc_html_e('Output BreadcrumbList schema', 'celestial-sitemap'); ?></label>
                        <p class="description"><?php esc_html_e('Enables breadcrumb navigation display in search results.', 'celestial-sitemap'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- OGP / Social -->
        <div class="cel-card">
            <h2><?php esc_html_e('Open Graph / Social', 'celestial-sitemap'); ?></h2>
            <p class="description"><?php esc_html_e('Controls how your pages appear when shared on Facebook, Twitter/X, LINE, and other social platforms.', 'celestial-sitemap'); ?></p>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Enable OGP', 'celestial-sitemap'); ?></th>
                    <td><label><input type="checkbox" name="cel_ogp_enabled" value="1" <?php checked($opts->ogpEnabled()); ?> /> <?php esc_html_e('Output Open Graph meta tags', 'celestial-sitemap'); ?></label></td>
                </tr>
                <tr>
                    <th><label for="cel_ogp_default_image"><?php esc_html_e('Default OG Image', 'celestial-sitemap'); ?></label></th>
                    <td>
                        <input type="url" id="cel_ogp_default_image" name="cel_ogp_default_image" value="<?php echo esc_attr($opts->ogpDefaultImage()); ?>" class="large-text" />
                        <p class="description"><?php esc_html_e('Fallback image used when a post has no featured image. Recommended size: 1200x630px.', 'celestial-sitemap'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cel_fb_app_id"><?php esc_html_e('Facebook App ID', 'celestial-sitemap'); ?></label></th>
                    <td>
                        <input type="text" id="cel_fb_app_id" name="cel_fb_app_id" value="<?php echo esc_attr($opts->fbAppId()); ?>" class="regular-text" placeholder="<?php esc_attr_e('Optional', 'celestial-sitemap'); ?>" />
                        <p class="description"><?php esc_html_e('Optional. Outputs fb:app_id meta tag for Facebook Insights integration.', 'celestial-sitemap'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cel_twitter_site"><?php esc_html_e('Twitter/X @handle', 'celestial-sitemap'); ?></label></th>
                    <td>
                        <input type="text" id="cel_twitter_site" name="cel_twitter_site" value="<?php echo esc_attr($opts->twitterSite()); ?>" class="regular-text" placeholder="@yourhandle" />
                        <p class="description"><?php esc_html_e('Your Twitter/X username. Used in twitter:site meta tag for Twitter Cards.', 'celestial-sitemap'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <p class="submit">
            <button type="submit" class="button button-primary" id="cel-save-settings"><?php esc_html_e('Save Settings', 'celestial-sitemap'); ?></button>
        </p>
    </form>
</div>
