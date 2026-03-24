<?php

declare(strict_types=1);

namespace CelestialSitemap\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Database version migration system.
 *
 * Compares the stored `cel_version` option against CEL_VERSION.
 * When they differ, runs all migration callbacks for versions between
 * the stored version and the current version, then updates the stored version.
 *
 * To add a new migration:
 *   1. Add an entry to self::MIGRATIONS with the target version as key
 *      and a [self::class, 'methodName'] callable as value.
 *   2. Implement the migration method.
 *
 * Migrations run once per version bump, in ascending version order.
 * Called from Plugin::boot() on every request (cheap no-op when versions match).
 *
 * If a migration fails (throws an exception), the version is NOT updated,
 * so the migration will be retried on the next request. An error is logged
 * to aid debugging.
 */
final class Migrator
{
    /**
     * Map of version => migration callable.
     * Versions must be valid semver strings comparable with version_compare().
     *
     * @var array<string, callable>
     */
    private const MIGRATIONS = [
        // '3.2.0' => [self::class, 'migrateTo320'],
    ];

    /**
     * Run pending migrations if the stored version differs from CEL_VERSION.
     */
    public static function maybeRun(): void
    {
        $stored = (string) get_option('cel_version', '0.0.0');

        if (version_compare($stored, CEL_VERSION, '>=')) {
            return; // Already up to date
        }

        try {
            self::runMigrations($stored);
            update_option('cel_version', CEL_VERSION, true);

            // Flag for SitemapRouter to flush rewrite rules at `wp_loaded`.
            // Cannot flush here (plugins_loaded) because post types are not yet registered.
            update_option('cel_flush_rewrite_rules', 1, true);
        } catch (\Throwable $e) {
            // Log the error but do NOT update the version, so migration retries next request.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'Celestial Sitemap migration failed (from %s to %s): %s in %s:%d',
                    $stored,
                    CEL_VERSION,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
            }
        }
    }

    /**
     * Execute all migration callbacks for versions newer than $fromVersion.
     *
     * @throws \Throwable Re-throws any exception from a migration callback.
     */
    private static function runMigrations(string $fromVersion): void
    {
        // Sort migration versions in ascending order
        $versions = array_keys(self::MIGRATIONS);
        usort($versions, 'version_compare');

        foreach ($versions as $version) {
            if (version_compare($fromVersion, $version, '<')) {
                $callback = self::MIGRATIONS[$version];
                if (is_callable($callback)) {
                    call_user_func($callback);
                }
            }
        }

        // Always run dbDelta to ensure table schema is current
        self::ensureSchema();
    }

    /**
     * Re-run dbDelta to apply any table schema changes.
     * This is safe to call on every upgrade — dbDelta is idempotent.
     *
     * Uses Activator::ensureSchema() instead of Activator::activate()
     * because activate() calls flush_rewrite_rules() which is unsafe
     * at plugins_loaded (post types/taxonomies not yet registered).
     */
    private static function ensureSchema(): void
    {
        Activator::ensureSchema();
    }

    // ── Migration methods ───────────────────────────────────────────
    // Add future migration methods here. Example:
    //
    // private static function migrateTo320(): void
    // {
    //     // Add a new column, migrate data, etc.
    // }
}
