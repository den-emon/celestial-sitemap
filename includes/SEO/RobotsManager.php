<?php

declare(strict_types=1);

namespace CelestialSitemap\SEO;

use CelestialSitemap\Core\Options;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Controls robots meta tags and robots.txt output.
 *
 * Per-post noindex: `_cel_noindex` post meta = '1'.
 * Per-term noindex: `_cel_noindex` term meta = '1'.
 * Global noindex:   plugin options for archives, tags, author, date, search.
 *
 * Filter: `cel_robots_directives` allows third-party override of the robots array.
 */
final class RobotsManager
{
    private Options $opts;

    public function __construct(Options $opts)
    {
        $this->opts = $opts;
    }

    public function register(): void
    {
        add_filter('wp_robots', [$this, 'filterRobots'], 99);
        add_filter('robots_txt', [$this, 'filterRobotsTxt'], 99, 2);
    }

    /**
     * Filter the wp_robots array (WP 5.7+).
     *
     * @param array<string,bool|string> $robots
     * @return array<string,bool|string>
     */
    public function filterRobots(array $robots): array
    {
        if ($this->shouldNoindex()) {
            $robots['noindex'] = true;
            $robots['follow']  = true;
            unset($robots['max-image-preview'], $robots['max-snippet'], $robots['max-video-preview']);
        }

        /**
         * @var array<string,bool|string> $robots Filtered robots directives.
         */
        return (array) apply_filters('cel_robots_directives', $robots);
    }

    private function shouldNoindex(): bool
    {
        // Per-post
        if (is_singular()) {
            $noindex = get_post_meta((int) get_the_ID(), '_cel_noindex', true);
            if ($noindex === '1') {
                return true;
            }
        }

        // Per-term
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $noindex = get_term_meta($term->term_id, '_cel_noindex', true);
                if ($noindex === '1') {
                    return true;
                }
            }
        }

        // Global rules
        if (is_date() && $this->opts->noindexDate()) {
            return true;
        }
        if (is_author() && $this->opts->noindexAuthor()) {
            return true;
        }
        if (is_search() && $this->opts->noindexSearch()) {
            return true;
        }
        if (is_tag() && $this->opts->noindexTags()) {
            return true;
        }
        if ((is_category() || is_tax()) && $this->opts->noindexArchives()) {
            return true;
        }
        if (is_paged() && is_archive() && $this->opts->noindexArchives()) {
            return true;
        }

        return false;
    }

    /**
     * Append sitemap URL to robots.txt.
     *
     * FIX #2: WordPress passes $public as string ('0' or '1'), NOT bool.
     * Under declare(strict_types=1), typing it as bool causes TypeError.
     * Accept string and cast internally.
     *
     * @param string $output  Current robots.txt content.
     * @param string $public  '1' if blog is public, '0' otherwise (WP core passes string).
     * @return string
     */
    public function filterRobotsTxt(string $output, string $public): string
    {
        if (! (bool) $public) {
            return $output;
        }
        $output .= "\n# Celestial Sitemap v" . CEL_VERSION . "\n";
        $output .= 'Sitemap: ' . home_url('/cel-sitemap.xml') . "\n";

        return $output;
    }
}
