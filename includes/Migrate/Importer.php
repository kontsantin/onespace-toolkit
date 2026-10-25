<?php

namespace OneSpace\Toolkit\Migrate;

if (!defined('ABSPATH')) {
	exit;
}

class Importer {
	public static function import($file, $args = array()) {
		if (!is_file($file)) {
			throw new \RuntimeException('Архив не найден: ' . $file);
		}

		$targetUrl = !empty($args['url']) ? untrailingslashit($args['url']) : untrailingslashit(home_url());
		$zip = Archive::openForRead($file);
		$manifestJson = $zip->getFromName('manifest.json');

		if ($manifestJson === false) {
			$zip->close();
			throw new \RuntimeException('В архиве нет manifest.json.');
		}

		$manifest = json_decode($manifestJson, true);

		if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'pomigrate') {
			$zip->close();
			throw new \RuntimeException('Неверный формат архива.');
		}

		$temp = trailingslashit(get_temp_dir()) . 'pomigrate-' . wp_generate_password(12, false, false);
		wp_mkdir_p($temp);

		$hasDatabase = $zip->locateName('database.sql') !== false;
		$progress = isset($args['progress']) && is_callable($args['progress']) ? $args['progress'] : null;
		$statements = 0;
		$replaced = 0;
		$backupFile = null;

		if ($hasDatabase) {
			$sqlStream = $zip->getStream('database.sql');

			if (!$sqlStream) {
				$zip->close();
				Archive::removeDirectory($temp);
				throw new \RuntimeException('Не удалось прочитать database.sql.');
			}

			$sqlFile = $temp . '/database.sql';
			$sqlHandle = fopen($sqlFile, 'wb');
			stream_copy_to_stream($sqlStream, $sqlHandle);
			fclose($sqlHandle);
			fclose($sqlStream);

			if (is_callable($progress)) {
				call_user_func($progress, 'backup', 0, 0);
			}

			$backupDir = Plugin::backup_dir();
			$backupFile = $backupDir . '/' . Plugin::defaultPreImportName();
			Database::dump($backupFile, function ($table, $current, $total) use ($progress) {
				if (is_callable($progress)) {
					call_user_func($progress, 'backup', $current, $total, $table);
				}
			});

			$totalStatements = Database::countStatements($sqlFile);

			if (is_callable($progress)) {
				call_user_func($progress, 'import-database', 0, $totalStatements);
			}

			$statements = Database::import($sqlFile, function ($current, $total) use ($progress, $totalStatements) {
				if (is_callable($progress)) {
					call_user_func($progress, 'import-database', $current, $totalStatements);
				}
			});

			wp_cache_flush();

			$oldUrls = array_unique(array_filter(array(
				isset($manifest['site_url']) ? untrailingslashit($manifest['site_url']) : '',
				isset($manifest['home_url']) ? untrailingslashit($manifest['home_url']) : '',
			)));

			foreach ($oldUrls as $oldUrl) {
				if ($oldUrl !== '' && $oldUrl !== $targetUrl) {
					$replaced += Replacer::replace($oldUrl, $targetUrl, null, function ($table, $current, $total) use ($progress) {
						if (is_callable($progress)) {
							call_user_func($progress, 'url', $current, $total, $table);
						}
					});
				}
			}

			update_option('siteurl', $targetUrl);
			update_option('home', $targetUrl);

			$plugin = plugin_basename(ONESPACE_TOOLKIT_FILE);
			$active = (array) get_option('active_plugins', array());

			if (!in_array($plugin, $active, true)) {
				$active[] = $plugin;
				update_option('active_plugins', $active);
			}
		}

		if ($zip->extractTo($temp . '/extract') === false) {
			$zip->close();
			Archive::removeDirectory($temp);
			throw new \RuntimeException('Не удалось распаковать архив.');
		}

		$filesSource = $temp . '/extract/files';
		$files = 0;
		$excludeFiles = array(
			'plugins/onespace-toolkit',
			'onespace-toolkit-backups',
			'cache',
			'upgrade',
		);

		if (is_dir($filesSource)) {
			$totalFiles = Archive::countDirectory($filesSource, $excludeFiles);

			if (is_callable($progress)) {
				call_user_func($progress, 'import-files', 0, $totalFiles);
			}

			$files = Archive::copyDirectory(
				$filesSource,
				WP_CONTENT_DIR,
				$excludeFiles,
				function ($current, $total) use ($progress) {
					if (is_callable($progress)) {
						call_user_func($progress, 'import-files', $current, $total);
					}
				},
				$totalFiles
			);
		}

		$zip->close();
		Archive::removeDirectory($temp);

		wp_cache_flush();

		if ($hasDatabase) {
			flush_rewrite_rules(false);
		}

		return array(
			'statements' => $statements,
			'files' => $files,
			'replaced' => $replaced,
			'url' => $hasDatabase ? $targetUrl : '',
			'backup' => $backupFile,
			'database' => $hasDatabase,
			'manifest' => $manifest,
		);
	}
}
