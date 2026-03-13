<?php

declare(strict_types=1);

namespace CelestialSitemap\SEO;

use CelestialSitemap\Core\Options;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Canonical URL management.
 *
 * Strategy:
 *  1. Per-post/term `_cel_canonical` meta overrides everything.
 *  2. Paginated archives → self-referencing canonical (current page URL).
 *  3. Paginated singular → delegated to wp_get_canonical_url().
 *  4. Default: `get_permalink()` / term link / archive link.
 *
 * Filter: `cel_canonical_url` allows third-party override of the resolved URL.
 *
 * Output is handled by HeadManager (unified head builder).
 * This class provides resolve() and getCanonicalHtml() for HeadManager to call.
 */
final class CanonicalManager
{
    private Options $opts;

    /** @var string|null Pre-resolved canonical URL (cached per request). */
    private ?string $resolvedUrl = null;

    public function __construct(Options $opts)
    {
        $this->opts = $opts;
    }

    /**
     * Register hooks. Only removes WP default rel_canonical.
     * Output is delegated to HeadManager via getCanonicalHtml().
     */
    public function register(): void
    {
        remove_action('wp_head', 'rel_canonical');
    }

    /**
     * Build canonical + prev/next HTML for the unified head builder.
     * All filters are applied. Result is a complete HTML fragment.
     */
    public function getCanonicalHtml(): string
    {
        $html = '';
        $url  = $this->resolve();

        /** @var string $url Filtered canonical URL for the current request. */
        $url = (string) apply_filters('cel_canonical_url', $url);

        if ($url !== '') {
            $html .= '<link rel="canonical" href="' . esc_url($url) . '" />' . "\n";
        }

        $html .= $this->buildPrevNextHtml();

        return $html;
    }

    /**
     * Resolve the canonical URL for the current request.
     * Result is cached per request.
     */
    public function resolve(): string
    {
        if ($this->resolvedUrl !== null) {
            return $this->resolvedUrl;
        }

        $this->resolvedUrl = $this->doResolve();
        return $this->resolvedUrl;
    }

    private function doResolve(): string
    {
        if (is_singular()) {
            $postId = (int) get_the_ID();
            if ($postId === 0) {
                return '';
            }
            $custom = (string) get_post_meta($postId, '_cel_canonical', true);
            if ($custom !== '') {
                return $custom;
            }
            return (string) wp_get_canonical_url($postId);
        }

        $paged = (int) get_query_var('paged', 0);

        if (is_front_page() || is_home()) {
            if ($paged > 1) {
                return (string) get_pagenum_link($paged);
            }
            return home_url('/');
        }

        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $custom = (string) get_term_meta($term->term_id, '_cel_canonical', true);
                if ($custom !== '') {
                    return $custom;
                }
                if ($paged > 1) {
                    return (string) get_pagenum_link($paged);
                }
                $link = get_term_link($term);
                return is_wp_error($link) ? '' : (string) $link;
            }
        }

        if (is_post_type_archive()) {
            $obj  = get_queried_object();
            $name = ($obj instanceof \WP_Post_Type) ? $obj->name : '';
            $link = $name !== '' ? get_post_type_archive_link($name) : false;
            if (! $link) {
                return '';
            }
            if ($paged > 1) {
                return (string) get_pagenum_link($paged);
            }
            return (string) $link;
        }

        if (is_author()) {
            $author = get_queried_object();
            if (! $author instanceof \WP_User) {
                return '';
            }
            if ($paged > 1) {
                return (string) get_pagenum_link($paged);
            }
            return get_author_posts_url($author->ID);
        }

        return '';
    }

    private function buildPrevNextHtml(): string
    {
        $html = '';

        // Singular paginated posts (<!--nextpage-->)
        if (is_singular()) {
            global $page, $numpages;
            if ($numpages <= 1) {
                return '';
            }

            $postId = (int) get_the_ID();
            if ($postId === 0) {
                return '';
            }

            if ($page > 1) {
                $prev = $this->singularPageUrl($postId, $page - 1);
                $html .= '<link rel="prev" href="' . esc_url($prev) . '" />' . "\n";
            }

            if ($page < $numpages) {
                $next = $this->singularPageUrl($postId, $page + 1);
                $html .= '<link rel="next" href="' . esc_url($next) . '" />' . "\n";
            }
            return $html;
        }

        // Archive pagination
        if (! is_archive() && ! is_home()) {
            return '';
        }

        global $wp_query;
        $maxPages = (int) $wp_query->max_num_pages;
        if ($maxPages <= 1) {
            return '';
        }

        $paged = max(1, (int) get_query_var('paged', 1));

        if ($paged > 1) {
            $html .= '<link rel="prev" href="' . esc_url((string) get_pagenum_link($paged - 1)) . '" />' . "\n";
        }

        if ($paged < $maxPages) {
            $html .= '<link rel="next" href="' . esc_url((string) get_pagenum_link($paged + 1)) . '" />' . "\n";
        }

        return $html;
    }

    /**
     * Build a paginated singular post URL that works with both
     * pretty permalinks and default (?p=123) permalink structures.
     */
    private function singularPageUrl(int $postId, int $pageNum): string
    {
        if ($pageNum <= 1) {
            return (string) get_permalink($postId);
        }

        $link = _wp_link_page($pageNum);
        if (preg_match('/href="([^"]+)"/', $link, $matches)) {
            return $matches[1];
        }

        $permalink = get_permalink($postId);
        if (! $permalink) {
            return '';
        }

        if (get_option('permalink_structure')) {
            return trailingslashit((string) $permalink) . $pageNum . '/';
        }

        return add_query_arg('page', $pageNum, (string) $permalink);
    }
}
