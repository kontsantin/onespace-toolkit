<?php

namespace OneSpace\Toolkit\Migrate;

if (!defined('ABSPATH')) {
	exit;
}

class Cli {
	public function export($args, $assoc_args) {
		$output = $assoc_args['output'] ?? (Plugin::backup_dir() . '/' . Plugin::defaultArchiveName());

		if (!$this->isAbsolute($output)) {
			$output = rtrim(getcwd(), '/\\') . '/' . ltrim($output, '/\\');
		}

		$allowed = array('database', 'uploads', 'themes', 'plugins', 'other');
		$scopes = isset($assoc_args['scopes'])
			? preg_split('/[,\s]+/', (string) $assoc_args['scopes'])
			: $allowed;
		$scopes = array_values(array_intersect($allowed, array_filter(array_map('trim', $scopes))));

		$result = Exporter::export($output, array(
			'force' => !empty($assoc_args['force']),
			'scopes' => $scopes,
			'include_paths' => $assoc_args['include'] ?? '',
			'exclude_paths' => $assoc_args['exclude'] ?? '',
			'progress' => function ($stage, $current, $total, $message = '') {
				\WP_CLI::line($this->progressLabel($stage, $current, $total, $message));
			},
		));

		\WP_CLI::success('Архив создан: ' . $result['path']);
		\WP_CLI::line('БД: ' . ($result['database'] ? 'да' : 'нет') . ', файлов: ' . $result['files'] . ', таблиц: ' . $result['tables']);
	}

	public function import($args, $assoc_args) {
		$file = $args[0] ?? '';

		if (!$this->isAbsolute($file)) {
			$file = rtrim(getcwd(), '/\\') . '/' . ltrim($file, '/\\');
		}

		$targetUrl = $assoc_args['target-url'] ?? home_url();

		if (!empty($targetUrl)) {
			$targetUrl = untrailingslashit($targetUrl);
		}

		if (empty($assoc_args['yes'])) {
			\WP_CLI::confirm('Импорт заменит данные из архива (базу и/или файлы). Продолжить?');
		}

		$result = Importer::import($file, array(
			'url' => $targetUrl,
			'progress' => function ($stage, $current, $total, $message = '') {
				\WP_CLI::line($this->progressLabel($stage, $current, $total, $message));
			},
		));

		\WP_CLI::success('Импорт завершён.');
		\WP_CLI::line('БД: ' . ($result['database'] ? 'импортирована' : 'не импортировалась'));
		\WP_CLI::line('SQL-запросов: ' . $result['statements']);
		\WP_CLI::line('Файлов: ' . $result['files']);
		\WP_CLI::line('Замен URL: ' . $result['replaced']);

		if ($result['database']) {
			\WP_CLI::line('URL сайта: ' . $result['url']);
		}

		if (!empty($result['backup'])) {
			\WP_CLI::line('Бэкап БД: ' . $result['backup']);
		}
	}

	public function replace($args) {
		if (count($args) < 2) {
			\WP_CLI::error('Использование: wp onespace migrate replace <старое> <новое>');
		}

		$count = Replacer::replace($args[0], $args[1]);

		\WP_CLI::success('Заменено строк: ' . $count);
	}

	public function backup($args, $assoc_args) {
		$output = $assoc_args['output'] ?? (Plugin::backup_dir() . '/' . Plugin::defaultBackupName());

		if (!$this->isAbsolute($output)) {
			$output = rtrim(getcwd(), '/\\') . '/' . ltrim($output, '/\\');
		}

		$dir = dirname($output);
		if (!is_dir($dir)) {
			wp_mkdir_p($dir);
		}

		Database::dump($output, function ($table, $current, $total) {
			if ($total > 0) {
				\WP_CLI::line($table . ': ' . $current . '/' . $total);
			}
		});

		\WP_CLI::success('Бэкап создан: ' . $output);
	}

	protected function progressLabel($stage, $current, $total, $message = '') {
		$labels = array(
			'prepare' => 'Подготовка',
			'database' => 'База данных',
			'files' => 'Файлы',
			'backup' => 'Бэкап БД',
			'import-database' => 'Импорт БД',
			'import-files' => 'Файлы',
			'url' => 'Замена URL',
		);

		$label = $labels[$stage] ?? $stage;

		if ($message !== '') {
			$label .= ': ' . $message;
		}

		if ($total > 0) {
			$label .= ' ' . $current . '/' . $total;
		} elseif ($current > 0) {
			$label .= ' ' . $current;
		}

		return $label;
	}

	protected function isAbsolute($path) {
		return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || strpos($path, '/') === 0 || strpos($path, '\\\\') === 0;
	}
}
