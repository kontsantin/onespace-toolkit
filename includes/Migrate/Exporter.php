<?php

namespace OneSpace\Toolkit\Migrate;

if (!defined('ABSPATH')) {
	exit;
}

class Exporter {
	public static function export($output, $args = array()) {
		if (!class_exists('ZipArchive')) {
			throw new \RuntimeException('Не установлено расширение PHP zip.');
		}

		if (file_exists($output) && empty($args['force'])) {
			throw new \RuntimeException('Файл уже существует: ' . $output);
		}

		$dir = dirname($output);
		if (!is_dir($dir)) {
			wp_mkdir_p($dir);
		}

		$allowedScopes = array('database', 'uploads', 'themes', 'plugins', 'other');
		$scopes = isset($args['scopes']) ? (array) $args['scopes'] : $allowedScopes;
		$scopes = array_values(array_intersect($allowedScopes, array_map('strval', $scopes)));

		$includePaths = self::normalizePaths($args['include_paths'] ?? array());
		$excludePaths = self::normalizePaths($args['exclude_paths'] ?? array());

		$exclude = array_merge(array(
			'cache',
			'upgrade',
			'ai1wm-backups',
			'backups',
			'onespace-toolkit-backups',
			'plugins/onespace-toolkit',
			'.git',
			'node_modules',
		), $excludePaths);
		$exclude = array_values(array_unique($exclude));

		$database = in_array('database', $scopes, true);
		$roots = self::roots($scopes, $includePaths, $exclude);

		if (!$database && empty($roots)) {
			throw new \RuntimeException('Нечего экспортировать: выберите базу данных или папки/файлы.');
		}

		$lock = self::lock();
		$sqlFile = null;
		$zip = null;

		try {
			self::cleanupStaleTemps();

			$totalFiles = 0;
			$totalBytes = 0;

			foreach ($roots as $root) {
				$measure = Archive::measurePath(WP_CONTENT_DIR . '/' . $root, $exclude, $root);
				$totalFiles += $measure['files'];
				$totalBytes += $measure['bytes'];
			}

			$dbBytes = $database ? Database::size() : 0;
			$required = $totalBytes + ($database ? 2 * $dbBytes : 0);
			$required = (int) ($required * 1.1) + (50 * 1024 * 1024);
			$free = @disk_free_space(WP_CONTENT_DIR);

			if ($free !== false && $required > 0 && $free < $required) {
				throw new \RuntimeException(
					'Недостаточно места на диске: нужно примерно ' . size_format($required)
					. ', свободно ' . size_format($free)
					. '. Уменьшите состав экспорта или освободите место.'
				);
			}

			$progress = isset($args['progress']) && is_callable($args['progress']) ? $args['progress'] : null;

			if (is_callable($progress)) {
				call_user_func($progress, 'prepare', 0, 0);
			}

			if ($database) {
				$sqlFile = trailingslashit(get_temp_dir()) . 'pomigrate-' . wp_generate_password(12, false, false) . '.sql';

				Database::dump($sqlFile, function ($table, $current, $total) use ($progress) {
					if (is_callable($progress)) {
						call_user_func($progress, 'database', $current, $total, $table);
					}
				});
			}

			$manifest = array(
				'format' => 'pomigrate',
				'format_version' => 1,
				'plugin_version' => ONESPACE_TOOLKIT_VERSION,
				'created_at' => gmdate('c'),
				'wp_version' => get_bloginfo('version'),
				'site_url' => untrailingslashit(site_url()),
				'home_url' => untrailingslashit(home_url()),
				'table_prefix' => $GLOBALS['wpdb']->prefix,
				'db_charset' => defined('DB_CHARSET') ? DB_CHARSET : '',
				'active_plugins' => (array) get_option('active_plugins', array()),
				'template' => get_option('template'),
				'stylesheet' => get_option('stylesheet'),
				'tables' => $database ? Database::tables() : array(),
				'database' => $database,
				'scopes' => $scopes,
				'include_paths' => $includePaths,
				'exclude_paths' => $excludePaths,
				'files_prefix' => 'files',
				'excluded' => $exclude,
				'roots' => $roots,
			);

			$zip = Archive::openForWrite($output);

			if (!$zip->addFromString('manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))) {
				throw new \RuntimeException('Не удалось записать manifest.json в архив.');
			}

			if ($database && $sqlFile && !$zip->addFile($sqlFile, 'database.sql')) {
				throw new \RuntimeException('Не удалось записать database.sql в архив.');
			}

			if (is_callable($progress)) {
				call_user_func($progress, 'files', 0, $totalFiles);
			}

			$files = 0;

			foreach ($roots as $root) {
				$path = WP_CONTENT_DIR . '/' . $root;

				if (!file_exists($path)) {
					continue;
				}

				$base = $files;

				$files += Archive::addPath(
					$zip,
					$path,
					'files/' . $root,
					$exclude,
					function ($current, $total) use ($progress, $base, $totalFiles) {
						if (is_callable($progress)) {
							call_user_func($progress, 'files', $base + $current, $totalFiles);
						}
					},
					$totalFiles,
					$root
				);
			}

			if ($zip->close() !== true) {
				throw new \RuntimeException('Не удалось завершить архив. Возможно, закончилось место на диске.');
			}

			$zip = null;

			if ($sqlFile) {
				@unlink($sqlFile);
				$sqlFile = null;
			}

			return array(
				'path' => $output,
				'files' => $files,
				'tables' => $database ? count($manifest['tables']) : 0,
				'database' => $database,
				'scopes' => $scopes,
				'manifest' => $manifest,
			);
		} catch (\Throwable $e) {
			if ($zip instanceof \ZipArchive) {
				@$zip->close();
			}

			if ($sqlFile) {
				@unlink($sqlFile);
			}

			self::cleanupOutput($output);

			throw $e;
		} finally {
			self::unlock($lock);
		}
	}

	protected static function lock() {
		$path = Plugin::backup_dir() . '/export.lock';
		$handle = @fopen($path, 'c');

		if (!$handle) {
			throw new \RuntimeException('Не удалось создать lock-файл экспорта.');
		}

		if (!flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);
			throw new \RuntimeException('Экспорт уже выполняется. Дождитесь его завершения.');
		}

		return $handle;
	}

	protected static function unlock($handle) {
		if (is_resource($handle)) {
			@flock($handle, LOCK_UN);
			@fclose($handle);
		}
	}

	protected static function cleanupStaleTemps() {
		$dir = Plugin::backup_dir();
		$limit = time() - 86400;

		foreach (array($dir . '/*.pomigrate.*', $dir . '/*.zip.*') as $pattern) {
			foreach (glob($pattern) ?: array() as $file) {
				if (is_file($file) && filemtime($file) < $limit) {
					@unlink($file);
				}
			}
		}
	}

	protected static function cleanupOutput($output) {
		foreach (glob($output . '.*') ?: array() as $file) {
			if (is_file($file)) {
				@unlink($file);
			}
		}

		if (is_file($output)) {
			@unlink($output);
		}
	}

	protected static function roots($scopes, $includePaths, $exclude) {
		$roots = array();

		if (in_array('uploads', $scopes, true)) {
			$roots[] = 'uploads';
		}

		if (in_array('themes', $scopes, true)) {
			$roots[] = 'themes';
		}

		if (in_array('plugins', $scopes, true)) {
			$roots[] = 'plugins';
		}

		if (in_array('other', $scopes, true)) {
			$entries = @scandir(WP_CONTENT_DIR) ?: array();

			foreach ($entries as $entry) {
				if ($entry === '.' || $entry === '..' || in_array($entry, array('uploads', 'themes', 'plugins'), true)) {
					continue;
				}

				$roots[] = $entry;
			}
		}

		foreach ($includePaths as $path) {
			$roots[] = $path;
		}

		$roots = self::normalizePaths($roots);
		usort($roots, function ($a, $b) {
			return strlen($a) <=> strlen($b);
		});

		$accepted = array();

		foreach ($roots as $root) {
			if (self::pathExcluded($root, $exclude)) {
				continue;
			}

			$inside = false;

			foreach ($accepted as $parent) {
				if ($root === $parent || strpos($root, $parent . '/') === 0) {
					$inside = true;
					break;
				}
			}

			if (!$inside) {
				$accepted[] = $root;
			}
		}

		return $accepted;
	}

	protected static function normalizePaths($paths) {
		if (is_string($paths)) {
			$paths = preg_split('/[\r\n,]+/', $paths);
		}

		$out = array();

		foreach ((array) $paths as $path) {
			$path = trim(str_replace('\\', '/', (string) $path));

			if ($path === '' || $path === '.' || $path === '..') {
				continue;
			}

			$path = ltrim($path, '/');
			$path = preg_replace('#/+#', '/', $path);
			$path = preg_replace('#^(\./)+#', '', $path);

			if (strpos($path, 'wp-content/') === 0) {
				$path = substr($path, strlen('wp-content/'));
				$path = preg_replace('#^(\./)+#', '', $path);
			}

			if ($path === '' || strpos($path, '..') !== false) {
				continue;
			}

			$out[] = untrailingslashit($path);
		}

		return array_values(array_unique($out));
	}

	protected static function pathExcluded($path, $exclude) {
		foreach ($exclude as $item) {
			if ($path === $item || strpos($path, $item . '/') === 0) {
				return true;
			}
		}

		return false;
	}
}
