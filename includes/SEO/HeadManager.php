<?php

declare(strict_types=1);

namespace CelestialSitemap\SEO;

use CelestialSitemap\Core\Options;
use CelestialSitemap\Schema\SchemaManager;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Unified head builder — centralized single-pass metadata generation.
 *
 * Architecture:
 *   1. collect() runs at `template_redirect` (priority 10) — resolves ALL
 *      metadata in one pass: description, OGP, canonical, prev/next, schema.
 *   2. outputHead() runs at `wp_head` (priority 1) — echoes the pre-built
 *      HTML fragment. No DB queries, no computation at render time.
 *
 * This eliminates redundant data resolution across multiple hooks and ensures
 * a single, predictable output point for all SEO head tags.
 *
 * Filters (all applied during collect phase):
 *  - `cel_head_title`        — resolved document title (string)
 *  - `cel_head_description`  — resolved meta description (string)
 *  - `cel_head_ogp`          — Open Graph tag array (array<string,string>)
 *  - `cel_canonical_url`     — canonical URL (via CanonicalManager)
 *  - `cel_schema_*`          — structured data schemas (via SchemaManager)
 */
final class HeadManager
{
    private Options $opts;
    private CanonicalManager $canonical;
    private SchemaManager $schema;

    /** @var string|null Pre-built HTML fragment, populated by collect(). */
    private ?string $headHtml = null;

    /** @var string|null Cached description, shared between meta and OGP. */
    private ?string $cachedDescription = null;

    public function __construct(Options $opts, CanonicalManager $canonical, SchemaManager $schema)
    {
        $this->opts      = $opts;
        $this->canonical = $canonical;
        $this->schema    = $schema;
    }

    public function register(): void
    {
        // Title filters (must remain as filters, not part of head HTML)
        add_filter('pre_get_document_title', [$this, 'filterTitle'], 99);
        add_filter('document_title_parts', [$this, 'filterTitleParts'], 99);
        add_filter('document_title_separator', [$this, 'filterSeparator'], 99);

        // Robots directives via wp_robots filter (separate from head output)
        add_filter('wp_robots', [$this, 'filterRobotsDirectives'], 50);

        // Collect all metadata at template_redirect (after redirects, before output)
        add_action('template_redirect', [$this, 'collect'], 10);

        // Output pre-built head HTML at wp_head priority 1
        add_action('wp_head', [$this, 'outputHead'], 1);
    }

    // ── Collection phase (template_redirect) ────────────────────────

    /**
     * Resolve all head metadata in a single pass and build the HTML fragment.
     * Called at template_redirect, before any output begins.
     */
    public function collect(): void
    {
        $html = '';

        // 1. Meta description
        $desc = $this->resolveDescription();
        if ($desc !== '') {
            $html .= '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
        }

        // 2. Canonical + prev/next (delegated to CanonicalManager)
        $html .= $this->canonical->getCanonicalHtml();

        // 3. OGP + Twitter Card tags
        $ogp = $this->buildOgp();
        foreach ($ogp as $property => $content) {
            if ($content === '') {
                continue;
            }
            if (str_starts_with($property, 'twitter:')) {
                $html .= '<meta name="' . esc_attr($property) . '" content="' . esc_attr($content) . '" />' . "\n";
            } else {
                $html .= '<meta property="' . esc_attr($property) . '" content="' . esc_attr($content) . '" />' . "\n";
            }
        }

        // 4. Schema JSON-LD (delegated to SchemaManager)
        $html .= $this->schema->getSchemaHtml();

        $this->headHtml = $html;
    }

    // ── Output phase (wp_head) ──────────────────────────────────────

    /**
     * Echo the pre-built head HTML. If collect() hasn't run (edge case),
     * fall back to building inline.
     */
    public function outputHead(): void
    {
        if ($this->headHtml === null) {
            $this->collect();
        }

        echo $this->headHtml;
    }

    // ── Title ────────────────────────────────────────────────────────

    public function filterTitle(string $title): string
    {
        if (is_singular()) {
            $id = (int) get_the_ID();
            if ($id === 0) {
                return $title;
            }
            $custom = (string) get_post_meta($id, '_cel_title', true);
            if ($custom !== '') {
                return $custom;
            }
        }
        return $title;
    }

    public function filterTitleParts(array $parts): array
    {
        if (is_front_page() || is_home()) {
            $custom = $this->opts->homepageTitle();
            if ($custom !== '') {
                $parts['title'] = $custom;
                unset($parts['tagline']);
            }
        }

        if (is_singular()) {
            $id = (int) get_the_ID();
            if ($id > 0) {
                $custom = (string) get_post_meta($id, '_cel_title', true);
                if ($custom !== '') {
                    $parts['title'] = $custom;
                }
            }
        }

        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $custom = (string) get_term_meta($term->term_id, '_cel_title', true);
                if ($custom !== '') {
                    $parts['title'] = $custom;
                }
            }
        }

        $parts['title'] = (string) apply_filters('cel_head_title', $parts['title'] ?? '');

        return $parts;
    }

    public function filterSeparator(string $sep): string
    {
        $custom = $this->opts->titleSeparator();
        return $custom !== '' ? " {$custom} " : $sep;
    }

    // ── Robots directives (via wp_robots filter) ─────────────────────

    /**
     * @param array<string,bool|string> $robots
     * @return array<string,bool|string>
     */
    public function filterRobotsDirectives(array $robots): array
    {
        if (is_singular()) {
            $robots['max-image-preview'] = 'large';
            $robots['max-snippet']       = '-1';
            $robots['max-video-preview'] = '-1';
        }
        return $robots;
    }

    // ── Meta Description ─────────────────────────────────────────────

    private function resolveDescription(): string
    {
        if ($this->cachedDescription !== null) {
            return $this->cachedDescription;
        }

        $desc = '';

        if (is_singular()) {
            $post = get_post();
            if (! $post) {
                $this->cachedDescription = '';
                return '';
            }

            $custom = (string) get_post_meta($post->ID, '_cel_description', true);
            if ($custom !== '') {
                $desc = $custom;
            } elseif (! post_password_required($post)) {
                $text = $post->post_excerpt !== '' ? $post->post_excerpt : $post->post_content;
                $text = wp_strip_all_tags(strip_shortcodes($text));
                $desc = $this->truncateDescription($text);
            }
        } elseif (is_front_page() || is_home()) {
            $custom = $this->opts->homepageDesc();
            if ($custom !== '') {
                $desc = $custom;
            } else {
                $desc = (string) get_bloginfo('description');
            }
        } elseif (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $custom = (string) get_term_meta($term->term_id, '_cel_description', true);
                if ($custom !== '') {
                    $desc = $custom;
                } elseif ($term->description !== '') {
                    $desc = $this->truncateDescription($term->description);
                }
            }
        } elseif (is_author()) {
            $author = get_queried_object();
            if ($author instanceof \WP_User) {
                $bio = get_the_author_meta('description', $author->ID);
                if ($bio !== '') {
                    $desc = $this->truncateDescription($bio);
                }
            }
        }

        $this->cachedDescription = (string) apply_filters('cel_head_description', $desc);
        return $this->cachedDescription;
    }

    private function truncateDescription(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text));
        if ($text === null) {
            return '';
        }
        if (mb_strlen($text) <= 160) {
            return $text;
        }
        $truncated = mb_substr($text, 0, 157);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > 100) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }
        return $truncated . '...';
    }

    // ── OGP ──────────────────────────────────────────────────────────

    /**
     * @return array<string,string>
     */
    private function buildOgp(): array
    {
        if (! $this->opts->ogpEnabled()) {
            return [];
        }

        $canonicalUrl = $this->canonical->resolve();

        $og = [];
        $og['og:locale']    = get_locale();
        $og['og:site_name'] = get_bloginfo('name');

        if (is_singular()) {
            $og['og:type']  = 'article';
            $og['og:title'] = wp_get_document_title();
            $og['og:url']   = $canonicalUrl !== '' ? $canonicalUrl : (string) get_permalink();

            $desc = $this->resolveDescription();
            if ($desc !== '') {
                $og['og:description'] = $desc;
            }

            $imageUrl = $this->resolveOgImage((int) get_the_ID());
            if ($imageUrl !== '') {
                $og['og:image'] = $imageUrl;
            }

            $og['article:published_time'] = get_the_date('c');
            $og['article:modified_time']  = get_the_modified_date('c');

            $og['twitter:card']  = ($imageUrl !== '') ? 'summary_large_image' : 'summary';
            $og['twitter:title'] = $og['og:title'];
            if (isset($og['og:description'])) {
                $og['twitter:description'] = $og['og:description'];
            }
        } else {
            $og['og:type']  = 'website';
            $og['og:title'] = wp_get_document_title();
            $og['og:url']   = $canonicalUrl !== '' ? $canonicalUrl : home_url('/');

            $desc = $this->resolveDescription();
            if ($desc !== '') {
                $og['og:description'] = $desc;
            }

            $defaultImage = $this->opts->ogpDefaultImage();
            if ($defaultImage !== '') {
                $og['og:image'] = $defaultImage;
            }

            $og['twitter:card']  = 'summary';
            $og['twitter:title'] = $og['og:title'];
            if (isset($og['og:description'])) {
                $og['twitter:description'] = $og['og:description'];
            }
        }

        // Prevent output of invalid OGP (og:url is required by spec)
        if (empty($og['og:url'])) {
            return [];
        }

        $fbAppId = $this->opts->fbAppId();
        if ($fbAppId !== '') {
            $og['fb:app_id'] = $fbAppId;
        }

        $twitterSite = $this->opts->twitterSite();
        if ($twitterSite !== '') {
            $og['twitter:site'] = $twitterSite;
        }

        return (array) apply_filters('cel_head_ogp', $og);
    }

    private function resolveOgImage(int $postId): string
    {
        $custom = (string) get_post_meta($postId, '_cel_og_image', true);
        if ($custom !== '') {
            return $custom;
        }

        if (has_post_thumbnail($postId)) {
            $src = wp_get_attachment_image_src(get_post_thumbnail_id($postId), 'large');
            if ($src) {
                return $src[0];
            }
        }

        return $this->opts->ogpDefaultImage();
    }
}
