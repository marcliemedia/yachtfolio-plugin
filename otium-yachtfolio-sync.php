<?php
/**
 * Plugin Name:       Otium Yachtfolio Sync
 * Description:       One-way sync from the Yachtfolio Public API into the existing yacht post type. Never writes to Yachtfolio, never publishes on its own.
 * Version:           0.12.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Otium Yachts
 * Text Domain:       otium-yachtfolio-sync
 * Update URI:        https://github.com/marcliemedia/yachtfolio-plugin
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace Otium\Yachtfolio;

if (!defined('ABSPATH')) {
    exit;
}

const VERSION = '0.12.1';

define('OY_YF_FILE', __FILE__);
define('OY_YF_DIR', __DIR__);
define('OY_YF_VERSION', VERSION);

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>Otium Yachtfolio Sync requires PHP 8.1 or newer.</p></div>';
    });
    return;
}

$oy_yf_autoload = __DIR__ . '/vendor/autoload.php';
if (!is_readable($oy_yf_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>Otium Yachtfolio Sync: run <code>composer install</code> inside the plugin directory.</p></div>';
    });
    return;
}
require_once $oy_yf_autoload;

// Bundled Action Scheduler. It self-registers and the newest loaded copy wins,
// so this is safe next to the copies shipped by FluentForm and Rank Math.
$oy_yf_action_scheduler = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if (is_readable($oy_yf_action_scheduler)) {
    require_once $oy_yf_action_scheduler;
}

register_activation_hook(__FILE__, [Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Activator::class, 'deactivate']);

Plugin::instance()->boot();
