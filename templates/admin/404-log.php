<?php
/**
 * 404 Log admin page.
 *
 * @var array<object> $entries
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
    <h1><?php esc_html_e('404 Log', 'celestial-sitemap'); ?></h1>

    <div id="cel-404-notice" class="notice" style="display:none;"></div>

    <div class="cel-card">
        <h2><?php printf(esc_html__('Logged 404 Errors (%d)', 'celestial-sitemap'), $total); ?></h2>
        <p>
            <button type="button" id="cel-clear-404" class="button"><?php esc_html_e('Clear All', 'celestial-sitemap'); ?></button>
        </p>

        <?php if (empty($entries)) : ?>
            <p><?php esc_html_e('No 404 errors recorded.', 'celestial-sitemap'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('URL', 'celestial-sitemap'); ?></th>
                        <th><?php esc_html_e('Hits', 'celestial-sitemap'); ?></th>
                        <th><?php esc_html_e('Referrer', 'celestial-sitemap'); ?></th>
                        <th><?php esc_html_e('Last Seen', 'celestial-sitemap'); ?></th>
                        <th><?php esc_html_e('Action', 'celestial-sitemap'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $e) : ?>
                    <tr data-id="<?php echo esc_attr((string) $e->id); ?>">
                        <td><?php echo esc_html($e->url); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $e->hit_count)); ?></td>
                        <td><?php echo $e->referrer !== '' ? '<a href="' . esc_url($e->referrer) . '" target="_blank">' . esc_html($e->referrer) . '</a>' : '—'; ?></td>
                        <td><?php echo esc_html($e->last_seen); ?></td>
                        <td><button type="button" class="button cel-delete-404"><?php esc_html_e('Delete', 'celestial-sitemap'); ?></button></td>
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
