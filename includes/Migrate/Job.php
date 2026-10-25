<?php

namespace OneSpace\Toolkit\Migrate;

if (!defined('ABSPATH')) {
	exit;
}

class Job {
	protected static function path($id) {
		$dir = WP_CONTENT_DIR . '/onespace-toolkit-backups/jobs';

		if (!is_dir($dir)) {
			wp_mkdir_p($dir);
		}

		return $dir . '/' . self::sanitize($id) . '.json';
	}

	public static function init($id) {
		$id = self::sanitize($id);
		self::cleanup();

		self::write($id, array(
			'stage' => 'Подготовка',
			'current' => 0,
			'total' => 0,
			'message' => '',
			'done' => false,
			'error' => '',
			'result' => null,
			'updated' => time(),
			'_last' => 0,
		));

		return $id;
	}

	public static function update($id, $stage, $current = 0, $total = 0, $message = '') {
		$id = self::sanitize($id);
		$data = self::read($id);

		if (!is_array($data)) {
			$data = array('done' => false, 'error' => '', 'result' => null);
		}

		$now = microtime(true);

		if (!empty($data['_last']) && ($now - (float) $data['_last']) < 0.4) {
			return;
		}

		$data['stage'] = $stage;
		$data['current'] = (int) $current;
		$data['total'] = (int) $total;
		$data['message'] = (string) $message;
		$data['updated'] = time();
		$data['_last'] = $now;

		self::write($id, $data);
	}

	public static function finish($id, $result) {
		$id = self::sanitize($id);
		$data = self::read($id);
		$data = is_array($data) ? $data : array();

		$data['stage'] = 'Готово';
		$data['current'] = 1;
		$data['total'] = 1;
		$data['message'] = '';
		$data['done'] = true;
		$data['error'] = '';
		$data['result'] = $result;
		$data['updated'] = time();
		$data['_last'] = microtime(true);

		self::write($id, $data);
	}

	public static function fail($id, $error) {
		$id = self::sanitize($id);
		$data = self::read($id);
		$data = is_array($data) ? $data : array();

		$data['stage'] = 'Ошибка';
		$data['done'] = true;
		$data['error'] = (string) $error;
		$data['result'] = null;
		$data['updated'] = time();
		$data['_last'] = microtime(true);

		self::write($id, $data);
	}

	public static function get($id) {
		$id = self::sanitize($id);
		$data = self::read($id);

		if (!is_array($data)) {
			return null;
		}

		unset($data['_last']);

		return $data;
	}

	public static function sanitize($id) {
		return preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $id);
	}

	protected static function cleanup() {
		$dir = WP_CONTENT_DIR . '/onespace-toolkit-backups/jobs';
		$files = glob($dir . '/*.json');

		if (!$files) {
			return;
		}

		$limit = time() - 86400;

		foreach ($files as $file) {
			if (filemtime($file) < $limit) {
				@unlink($file);
			}
		}
	}

	protected static function read($id) {
		$path = self::path($id);

		if (!is_file($path)) {
			return null;
		}

		$data = json_decode((string) file_get_contents($path), true);

		return is_array($data) ? $data : null;
	}

	protected static function write($id, $data) {
		file_put_contents(self::path($id), wp_json_encode($data), LOCK_EX);
	}
}
