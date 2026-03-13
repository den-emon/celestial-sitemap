<?php

declare(strict_types=1);

namespace CelestialSitemap;

use CelestialSitemap\Admin\AdminController;
use CelestialSitemap\Admin\ConflictDetector;
use CelestialSitemap\Admin\TaxonomyFields;
use CelestialSitemap\Core\Migrator;
use CelestialSitemap\Core\Options;
use CelestialSitemap\SEO\HeadManager;
use CelestialSitemap\SEO\CanonicalManager;
use CelestialSitemap\SEO\RobotsManager;
use CelestialSitemap\Schema\SchemaManager;
use CelestialSitemap\Sitemap\SitemapRouter;
use CelestialSitemap\Redirect\RedirectManager;
use CelestialSitemap\Core\NotFoundLogger;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?self $instance = null;

    private Options           $options;
    private HeadManager       $head;
    private CanonicalManager  $canonical;
    private RobotsManager     $robots;
    private SchemaManager     $schema;
    private SitemapRouter     $sitemap;
    private RedirectManager   $redirects;
    private NotFoundLogger    $notFoundLogger;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Boot all subsystems. Called at plugins_loaded.
     */
    public function boot(): void
    {
        load_plugin_textdomain('celestial-sitemap', false, dirname(CEL_BASENAME) . '/languages');

        // Disable WP core sitemaps (5.5+) — this plugin provides its own.
        add_filter('wp_sitemaps_enabled', '__return_false');

        // Run migrations if plugin version has changed.
        Migrator::maybeRun();

        $this->options        = new Options();
        $this->canonical      = new CanonicalManager($this->options);
        $this->schema         = new SchemaManager($this->options);
        $this->head           = new HeadManager($this->options, $this->canonical, $this->schema);
        $this->robots         = new RobotsManager($this->options);
        $this->sitemap        = new SitemapRouter($this->options);
        $this->redirects      = new RedirectManager();
        $this->notFoundLogger = new NotFoundLogger();

        // CanonicalManager: only removes WP default rel_canonical.
        // Its output is now handled by HeadManager (unified head builder).
        $this->canonical->register();

        // HeadManager: centralized head builder.
        // Collects at template_redirect, outputs at wp_head priority 1.
        // Includes canonical, prev/next, OGP, meta description, and schema.
        $this->head->register();

        // RobotsManager: wp_robots filter + robots.txt (separate from head output).
        $this->robots->register();

        // SchemaManager: NO register() call — output is handled by HeadManager.
        // SchemaManager is injected into HeadManager and called via getSchemaHtml().

        $this->sitemap->register();
        $this->redirects->register();
        $this->notFoundLogger->register();

        if (is_admin()) {
            (new AdminController($this->options))->register();
            (new TaxonomyFields())->register();
            (new ConflictDetector())->register();
        }
    }

    // ── Accessors (for inter-module use only) ────────────────────────
    public function options(): Options
    {
        return $this->options;
    }
}
