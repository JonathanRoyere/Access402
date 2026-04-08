<?php
/**
 * Plugin Name: Access402
 * Plugin URI: https://github.com/JonathanRoyere/access402
 * Description: Sell access to WordPress paths with x402-style payment rules, global defaults, and trusted bypass controls.
 * Version: 1.0.1
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Jonathan Royere
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: access402
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ACCESS402_VERSION', '1.0.1');
define('ACCESS402_DB_VERSION', '1.0.0');
define('ACCESS402_PLUGIN_FILE', __FILE__);
define('ACCESS402_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ACCESS402_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once ACCESS402_PLUGIN_DIR . 'src/Support/Autoloader.php';

\Access402\Support\Autoloader::register();

register_activation_hook(
    __FILE__,
    static function (): void {
        \Access402\Plugin::activate();
    }
);

register_deactivation_hook(
    __FILE__,
    static function (): void {
        \Access402\Plugin::deactivate();
    }
);

add_action(
    'plugins_loaded',
    static function (): void {
        \Access402\Plugin::instance()->boot();
    }
);
