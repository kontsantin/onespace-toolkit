<?php

namespace OneSpace\Toolkit\Migrate;

if (!defined('ABSPATH')) {
	exit;
}

class Plugin {
	public static function boot() {
		Admin::boot();

		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::add_command('onespace migrate', __NAMESPACE__ . '\\Cli');
		}
	}

	public static function activate() {
		self::backup_dir();
	}

	public static function siteSlug() {
		$host = wp_parse_url(home_url(), PHP_URL_HOST);

		if (empty($host)) {
			$host = 'site';
		}

		$host = preg_replace('/^www\./i', '', $host);
		$slug = sanitize_file_name($host);

		return $slug !== '' ? $slug : 'site';
	}

	public static function dateStamp() {
		return wp_date('Y-m-d_H-i-s');
	}

	public static function defaultArchiveName() {
		return self::siteSlug() . '-' . self::dateStamp() . '.pomigrate';
	}

	public static function defaultBackupName() {
		return self::siteSlug() . '-' . self::dateStamp() . '.sql';
	}

	public static function defaultPreImportName() {
		return self::siteSlug() . '-pre-import-' . self::dateStamp() . '.sql';
	}

	public static function backup_dir() {
		$dir = WP_CONTENT_DIR . '/onespace-toolkit-backups';

		if (!is_dir($dir)) {
			wp_mkdir_p($dir);
		}

		$htaccess = $dir . '/.htaccess';
		if (!file_exists($htaccess)) {
			file_put_contents($htaccess, "Deny from all\nRequire all denied\n");
		}

		$index = $dir . '/index.php';
		if (!file_exists($index)) {
			file_put_contents($index, "<?php\n");
		}

		return $dir;
	}
}
