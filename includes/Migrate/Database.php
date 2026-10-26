<?php

namespace OneSpace\Toolkit\Migrate;

if (!defined('ABSPATH')) {
	exit;
}

class Database {
	public static function size() {
		global $wpdb;

		$size = $wpdb->get_var(
			'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()'
		);

		return $size ? (int) $size : 0;
	}

	public static function tables() {
		global $wpdb;

		$tables = $wpdb->get_col('SHOW TABLES');

		return array_values(array_filter((array) $tables));
	}

	public static function dump($file, $progress = null) {
		global $wpdb;

		$handle = fopen($file, 'wb');

		if (!$handle) {
			throw new \RuntimeException('Не удалось открыть файл дампа: ' . $file);
		}

		fwrite($handle, "SET NAMES utf8mb4;\n");
		fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
		fwrite($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

		$tables = self::tables();

		foreach ($tables as $table) {
			$create = $wpdb->get_row('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`', ARRAY_N);

			if (!$create || !isset($create[1])) {
				throw new \RuntimeException('Не удалось прочитать структуру таблицы: ' . $table);
			}

			fwrite($handle, 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . "`;\n");
			fwrite($handle, $create[1] . ";\n\n");

			$total = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');
			$offset = 0;
			$batch = 500;

			while ($offset < $total) {
				$rows = $wpdb->get_results(
					'SELECT * FROM `' . str_replace('`', '``', $table) . '` LIMIT ' . (int) $offset . ', ' . (int) $batch,
					ARRAY_A
				);

				if (empty($rows)) {
					break;
				}

				self::writeRows($handle, $table, $rows);
				$offset += count($rows);

				if (is_callable($progress)) {
					call_user_func($progress, $table, $offset, $total);
				}
			}

			fwrite($handle, "\n");
		}

		fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
		fclose($handle);

		return true;
	}

	protected static function writeRows($handle, $table, $rows) {
		$columns = array_keys($rows[0]);
		$columnSql = '`' . implode('`, `', array_map(function ($column) {
			return str_replace('`', '``', $column);
		}, $columns)) . '`';

		$values = array();

		foreach ($rows as $row) {
			$items = array();

			foreach ($columns as $column) {
				$value = $row[$column];

				if ($value === null) {
					$items[] = 'NULL';
				} else {
					$items[] = "'" . esc_sql((string) $value) . "'";
				}
			}

			$values[] = '(' . implode(', ', $items) . ')';
		}

		fwrite(
			$handle,
			'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . $columnSql . ') VALUES' . "\n"
			. implode(",\n", $values) . ";\n"
		);
	}

	public static function countStatements($file) {
		$count = 0;

		foreach (self::statements($file) as $statement) {
			$count++;
		}

		return $count;
	}

	public static function import($file, $progress = null) {
		global $wpdb;

		if (!is_file($file)) {
			throw new \RuntimeException('Файл дампа не найден: ' . $file);
		}

		$wpdb->query('SET FOREIGN_KEY_CHECKS=0');

		foreach (self::tables() as $table) {
			$wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
		}

		$count = 0;

		foreach (self::statements($file) as $statement) {
			$result = $wpdb->query($statement);

			if ($result === false) {
				throw new \RuntimeException(
					'Ошибка импорта SQL: ' . $wpdb->last_error . ' | ' . substr($statement, 0, 300)
				);
			}

			$count++;

			if (is_callable($progress) && ($count % 100 === 0)) {
				call_user_func($progress, $count, 0);
			}
		}

		if (is_callable($progress)) {
			call_user_func($progress, $count, 0);
		}

		$wpdb->query('SET FOREIGN_KEY_CHECKS=1');

		wp_cache_flush();

		return $count;
	}

	public static function statements($file) {
		$handle = fopen($file, 'rb');

		if (!$handle) {
			throw new \RuntimeException('Не удалось прочитать файл дампа: ' . $file);
		}

		$current = '';
		$inString = false;
		$quote = '';
		$escape = false;
		$skipLine = false;
		$skipBlock = false;

		while (!feof($handle)) {
			$chunk = fread($handle, 65536);

			if ($chunk === false) {
				break;
			}

			$length = strlen($chunk);

			for ($i = 0; $i < $length; $i++) {
				$char = $chunk[$i];
				$next = ($i + 1 < $length) ? $chunk[$i + 1] : '';

				if ($skipLine) {
					if ($char === "\n") {
						$skipLine = false;
						$current .= "\n";
					}
					continue;
				}

				if ($skipBlock) {
					if ($char === '*' && $next === '/') {
						$skipBlock = false;
						$i++;
					}
					continue;
				}

				if ($inString) {
					$current .= $char;

					if ($escape) {
						$escape = false;
						continue;
					}

					if ($char === '\\') {
						$escape = true;
						continue;
					}

					if ($char === $quote) {
						if ($next === $quote) {
							$current .= $next;
							$i++;
							continue;
						}

						$inString = false;
					}

					continue;
				}

				if ($char === '-' && $next === '-') {
					$skipLine = true;
					$i++;
					continue;
				}

				if ($char === '#') {
					$skipLine = true;
					continue;
				}

				if ($char === '/' && $next === '*') {
					$skipBlock = true;
					$i++;
					continue;
				}

				if ($char === "'" || $char === '"') {
					$inString = true;
					$quote = $char;
					$current .= $char;
					continue;
				}

				if ($char === ';') {
					$statement = trim($current);

					if ($statement !== '') {
						yield $statement;
					}

					$current = '';
					continue;
				}

				$current .= $char;
			}
		}

		fclose($handle);

		$statement = trim($current);

		if ($statement !== '') {
			yield $statement;
		}
	}
}
