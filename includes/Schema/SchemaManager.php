<?php

declare(strict_types=1);

namespace CelestialSitemap\Schema;

use CelestialSitemap\Core\Options;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds JSON-LD structured data.
 *
 * Schemas implemented:
 *  - WebSite (front page, with SearchAction)
 *  - Organization / Person (site-wide)
 *  - Article (singular posts)
 *  - WebPage (singular pages + any public CPT)
 *  - BreadcrumbList (all non-front pages)
 *
 * Filters:
 *  - `cel_schema_website`      — WebSite schema array
 *  - `cel_schema_organization` — Organization/Person schema array
 *  - `cel_schema_article`      — Article schema array (singular post)
 *  - `cel_schema_webpage`      — WebPage schema array (singular page/CPT)
 *  - `cel_schema_breadcrumbs`  — BreadcrumbList schema array
 *  - `cel_schema_type`         — Schema @type for singular CPTs (string, receives post type)
 *
 * Output is handled by HeadManager (unified head builder).
 * This class provides getSchemaHtml() for HeadManager to call.
 *
 * All schemas follow https://schema.org and Google's structured data docs.
 */
final class SchemaManager
{
    private Options $opts;

    public function __construct(Options $opts)
    {
        $this->opts = $opts;
    }

    /**
     * Build all schema <script> tags as a single HTML fragment.
     * Called by HeadManager during the unified head build phase.
     *
     * Returns empty string if schema output is disabled.
     */
    public function getSchemaHtml(): string
    {
        if (! $this->opts->schemaEnabled()) {
            return '';
        }

        $schemas = [];

        if (is_front_page()) {
            $schemas[] = $this->buildWebSite();
            $schemas[] = $this->buildOrganization();
        }

        if (is_singular('post')) {
            $schemas[] = $this->buildArticle();
        } elseif (is_singular() && ! is_front_page()) {
            $schemas[] = $this->buildWebPage();
        }

        if ($this->opts->breadcrumbsEnabled() && ! is_front_page()) {
            $schemas[] = $this->buildBreadcrumbList();
        }

        $schemas = array_filter($schemas);

        if (empty($schemas)) {
            return '';
        }

        $html = '';
        foreach ($schemas as $schema) {
            $html .= '<script type="application/ld+json">';
            $html .= wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $html .= "</script>\n";
        }

        return $html;
    }

    // ── WebSite ──────────────────────────────────────────────────────

    private function buildWebSite(): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => get_bloginfo('name'),
            'url'      => home_url('/'),
            'description'     => get_bloginfo('description'),
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => home_url('/?s={search_term_string}'),
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];

        /** @var array $schema Filtered WebSite schema. */
        return (array) apply_filters('cel_schema_website', $schema);
    }

    // ── Organization / Person ────────────────────────────────────────

    private function buildOrganization(): array
    {
        $data   = $this->buildOrganizationData();
        $schema = array_merge(['@context' => 'https://schema.org'], $data);

        /** @var array $schema Filtered Organization/Person schema. */
        return (array) apply_filters('cel_schema_organization', $schema);
    }

    /**
     * Organization data WITHOUT @context.
     * Used for nesting inside Article.publisher.
     *
     * @return array<string,mixed>
     */
    private function buildOrganizationData(): array
    {
        $type = $this->opts->schemaOrgType();
        $name = $this->opts->schemaOrgName();
        $logo = $this->opts->schemaOrgLogo();

        $schema = [
            '@type' => $type ?: 'Organization',
            'name'  => $name !== '' ? $name : get_bloginfo('name'),
            'url'   => home_url('/'),
        ];

        if ($logo !== '' && $type !== 'Person') {
            $schema['logo'] = [
                '@type' => 'ImageObject',
                'url'   => $logo,
            ];
        }

        if (! isset($schema['logo']) && $type !== 'Person') {
            $icon = get_site_icon_url(512);
            if ($icon) {
                $schema['logo'] = [
                    '@type' => 'ImageObject',
                    'url'   => $icon,
                ];
            }
        }

        return $schema;
    }

    // ── Article ──────────────────────────────────────────────────────

    private function buildArticle(): array
    {
        $post = get_post();
        if (! $post) {
            return [];
        }

        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => 'Article',
            'headline'      => get_the_title($post),
            'datePublished' => get_the_date('c', $post),
            'dateModified'  => get_the_modified_date('c', $post),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => get_permalink($post),
            ],
            'author'        => [
                '@type' => 'Person',
                'name'  => get_the_author_meta('display_name', $post->post_author),
                'url'   => get_author_posts_url((int) $post->post_author),
            ],
            'publisher'     => $this->buildOrganizationData(),
        ];

        if (has_post_thumbnail($post->ID)) {
            $imgId  = (int) get_post_thumbnail_id($post->ID);
            $imgSrc = wp_get_attachment_image_src($imgId, 'full');
            if ($imgSrc) {
                $schema['image'] = [
                    '@type'  => 'ImageObject',
                    'url'    => $imgSrc[0],
                    'width'  => $imgSrc[1],
                    'height' => $imgSrc[2],
                ];
            }
        }

        $desc = (string) get_post_meta($post->ID, '_cel_description', true);
        if ($desc === '') {
            $desc = has_excerpt($post) ? get_the_excerpt($post) : wp_trim_words(wp_strip_all_tags($post->post_content), 30);
        }
        if ($desc !== '') {
            $schema['description'] = $desc;
        }

        $wordCount = $this->countWords(wp_strip_all_tags($post->post_content));
        if ($wordCount > 0) {
            $schema['wordCount'] = $wordCount;
        }

        /** @var array $schema Filtered Article schema. */
        return (array) apply_filters('cel_schema_article', $schema, $post);
    }

    // ── WebPage (pages + any public CPT) ─────────────────────────────

    private function buildWebPage(): array
    {
        $post = get_post();
        if (! $post) {
            return [];
        }

        $type = (string) apply_filters('cel_schema_type', 'WebPage', $post->post_type);

        $schema = [
            '@context'      => 'https://schema.org',
            '@type'         => $type,
            'name'          => get_the_title($post),
            'url'           => get_permalink($post),
            'datePublished' => get_the_date('c', $post),
            'dateModified'  => get_the_modified_date('c', $post),
        ];

        $desc = (string) get_post_meta($post->ID, '_cel_description', true);
        if ($desc === '' && $post->post_type !== 'page') {
            $desc = has_excerpt($post) ? get_the_excerpt($post) : wp_trim_words(wp_strip_all_tags($post->post_content), 30);
        }
        if ($desc !== '') {
            $schema['description'] = $desc;
        }

        if ($post->post_type !== 'page' && has_post_thumbnail($post->ID)) {
            $imgId  = (int) get_post_thumbnail_id($post->ID);
            $imgSrc = wp_get_attachment_image_src($imgId, 'full');
            if ($imgSrc) {
                $schema['image'] = [
                    '@type'  => 'ImageObject',
                    'url'    => $imgSrc[0],
                    'width'  => $imgSrc[1],
                    'height' => $imgSrc[2],
                ];
            }
        }

        /** @var array $schema Filtered WebPage schema. */
        return (array) apply_filters('cel_schema_webpage', $schema, $post);
    }

    // ── BreadcrumbList ───────────────────────────────────────────────

    private function buildBreadcrumbList(): array
    {
        $items = [];
        $pos   = 1;

        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos++,
            'name'     => __('Home', 'celestial-sitemap'),
            'item'     => home_url('/'),
        ];

        if (is_singular()) {
            $post = get_post();
            if (! $post) {
                return [];
            }

            if ($post->post_type !== 'page') {
                $pto = get_post_type_object($post->post_type);
                if ($pto && $pto->has_archive) {
                    $archiveUrl = get_post_type_archive_link($post->post_type);
                    if ($archiveUrl) {
                        $items[] = [
                            '@type'    => 'ListItem',
                            'position' => $pos++,
                            'name'     => $pto->labels->name ?? $pto->label,
                            'item'     => $archiveUrl,
                        ];
                    }
                }
            }

            if ($post->post_type === 'post') {
                $cats = get_the_category($post->ID);
                if (! empty($cats)) {
                    usort($cats, static fn($a, $b) => $b->parent <=> $a->parent);
                    $cat = $cats[0];

                    $ancestors = get_ancestors($cat->term_id, 'category');
                    $ancestors = array_reverse($ancestors);
                    foreach ($ancestors as $ancestorId) {
                        $ancestor = get_term($ancestorId, 'category');
                        if ($ancestor && ! is_wp_error($ancestor)) {
                            $link = get_term_link($ancestor);
                            if (! is_wp_error($link)) {
                                $items[] = [
                                    '@type'    => 'ListItem',
                                    'position' => $pos++,
                                    'name'     => $ancestor->name,
                                    'item'     => $link,
                                ];
                            }
                        }
                    }

                    $items[] = [
                        '@type'    => 'ListItem',
                        'position' => $pos++,
                        'name'     => $cat->name,
                        'item'     => get_category_link($cat->term_id),
                    ];
                }
            }

            if ($post->post_type === 'page' && $post->post_parent) {
                $ancestors = array_reverse(get_post_ancestors($post->ID));
                foreach ($ancestors as $ancestorId) {
                    $items[] = [
                        '@type'    => 'ListItem',
                        'position' => $pos++,
                        'name'     => get_the_title($ancestorId),
                        'item'     => get_permalink($ancestorId),
                    ];
                }
            }

            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos,
                'name'     => get_the_title($post),
                'item'     => get_permalink($post),
            ];
        }

        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $link = get_term_link($term);
                if (! is_wp_error($link)) {
                    $items[] = [
                        '@type'    => 'ListItem',
                        'position' => $pos,
                        'name'     => $term->name,
                        'item'     => $link,
                    ];
                }
            }
        }

        if (count($items) < 2) {
            return [];
        }

        $schema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];

        /** @var array $schema Filtered BreadcrumbList schema. */
        return (array) apply_filters('cel_schema_breadcrumbs', $schema);
    }

    // ── Word Count (CJK-safe) ───────────────────────────────────────

    private function countWords(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        $cjkCount = (int) preg_match_all(
            '/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}]/u',
            $text
        );

        $nonCjk = (string) preg_replace(
            '/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}]/u',
            ' ',
            $text
        );
        $nonCjk = trim((string) preg_replace('/\s+/', ' ', $nonCjk));
        $latinCount = $nonCjk !== '' ? str_word_count($nonCjk) : 0;

        return $cjkCount + $latinCount;
    }
}
