<?php
/**
 * Plugin Name: OneSpace Toolkit
 * Plugin URI: https://github.com/kontsantin/onespace-toolkit
 * Description: Миграция сайта в формате .pomigrate, транслитерация слагов и русификация Carbon Fields.
 * Version: 1.0.2
 * Author: OneSpace
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: onespace-toolkit
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
	exit;
}

define('ONESPACE_TOOLKIT_VERSION', '1.0.2');
define('ONESPACE_TOOLKIT_FILE', __FILE__);
define('ONESPACE_TOOLKIT_DIR', plugin_dir_path(__FILE__));
define('ONESPACE_TOOLKIT_URL', plugin_dir_url(__FILE__));

require_once ONESPACE_TOOLKIT_DIR . 'includes/Translit.php';
require_once ONESPACE_TOOLKIT_DIR . 'includes/CarbonFields.php';
require_once ONESPACE_TOOLKIT_DIR . 'includes/Migrate/Job.php';
require_once ONESPACE_TOOLKIT_DIR . 'includes/Migrate/Archive.php';
require_once ONESPACE_TOOLKIT_DIR . 'includes/Migrate/Database.php';
require_once ONESPACE_TOOLKIT_DIR . 'includes/Migrate/Replacer.php';
require_once ONESPACE_TOOLKIT_DIR . 'includes/Migrate/Exporter.php';
require_once ONESPACE_TOOLKIT_DIR . 'includes/Migrate/Importer.php';
require_once ONESPACE_TOOLKIT_DIR . 'includes/Migrate/Cli.php';
require_once ONESPACE_TOOLKIT_DIR . 'includes/Migrate/Admin.php';
require_once ONESPACE_TOOLKIT_DIR . 'includes/Migrate/Plugin.php';

register_activation_hook(__FILE__, array('OneSpace\\Toolkit\\Migrate\\Plugin', 'activate'));

add_action('plugins_loaded', function () {
	\OneSpace\Toolkit\Translit::boot();
	\OneSpace\Toolkit\CarbonFields::boot();
	\OneSpace\Toolkit\Migrate\Plugin::boot();
});

