<?php

namespace OneSpace\Toolkit;

if (!defined('ABSPATH')) {
	exit;
}

class Translit {
	public static function boot() {
		add_filter('sanitize_title', array(__CLASS__, 'filter'), 10, 3);
	}

	public static function enabled() {
		return (bool) apply_filters('onespace_toolkit_translit_enabled', get_option('onespace_toolkit_translit', '1') === '1');
	}

	public static function filter($title, $raw_title = '', $context = 'save') {
		if ($context === 'query' || !self::enabled()) {
			return $title;
		}

		$source = ($raw_title !== '') ? $raw_title : $title;

		if (!preg_match('/[а-яё]/u', $source)) {
			return $title;
		}

		$converted = self::convert($source);

		return $converted !== '' ? $converted : $title;
	}

	public static function convert($str) {
		$map = array(
			'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
			'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
			'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
			'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts',
			'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
			'э' => 'e', 'ю' => 'yu', 'я' => 'ya', '№' => 'n', '«' => '', '»' => '',
			'“' => '', '”' => '', '‘' => '', '’' => '', '–' => '-', '—' => '-',
			'…' => '-', '×' => 'x',
		);

		$str = mb_strtolower((string) $str, 'UTF-8');
		$out = '';
		$len = mb_strlen($str, 'UTF-8');

		for ($i = 0; $i < $len; $i++) {
			$ch = mb_substr($str, $i, 1, 'UTF-8');

			if (isset($map[$ch])) {
				$out .= $map[$ch];
			} elseif (preg_match('/[a-z0-9\-_]/', $ch)) {
				$out .= $ch;
			} else {
				$out .= '-';
			}
		}

		$out = preg_replace('/-+/', '-', $out);

		return trim($out, '-');
	}
}
