<?php

namespace OneSpace\Toolkit\Migrate;

if (!defined('ABSPATH')) {
	exit;
}

class Archive {
	public static function openForWrite($path) {
		$zip = new \ZipArchive();

		if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('Не удалось создать архив: ' . $path);
		}

		return $zip;
	}

	public static function openForRead($path) {
		$zip = new \ZipArchive();

		if ($zip->open($path) !== true) {
			throw new \RuntimeException('Не удалось открыть архив: ' . $path);
		}

		return $zip;
	}

	protected static function iterator($dir, $exclude, $excludeBase = '') {
		$base = str_replace('\\', '/', rtrim($dir, '/\\'));
		$exclude = array_map(function ($item) {
			return str_replace('\\', '/', rtrim($item, '/\\'));
		}, $exclude);

		$directory = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
		$filter = new \RecursiveCallbackFilterIterator($directory, function ($file) use ($base, $exclude, $excludeBase) {
			$path = str_replace('\\', '/', $file->getPathname());
			$relative = ltrim(substr($path, strlen($base)), '/');
			$match = $excludeBase !== '' ? $excludeBase . '/' . $relative : $relative;

			return !self::isExcluded($match, $exclude);
		});

		return new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);
	}

	public static function countDirectory($dir, $exclude = array(), $excludeBase = '') {
		$dir = rtrim($dir, '/\\');

		if (!is_dir($dir)) {
			return 0;
		}

		$count = 0;

		foreach (self::iterator($dir, $exclude, $excludeBase) as $file) {
			if ($file->isDir() || !$file->isReadable()) {
				continue;
			}

			$count++;
		}

		return $count;
	}

	public static function countPath($path, $exclude = array(), $excludeBase = '') {
		if (is_file($path)) {
			$match = $excludeBase !== '' ? $excludeBase : basename($path);

			return self::isExcluded($match, $exclude) ? 0 : 1;
		}

		if (is_dir($path)) {
			return self::countDirectory($path, $exclude, $excludeBase);
		}

		return 0;
	}

	public static function addDirectory($zip, $dir, $prefix, $exclude = array(), $progress = null, $total = 0, $excludeBase = '') {
		$dir = rtrim($dir, '/\\');

		if (!is_dir($dir)) {
			return 0;
		}

		$base = str_replace('\\', '/', $dir);
		$count = 0;

		foreach (self::iterator($dir, $exclude, $excludeBase) as $file) {
			if ($file->isDir() || !$file->isReadable()) {
				continue;
			}

			$path = str_replace('\\', '/', $file->getPathname());
			$relative = ltrim(substr($path, strlen($base)), '/');

			if ($relative === '') {
				continue;
			}

			$zip->addFile($file->getPathname(), $prefix . '/' . $relative);
			$count++;

			if (is_callable($progress) && ($count % 25 === 0)) {
				call_user_func($progress, $count, $total);
			}
		}

		if (is_callable($progress)) {
			call_user_func($progress, $count, $total);
		}

		return $count;
	}

	public static function addPath($zip, $path, $local, $exclude = array(), $progress = null, $total = 0, $excludeBase = '') {
		if (is_file($path)) {
			$match = $excludeBase !== '' ? $excludeBase : basename($path);

			if (self::isExcluded($match, $exclude)) {
				return 0;
			}

			if (!$zip->addFile($path, $local)) {
				return 0;
			}

			if (is_callable($progress)) {
				call_user_func($progress, 1, $total > 0 ? $total : 1);
			}

			return 1;
		}

		if (is_dir($path)) {
			return self::addDirectory($zip, $path, $local, $exclude, $progress, $total, $excludeBase);
		}

		return 0;
	}

	protected static function isExcluded($relative, $exclude) {
		foreach ($exclude as $item) {
			if ($relative === $item || strpos($relative, $item . '/') === 0) {
				return true;
			}
		}

		return false;
	}

	public static function copyDirectory($source, $destination, $exclude = array(), $progress = null, $total = 0) {
		$source = rtrim($source, '/\\');

		if (!is_dir($source)) {
			return 0;
		}

		$base = str_replace('\\', '/', $source);
		$count = 0;

		foreach (self::iterator($source, $exclude, '') as $file) {
			$path = str_replace('\\', '/', $file->getPathname());
			$relative = ltrim(substr($path, strlen($base)), '/');

			if ($relative === '') {
				continue;
			}

			$target = rtrim($destination, '/\\') . '/' . $relative;

			if ($file->isDir()) {
				if (!is_dir($target)) {
					wp_mkdir_p($target);
				}
				continue;
			}

			$targetDir = dirname($target);
			if (!is_dir($targetDir)) {
				wp_mkdir_p($targetDir);
			}

			if (copy($file->getPathname(), $target)) {
				$count++;

				if (is_callable($progress) && ($count % 25 === 0)) {
					call_user_func($progress, $count, $total);
				}
			}
		}

		if (is_callable($progress)) {
			call_user_func($progress, $count, $total);
		}

		return $count;
	}

	public static function removeDirectory($dir) {
		$dir = rtrim($dir, '/\\');

		if (!is_dir($dir)) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $file) {
			if ($file->isDir()) {
				@rmdir($file->getPathname());
			} else {
				@unlink($file->getPathname());
			}
		}

		@rmdir($dir);
	}
}
