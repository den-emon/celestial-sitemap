<?php
/**
 * Plugin Name: Celestial Sitemap
 * Description: Enterprise-grade WordPress SEO plugin — title/meta/canonical/OGP/Schema/Sitemap/Redirect/404 log.
 * Version: 3.5.2
 * Requires at least: 6.0
 * Tested up to: 6.7
 * Requires PHP: 8.1
 * Author: Ikkido-den (一揆堂田)
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: celestial-sitemap
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

// ── Constants ────────────────────────────────────────────────────────
define('CEL_VERSION', '3.5.2');
define('CEL_FILE', __FILE__);
define('CEL_DIR', plugin_dir_path(__FILE__));
define('CEL_URL', plugin_dir_url(__FILE__));
define('CEL_BASENAME', plugin_basename(__FILE__));
define('CEL_MIN_WP', '6.0');
define('CEL_MIN_PHP', '8.1');

// ── Autoloader (PSR-4: CelestialSitemap\ → includes/) ─────────────────
spl_autoload_register(static function (string $class): void {
    $prefix = 'CelestialSitemap\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = CEL_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// ── Activation / Deactivation ────────────────────────────────────────
register_activation_hook(__FILE__, static function (): void {
    \CelestialSitemap\Core\Activator::activate();
});

register_deactivation_hook(__FILE__, static function (): void {
    \CelestialSitemap\Core\Deactivator::deactivate();
});

// ── Boot ─────────────────────────────────────────────────────────────
add_action('plugins_loaded', static function (): void {
    \CelestialSitemap\Plugin::instance()->boot();
}, 10);
