<?php
/**
 * Redirects admin page.
 *
 * @var array<object> $redirects
 * @var int           $total
 * @var int           $currentPage
 * @var int           $totalPages
 * @var string        $pageUrl
 */
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap cel-wrap">
    <h1><?php esc_html_e('Redirects', 'celestial-sitemap'); ?></h1>

    <div id="cel-redirect-notice" class="notice" style="display:none;"></div>

    <div class="cel-card">
        <h2><?php esc_html_e('Add Redirect', 'celestial-sitemap'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="cel-redir-source"><?php esc_html_e('Source URL (path)', 'celestial-sitemap'); ?></label></th>
                <td><input type="text" id="cel-redir-source" class="regular-text" placeholder="/old-page/" /></td>
            </tr>
            <tr>
                <th><label for="cel-redir-target"><?php esc_html_e('Target URL', 'celestial-sitemap'); ?></label></th>
                <td><input type="url" id="cel-redir-target" class="regular-text" placeholder="https://example.com/new-page/" /></td>
            </tr>
            <tr>
                <th><label for="cel-redir-code"><?php esc_html_e('Status Code', 'celestial-sitemap'); ?></label></th>
                <td>
                    <select id="cel-redir-code">
                        <option value="301"><?php esc_html_e('301 — Permanent redirect', 'celestial-sitemap'); ?></option>
                        <option value="302"><?php esc_html_e('302 — Temporary redirect', 'celestial-sitemap'); ?></option>
                        <option value="307"><?php esc_html_e('307 — Temporary (method preserved)', 'celestial-sitemap'); ?></option>
                        <option value="308"><?php esc_html_e('308 — Permanent (method preserved)', 'celestial-sitemap'); ?></option>
                    </select>
                </td>
            </tr>
        </table>
        <p><button type="button" id="cel-add-redirect" class="button button-primary"><?php esc_html_e('Add Redirect', 'celestial-sitemap'); ?></button></p>
    </div>

    <div class="cel-card">
        <h2><?php printf(esc_html__('Existing Redirects (%d)', 'celestial-sitemap'), $total); ?></h2>
        <?php if (empty($redirects)) : ?>
            <p><?php esc_html_e('No redirects configured.', 'celestial-sitemap'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Source', 'celestial-sitemap'); ?></th>
                        <th><?php esc_html_e('Target', 'celestial-sitemap'); ?></th>
                        <th><?php esc_html_e('Code', 'celestial-sitemap'); ?></th>
                        <th><?php esc_html_e('Hits', 'celestial-sitemap'); ?></th>
                        <th><?php esc_html_e('Updated', 'celestial-sitemap'); ?></th>
                        <th><?php esc_html_e('Action', 'celestial-sitemap'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($redirects as $r) : ?>
                    <tr data-id="<?php echo esc_attr((string) $r->id); ?>">
                        <td><?php echo esc_html($r->source_url); ?></td>
                        <td><a href="<?php echo esc_url($r->target_url); ?>" target="_blank"><?php echo esc_html($r->target_url); ?></a></td>
                        <td><?php echo esc_html((string) $r->status_code); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $r->hit_count)); ?></td>
                        <td><?php echo esc_html($r->updated_at); ?></td>
                        <td><button type="button" class="button cel-delete-redirect"><?php esc_html_e('Delete', 'celestial-sitemap'); ?></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1) : ?>
            <div class="cel-pagination">
                <?php if ($currentPage > 1) : ?>
                    <a href="<?php echo esc_url(add_query_arg('paged', $currentPage - 1, $pageUrl)); ?>" class="button">&laquo; <?php esc_html_e('Previous', 'celestial-sitemap'); ?></a>
                <?php endif; ?>

                <span class="cel-pagination-info">
                    <?php
                    printf(
                        /* translators: 1: current page, 2: total pages */
                        esc_html__('Page %1$d of %2$d', 'celestial-sitemap'),
                        $currentPage,
                        $totalPages
                    );
                    ?>
                </span>

                <?php if ($currentPage < $totalPages) : ?>
                    <a href="<?php echo esc_url(add_query_arg('paged', $currentPage + 1, $pageUrl)); ?>" class="button"><?php esc_html_e('Next', 'celestial-sitemap'); ?> &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
