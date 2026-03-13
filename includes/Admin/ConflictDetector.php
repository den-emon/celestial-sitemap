<?php

declare(strict_types=1);

namespace CelestialSitemap\Admin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Detects competing SEO plugins and displays a non-intrusive admin notice.
 *
 * Checked plugins:
 *  - Yoast SEO
 *  - Rank Math
 *  - All in One SEO
 *  - SEOPress
 *
 * Detection uses function_exists(), defined(), and class_exists()
 * with autoload disabled to avoid fatal errors.
 */
final class ConflictDetector
{
    public function register(): void
    {
        add_action('admin_notices', [$this, 'maybeShowNotice']);
    }

    public function maybeShowNotice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $detected = $this->detectConflicts();
        if ($detected === []) {
            return;
        }

        $names = esc_html(implode(', ', $detected));
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            sprintf(
                /* translators: %s: comma-separated list of detected SEO plugin names */
                esc_html__(
                    'Celestial Sitemap: The following SEO plugin(s) are also active: %s. Running multiple SEO plugins simultaneously may cause duplicate meta tags, conflicting canonical URLs, and duplicate structured data. Consider deactivating one to avoid conflicts.',
                    'celestial-sitemap'
                ),
                '<strong>' . $names . '</strong>'
            )
        );
    }

    /**
     * @return string[] Names of detected competing SEO plugins.
     */
    private function detectConflicts(): array
    {
        $conflicts = [];

        // Yoast SEO
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options', false)) {
            $conflicts[] = 'Yoast SEO';
        }

        // Rank Math
        if (defined('RANK_MATH_VERSION') || class_exists('RankMath', false)) {
            $conflicts[] = 'Rank Math';
        }

        // All in One SEO
        if (defined('AIOSEO_VERSION') || function_exists('aioseo')) {
            $conflicts[] = 'All in One SEO';
        }

        // SEOPress
        if (defined('SEOPRESS_VERSION') || function_exists('seopress_init')) {
            $conflicts[] = 'SEOPress';
        }

        return $conflicts;
    }
}
