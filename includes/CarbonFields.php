<?php

namespace OneSpace\Toolkit;

if (!defined('ABSPATH')) {
	exit;
}

class CarbonFields {
	public static function boot() {
		if (!self::enabled()) {
			return;
		}

		add_filter('carbon_fields_config', array(__CLASS__, 'config'), 99);
		add_action('enqueue_block_assets', array(__CLASS__, 'styles'), 20);
	}

	public static function enabled() {
		return (bool) apply_filters('onespace_toolkit_cf_fix_enabled', get_option('onespace_toolkit_cf_fix', '1') === '1');
	}

	public static function config($config) {
		if (empty($config['config']['locale']) || !is_array($config['config']['locale'])) {
			return $config;
		}

		$locale = $config['config']['locale'];

		if (isset($locale[0])) {
			if (!is_textdomain_loaded('carbon-fields-ui') && class_exists('\Carbon_Fields\Carbon_Fields')) {
				$loader = \Carbon_Fields\Carbon_Fields::resolve('loader');

				if ($loader && method_exists($loader, 'load_textdomain')) {
					$loader->load_textdomain();
				}
			}

			$translations = get_translations_for_domain('carbon-fields-ui');
			$fixed = array(
				'' => isset($locale['']) ? $locale[''] : array(
					'domain' => 'carbon-fields-ui',
					'lang' => is_admin() ? get_user_locale() : get_locale(),
				),
			);

			foreach ($translations->entries as $entry) {
				if (!is_object($entry) || !isset($entry->singular)) {
					continue;
				}

				$key = $entry->singular;

				if ($entry->context) {
					$key = $entry->context . "\4" . $key;
				}

				$fixed[$key] = $entry->translations;
			}

			if (count($fixed) > 1) {
				$locale = $fixed;
			}
		}

		foreach (self::fallbackTranslations() as $key => $value) {
			if (!isset($locale[$key])) {
				$locale[$key] = $value;
			}
		}

		$config['config']['locale'] = $locale;

		return $config;
	}

	public static function styles() {
		if (!is_admin() || !class_exists('\Carbon_Fields\Carbon_Fields')) {
			return;
		}

		if (!defined('Carbon_Fields\URL')) {
			return;
		}

		$url = constant('Carbon_Fields\URL');
		$version = defined('Carbon_Fields\VERSION') ? constant('Carbon_Fields\VERSION') : null;
		$registered = wp_styles()->registered['carbon-fields-core'] ?? null;

		if ($registered && strpos((string) $registered->src, '/build/classic/') !== false) {
			wp_dequeue_style('carbon-fields-core');
			wp_deregister_style('carbon-fields-core');
		}

		if (!wp_style_is('carbon-fields-core', 'enqueued') && !wp_style_is('carbon-fields-core', 'done')) {
			wp_enqueue_style('onespace-carbon-fields-core', $url . '/build/gutenberg/core.min.css', array(), $version);
			wp_enqueue_style('onespace-carbon-fields-blocks', $url . '/build/gutenberg/blocks.min.css', array('onespace-carbon-fields-core'), $version);
		}

		wp_enqueue_style('buttons');

		if (!wp_style_is('dashicons', 'enqueued') && !wp_style_is('dashicons', 'done')) {
			wp_enqueue_style('dashicons');
		}
	}

	protected static function fallbackTranslations() {
		return array(
			'Add %s' => array('Добавить %s'),
			'Add' => array('Добавить'),
			'Add Entry' => array('Добавить'),
			'Add row' => array('Добавить строку'),
			'Add group' => array('Добавить группу'),
			'Add Another' => array('Добавить ещё'),
			'There are no entries yet.' => array('Записей пока нет.'),
			'Collapse' => array('Свернуть'),
			'Expand' => array('Развернуть'),
			'Collapse All' => array('Свернуть все'),
			'Expand All' => array('Развернуть все'),
			'Duplicate' => array('Дублировать'),
			'Remove' => array('Удалить'),
			'Select Image' => array('Выбрать изображение'),
			'Remove Image' => array('Удалить изображение'),
			'Select File' => array('Выбрать файл'),
			'Remove File' => array('Удалить файл'),
			'Select' => array('Выбрать'),
			'Select Attachments' => array('Выбрать файлы'),
			'Use Attachments' => array('Использовать файлы'),
			'Edit' => array('Редактировать'),
			'Delete' => array('Удалить'),
			'Enter' => array('Ввод'),
			'Cancel' => array('Отмена'),
			'Save' => array('Сохранить'),
			'Show' => array('Показать'),
			'Hide' => array('Скрыть'),
			'Show More' => array('Показать ещё'),
			'Show Less' => array('Свернуть'),
			'Available' => array('Доступно'),
			'Selected' => array('Выбрано'),
			'Search' => array('Поиск'),
			'No matches found' => array('Ничего не найдено'),
			'No items found' => array('Элементы не найдены'),
			'No options' => array('Нет вариантов'),
			'Clear' => array('Очистить'),
			'Click to edit' => array('Нажмите для редактирования'),
			'Close' => array('Закрыть'),
			'No results found.' => array('Ничего не найдено.'),
			'Show All Options' => array('Показать все варианты'),
			'Show Less Options' => array('Показать меньше вариантов'),
			'Thumbnail' => array('Миниатюра'),
		);
	}
}
