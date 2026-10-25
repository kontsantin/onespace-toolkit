<?php

namespace OneSpace\Toolkit\Migrate;

if (!defined('ABSPATH')) {
	exit;
}

class Replacer {
	protected static $stringTypes = array(
		'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json',
	);

	public static function replace($search, $replace, $tables = null, $progress = null) {
		global $wpdb;

		$search = (string) $search;
		$replace = (string) $replace;

		if ($search === '' || $search === $replace) {
			return 0;
		}

		$variants = array(
			$search => $replace,
			str_replace('/', '\\/', $search) => str_replace('/', '\\/', $replace),
			addslashes($search) => addslashes($replace),
		);

		$tables = $tables ? (array) $tables : self::tables();
		$changed = 0;
		$index = 0;
		$total = count($tables);

		foreach ($tables as $table) {
			$index++;

			if (is_callable($progress)) {
				call_user_func($progress, $table, $index, $total);
			}

			$columns = $wpdb->get_results('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`', ARRAY_A);

			if (empty($columns)) {
				continue;
			}

			$textColumns = array();
			$primary = array();

			foreach ($columns as $column) {
				$type = strtolower($column['Type']);
				$type = preg_replace('/\(.*$/', '', $type);

				if (in_array($type, self::$stringTypes, true)) {
					$textColumns[] = $column['Field'];
				}

				if (isset($column['Key']) && $column['Key'] === 'PRI') {
					$primary[] = $column['Field'];
				}
			}

			if (empty($textColumns)) {
				continue;
			}

			$total = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');
			$offset = 0;
			$limit = 200;

			while ($offset < $total) {
				$rows = $wpdb->get_results(
					'SELECT * FROM `' . str_replace('`', '``', $table) . '` LIMIT ' . (int) $offset . ', ' . (int) $limit,
					ARRAY_A
				);

				if (empty($rows)) {
					break;
				}

				foreach ($rows as $row) {
					$update = array();

					foreach ($textColumns as $column) {
						if (!isset($row[$column]) || $row[$column] === null) {
							continue;
						}

						$new = self::replaceValue($row[$column], $variants);

						if ($new !== $row[$column]) {
							$update[$column] = $new;
						}
					}

					if (empty($update)) {
						continue;
					}

					$where = array();

					if (!empty($primary)) {
						foreach ($primary as $column) {
							$where[$column] = $row[$column];
						}
					} else {
						$where = $row;
					}

					$result = $wpdb->update(
						$table,
						$update,
						$where,
						array_fill(0, count($update), '%s'),
						array_fill(0, count($where), '%s')
					);

					if ($result === false) {
						throw new \RuntimeException('Ошибка замены URL в таблице ' . $table . ': ' . $wpdb->last_error);
					}

					if ($result > 0) {
						$changed++;
					}
				}

				$offset += count($rows);
			}
		}

		wp_cache_flush();

		return $changed;
	}

	protected static function tables() {
		global $wpdb;

		return array_values(array_filter((array) $wpdb->get_col('SHOW TABLES')));
	}

	protected static function replaceValue($value, $variants) {
		$needs = false;

		foreach ($variants as $search => $replace) {
			if ($search !== '' && strpos($value, $search) !== false) {
				$needs = true;
				break;
			}
		}

		if (!$needs) {
			return $value;
		}

		if (is_serialized($value)) {
			$data = @unserialize($value);
			$isFalse = ($value === 'b:0;');

			if ($data !== false || $isFalse) {
				return serialize(self::replaceRecursive($data, $variants));
			}
		}

		return strtr($value, $variants);
	}

	protected static function replaceRecursive($data, $variants) {
		if (is_string($data)) {
			return strtr($data, $variants);
		}

		if (is_array($data)) {
			$result = array();

			foreach ($data as $key => $value) {
				$newKey = is_string($key) ? strtr($key, $variants) : $key;
				$result[$newKey] = self::replaceRecursive($value, $variants);
			}

			return $result;
		}

		if (is_object($data)) {
			if ($data instanceof \__PHP_Incomplete_Class) {
				return $data;
			}

			foreach (get_object_vars($data) as $key => $value) {
				$data->$key = self::replaceRecursive($value, $variants);
			}

			return $data;
		}

		return $data;
	}
}
