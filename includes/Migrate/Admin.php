<?php

namespace OneSpace\Toolkit\Migrate;

if (!defined('ABSPATH')) {
	exit;
}

class Admin {
	public static function boot() {
		add_action('admin_menu', array(__CLASS__, 'menu'));
		add_action('admin_post_onespace_toolkit_export', array(__CLASS__, 'handleExport'));
		add_action('admin_post_onespace_toolkit_backup', array(__CLASS__, 'handleBackup'));
		add_action('admin_post_onespace_toolkit_import', array(__CLASS__, 'handleImport'));
		add_action('admin_post_onespace_toolkit_settings', array(__CLASS__, 'handleSettings'));
		add_action('admin_post_onespace_toolkit_download', array(__CLASS__, 'download'));
		add_action('admin_post_onespace_toolkit_download_entry', array(__CLASS__, 'downloadEntry'));
		add_action('admin_post_onespace_toolkit_delete_backup', array(__CLASS__, 'handleDeleteBackup'));
		add_action('admin_post_onespace_toolkit_bulk_backups', array(__CLASS__, 'handleBulkBackups'));
		add_action('admin_post_onespace_toolkit_restore_file', array(__CLASS__, 'handleRestoreFile'));
		add_action('wp_ajax_onespace_toolkit_export_start', array(__CLASS__, 'ajaxExport'));
		add_action('wp_ajax_onespace_toolkit_backup_start', array(__CLASS__, 'ajaxBackup'));
		add_action('wp_ajax_onespace_toolkit_import_start', array(__CLASS__, 'ajaxImport'));
		add_action('wp_ajax_onespace_toolkit_job', array(__CLASS__, 'ajaxJob'));
		add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
	}

	public static function assets($hook) {
		$allowed = array(
			'toplevel_page_onespace-toolkit',
			'onespace-toolkit_page_onespace-toolkit-backups',
			'onespace-toolkit_page_onespace-toolkit-backup',
			'tools_page_onespace-toolkit',
			'tools_page_onespace-toolkit-backups',
			'tools_page_onespace-toolkit-backup',
		);

		if (!in_array($hook, $allowed, true)) {
			return;
		}

		$cssFile = ONESPACE_TOOLKIT_DIR . 'assets/admin.css';
		$jsFile = ONESPACE_TOOLKIT_DIR . 'assets/admin.js';

		wp_enqueue_style(
			'onespace-toolkit-admin',
			ONESPACE_TOOLKIT_URL . 'assets/admin.css',
			array(),
			file_exists($cssFile) ? filemtime($cssFile) : ONESPACE_TOOLKIT_VERSION
		);

		wp_enqueue_script(
			'onespace-toolkit-admin',
			ONESPACE_TOOLKIT_URL . 'assets/admin.js',
			array(),
			file_exists($jsFile) ? filemtime($jsFile) : ONESPACE_TOOLKIT_VERSION,
			true
		);

		wp_localize_script('onespace-toolkit-admin', 'onespaceToolkit', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'adminUrl' => admin_url('admin.php'),
			'nonce' => wp_create_nonce('onespace_toolkit_ajax'),
		));
	}

	public static function menu() {
		add_menu_page(
			'OneSpace Toolkit',
			'OneSpace Toolkit',
			'manage_options',
			'onespace-toolkit',
			array(__CLASS__, 'render'),
			'dashicons-backup',
			80
		);

		add_submenu_page(
			'onespace-toolkit',
			'Инструменты',
			'Инструменты',
			'manage_options',
			'onespace-toolkit',
			array(__CLASS__, 'render')
		);

		add_submenu_page(
			'onespace-toolkit',
			'Бэкапы',
			'Бэкапы',
			'manage_options',
			'onespace-toolkit-backups',
			array(__CLASS__, 'renderBackups')
		);

		add_submenu_page(
			'onespace-toolkit',
			'Бэкап',
			'Бэкап',
			'manage_options',
			'onespace-toolkit-backup',
			array(__CLASS__, 'renderBackupDetail')
		);
		remove_submenu_page('onespace-toolkit', 'onespace-toolkit-backup');
	}

	public static function render() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$notice = isset($_GET['onespace_notice']) ? sanitize_key($_GET['onespace_notice']) : '';
		$message = isset($_GET['onespace_message']) ? sanitize_text_field(wp_unslash($_GET['onespace_message'])) : '';

		$archiveExample = Plugin::defaultArchiveName();
		$backupExample = Plugin::defaultBackupName();

		$backupDir = Plugin::backup_dir();
		$archives = array_merge(
			glob($backupDir . '/*.pomigrate') ?: array(),
			glob($backupDir . '/*.zip') ?: array(),
			glob($backupDir . '/*.sql') ?: array(),
			glob($backupDir . '/*.pomigrate.*') ?: array(),
			glob($backupDir . '/*.zip.*') ?: array()
		);
		$archives = array_values(array_unique($archives));
		usort($archives, function ($a, $b) {
			return filemtime($b) <=> filemtime($a);
		});

		$incompleteFiles = array_values(array_filter($archives, function ($file) {
			return self::isIncompleteName(basename($file));
		}));

		$archives = array_slice($archives, 0, 10);
		?>
		<div class="wrap onespace-toolkit">
			<h1>OneSpace Toolkit</h1>
			<p class="onespace-toolkit__lead">
				Перенос сайта, SQL-бэкапы, транслитерация слагов и исправление Carbon Fields для Gutenberg.
			</p>

			<p>
				<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=onespace-toolkit-backups')); ?>">Управление бэкапами</a>
			</p>

			<?php if ($notice === 'imported') : ?>
				<div class="notice notice-success"><p>Импорт завершён. <?php echo esc_html($message); ?></p></div>
			<?php elseif ($notice === 'error') : ?>
				<div class="notice notice-error"><p><?php echo esc_html($message); ?></p></div>
			<?php elseif ($notice === 'saved') : ?>
				<div class="notice notice-success"><p>Настройки сохранены.</p></div>
			<?php endif; ?>

			<?php if (!empty($incompleteFiles)) : ?>
				<div class="notice notice-warning">
					<p>
						Есть незавершённые экспорты: <strong><?php echo count($incompleteFiles); ?></strong>.
						<a href="<?php echo esc_url(admin_url('admin.php?page=onespace-toolkit-backups')); ?>">Открыть бэкапы</a>
					</p>
				</div>
			<?php endif; ?>

			<div id="onespace-progress" class="onespace-progress" hidden>
				<div class="onespace-progress__head">Выполняется</div>
				<div class="onespace-progress__bar"><span></span></div>
				<p class="onespace-progress__text">Подготовка…</p>
			</div>

			<div class="onespace-toolkit__grid">
				<div class="card">
					<h2>Экспорт сайта</h2>
					<p>Создаёт один файл <code>.pomigrate</code>: база данных + <code>wp-content</code> без кеша и старых бэкапов. Подходит для переноса на другой домен или хостинг.</p>
					<p class="description">Имя файла: <code><?php echo esc_html($archiveExample); ?></code></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-job-action="export">
						<?php wp_nonce_field('onespace_toolkit_export'); ?>
						<input type="hidden" name="action" value="onespace_toolkit_export">

						<fieldset class="onespace-toolkit__scopes">
							<legend>Что экспортировать</legend>
							<label><input type="checkbox" name="scopes[]" value="database" checked> База данных</label>
							<label><input type="checkbox" name="scopes[]" value="uploads" checked> Медиатека <code>uploads</code></label>
							<label><input type="checkbox" name="scopes[]" value="themes" checked> Темы <code>themes</code></label>
							<label><input type="checkbox" name="scopes[]" value="plugins" checked> Плагины <code>plugins</code></label>
							<label><input type="checkbox" name="scopes[]" value="other" checked> Остальное в <code>wp-content</code></label>
						</fieldset>

						<details class="onespace-toolkit__advanced">
							<summary>Дополнительно: отдельные папки и файлы</summary>
							<p>
								<label for="onespace-include-paths">Включить пути (по одному в строке, относительно <code>wp-content</code>)</label>
								<textarea id="onespace-include-paths" name="include_paths" rows="3" class="large-text code" placeholder="themes/forest-test/style.css&#10;uploads/2026"></textarea>
							</p>
							<p>
								<label for="onespace-exclude-paths">Исключить пути</label>
								<textarea id="onespace-exclude-paths" name="exclude_paths" rows="3" class="large-text code" placeholder="uploads/2025&#10;themes/forest-test/tests"></textarea>
							</p>
							<p class="description">Можно указывать папки и отдельные файлы. Пути можно писать с <code>wp-content/</code> или без.</p>
						</details>

						<?php submit_button('Создать и скачать архив', 'primary', 'submit', false); ?>
					</form>
				</div>

				<div class="card">
					<h2>Бэкап базы данных</h2>
					<p>Отдельный SQL-дамп только базы. Быстро и удобно перед обновлениями или ручными правками.</p>
					<p class="description">Имя файла: <code><?php echo esc_html($backupExample); ?></code></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-job-action="backup">
						<?php wp_nonce_field('onespace_toolkit_backup'); ?>
						<input type="hidden" name="action" value="onespace_toolkit_backup">
						<?php submit_button('Создать SQL-бэкап', 'secondary', 'submit', false); ?>
					</form>
				</div>

				<div class="card">
					<h2>Импорт сайта</h2>
					<p>Загружает <code>.pomigrate</code>. Если в архиве есть база — заменяет её и меняет URL, если нет — только файлы.</p>
					<p class="description">Если в архиве есть база, перед импортом создаётся SQL-бэкап <code><?php echo esc_html(Plugin::siteSlug()); ?>-pre-import-…sql</code>. После импорта базы может потребоваться повторный вход в админку.</p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" data-job-action="import">
						<?php wp_nonce_field('onespace_toolkit_import'); ?>
						<input type="hidden" name="action" value="onespace_toolkit_import">
						<p><input type="file" name="archive" accept=".pomigrate,.zip" required></p>
						<p>
							<label for="onespace-url">URL сайта после импорта</label>
							<input type="url" class="regular-text" id="onespace-url" name="url" value="<?php echo esc_attr(home_url()); ?>">
						</p>
						<?php submit_button('Импортировать', 'primary', 'submit', false, array('onclick' => "return confirm('Импорт заменит данные из архива (базу и/или файлы). Продолжить?');")); ?>
					</form>
				</div>

				<div class="card">
					<h2>Настройки</h2>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('onespace_toolkit_settings'); ?>
						<input type="hidden" name="action" value="onespace_toolkit_settings">
						<p>
							<label>
								<input type="checkbox" name="translit" value="1" <?php checked(get_option('onespace_toolkit_translit', '1'), '1'); ?>>
								Транслитерация слагов: <code>Тестовая запись</code> → <code>testovaya-zapis</code>
							</label>
						</p>
						<p>
							<label>
								<input type="checkbox" name="cf_fix" value="1" <?php checked(get_option('onespace_toolkit_cf_fix', '1'), '1'); ?>>
								Carbon Fields: стили и русские переводы в Gutenberg
							</label>
						</p>
						<?php submit_button('Сохранить', 'secondary', 'submit', false); ?>
					</form>
				</div>
			</div>

			<div class="card onespace-toolkit__about">
				<h2>Что делает плагин</h2>
				<ul>
					<li><strong>Экспорт</strong> — собирает БД и файлы в один <code>.pomigrate</code>, показывает прогресс по таблицам и файлам.</li>
					<li><strong>Импорт</strong> — разворачивает архив, заменяет URL (включая serialized-данные) и сохраняет SQL-бэкап старой базы.</li>
					<li><strong>SQL-бэкап</strong> — отдельный дамп базы с именем домена и датой.</li>
					<li><strong>Транслитерация</strong> — автоматически делает латинские слаги для записей, страниц, товаров и терминов.</li>
					<li><strong>Carbon Fields</strong> — чинит загрузку стилей в iframe Gutenberg и русские переводы интерфейса.</li>
				</ul>
			</div>

			<h2>Последние файлы</h2>
			<?php if (empty($archives)) : ?>
				<p>Файлов пока нет.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Файл</th>
							<th>Размер</th>
							<th>Дата</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($archives as $archive) : ?>
							<tr>
								<td><?php echo esc_html(basename($archive)); ?></td>
								<td><?php echo esc_html(size_format(filesize($archive))); ?></td>
								<td><?php echo esc_html(wp_date('Y-m-d H:i', filemtime($archive))); ?></td>
								<td><a href="<?php echo esc_url(self::downloadUrl(basename($archive))); ?>">Скачать</a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function renderBackups() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$notice = isset($_GET['onespace_notice']) ? sanitize_key($_GET['onespace_notice']) : '';
		$message = isset($_GET['onespace_message']) ? sanitize_text_field(wp_unslash($_GET['onespace_message'])) : '';
		$view = isset($_GET['view']) ? sanitize_file_name(wp_unslash($_GET['view'])) : '';
		$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
		$paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
		?>
		<div class="wrap onespace-toolkit">
			<h1><span class="dashicons dashicons-backup"></span> Бэкапы OneSpace</h1>
			<p class="onespace-toolkit__lead">
				Все архивы сайта и SQL-дампы. Откройте файл, чтобы посмотреть содержимое, скачать или восстановить отдельный файл.
			</p>
			<p>
				<a href="<?php echo esc_url(admin_url('admin.php?page=onespace-toolkit')); ?>">← Назад к инструментам</a>
			</p>

			<?php if ($notice === 'deleted') : ?>
				<div class="notice notice-success"><p>Файл удалён: <?php echo esc_html($message); ?></p></div>
			<?php elseif ($notice === 'bulk_deleted') : ?>
				<div class="notice notice-success"><p><?php echo esc_html($message); ?></p></div>
			<?php elseif ($notice === 'restored') : ?>
				<div class="notice notice-success"><p>Файл восстановлен: <?php echo esc_html($message); ?></p></div>
			<?php elseif ($notice === 'error') : ?>
				<div class="notice notice-error"><p><?php echo esc_html($message); ?></p></div>
			<?php endif; ?>

			<?php
			if ($view !== '') {
				self::renderArchiveView($view, $search, $paged);
			} else {
				self::renderBackupList();
			}
			?>
		</div>
		<?php
	}

	public static function renderBackupDetail() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$file = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';
		$search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
		$paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
		$notice = isset($_GET['onespace_notice']) ? sanitize_key($_GET['onespace_notice']) : '';
		$message = isset($_GET['onespace_message']) ? sanitize_text_field(wp_unslash($_GET['onespace_message'])) : '';
		$path = self::resolveBackupPath($file);
		$isIncomplete = self::isIncompleteName($file);
		$isArchive = self::isArchiveName($file);
		$listUrl = admin_url('admin.php?page=onespace-toolkit-backups');
		?>
		<div class="wrap onespace-toolkit">
			<h1><span class="dashicons dashicons-backup"></span> Бэкап: <code><?php echo esc_html($file); ?></code></h1>
			<p class="onespace-toolkit__back">
				<a href="<?php echo esc_url($listUrl); ?>">← Ко всем бэкапам</a>
			</p>

			<?php if ($notice === 'restored') : ?>
				<div class="notice notice-success"><p>Файл восстановлен: <?php echo esc_html($message); ?></p></div>
			<?php elseif ($notice === 'error') : ?>
				<div class="notice notice-error"><p><?php echo esc_html($message); ?></p></div>
			<?php endif; ?>

			<?php if (!$path) : ?>
				<?php self::renderBackupError('Файл не найден.'); ?>
				<?php return; ?>
			<?php endif; ?>

			<?php if ($isIncomplete) : ?>
				<div class="notice notice-warning">
					<p>Экспорт не завершён. Скорее всего, закончилось место на диске или процесс был прерван. Импортировать этот файл нельзя — его нужно удалить.</p>
				</div>
			<?php endif; ?>

			<div class="card onespace-toolkit__summary">
				<div class="onespace-toolkit__summary-head">
					<div>
						<span class="onespace-toolkit__badge <?php echo $isIncomplete ? 'is-warning' : ($isArchive ? 'is-archive' : 'is-sql'); ?>">
							<?php echo $isIncomplete ? 'Незавершённый' : ($isArchive ? 'Архив сайта' : 'База данных'); ?>
						</span>
						<h2>Информация о файле</h2>
					</div>
					<div class="onespace-toolkit__toolbar">
						<a class="button button-primary" href="<?php echo esc_url(self::downloadUrl($file)); ?>">Скачать</a>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="onespace-toolkit__inline" onsubmit="return confirm('Удалить файл?');">
							<?php wp_nonce_field('onespace_toolkit_delete_backup'); ?>
							<input type="hidden" name="action" value="onespace_toolkit_delete_backup">
							<input type="hidden" name="file" value="<?php echo esc_attr($file); ?>">
							<button type="submit" class="button button-link-delete">Удалить</button>
						</form>
					</div>
				</div>

				<dl class="onespace-toolkit__meta">
					<div>
						<dt>Файл</dt>
						<dd><code><?php echo esc_html($file); ?></code></dd>
					</div>
					<div>
						<dt>Размер</dt>
						<dd><?php echo esc_html(size_format(filesize($path))); ?></dd>
					</div>
					<div>
						<dt>Дата</dt>
						<dd><?php echo esc_html(wp_date('Y-m-d H:i:s', filemtime($path))); ?></dd>
					</div>
					<div>
						<dt>Путь</dt>
						<dd><code><?php echo esc_html($path); ?></code></dd>
					</div>
					<?php if ($isArchive && !$isIncomplete) : ?>
						<?php $info = self::archiveInfo($path); ?>
						<div>
							<dt>Файлов внутри</dt>
							<dd><?php echo (int) $info['files']; ?></dd>
						</div>
					<?php endif; ?>
				</dl>
			</div>

			<?php if ($isIncomplete) : ?>
				<div class="card onespace-toolkit__summary">
					<h2>Что делать</h2>
					<p>Удалите незавершённый файл и запустите экспорт с меньшим составом или освободите место на диске. Если места мало, экспортируйте по частям: отдельно базу, отдельно медиатеку, отдельно темы и плагины.</p>
					<p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=onespace-toolkit')); ?>">Перейти к экспорту</a></p>
				</div>
			<?php elseif ($isArchive) : ?>
				<?php self::renderManifestSummary($path); ?>

				<div class="onespace-toolkit__section-head">
					<h2>Содержимое архива</h2>
					<span class="description">Можно скачать или восстановить отдельный файл.</span>
				</div>

				<?php
				self::renderArchiveView($file, $search, $paged, array(
					'page_slug' => 'onespace-toolkit-backup',
					'file_param' => 'file',
					'show_header' => false,
					'show_back' => false,
				));
				?>
			<?php else : ?>
				<?php self::renderSqlPreview($path); ?>
				<p>
					<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=onespace-toolkit')); ?>">Перейти к импорту</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	protected static function renderManifestSummary($path) {
		if (!class_exists('ZipArchive')) {
			return;
		}

		$zip = new \ZipArchive();

		if ($zip->open($path) !== true) {
			return;
		}

		$json = $zip->getFromName('manifest.json');
		$zip->close();

		if ($json === false) {
			return;
		}

		$manifest = json_decode($json, true);

		if (!is_array($manifest)) {
			return;
		}

		$rows = array(
			'Сайт' => $manifest['site_url'] ?? '',
			'Home' => $manifest['home_url'] ?? '',
			'WordPress' => $manifest['wp_version'] ?? '',
			'Создан' => $manifest['created_at'] ?? '',
			'Префикс таблиц' => $manifest['table_prefix'] ?? '',
			'Тема' => $manifest['stylesheet'] ?? '',
			'Таблиц' => isset($manifest['tables']) ? count((array) $manifest['tables']) : 0,
			'Активных плагинов' => isset($manifest['active_plugins']) ? count((array) $manifest['active_plugins']) : 0,
		);
		?>
		<div class="card onespace-toolkit__summary onespace-toolkit__manifest">
			<h2>Манифест архива</h2>
			<dl class="onespace-toolkit__meta">
				<?php foreach ($rows as $label => $value) : ?>
					<div>
						<dt><?php echo esc_html($label); ?></dt>
						<dd><?php echo esc_html((string) $value); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		</div>
		<?php
	}

	protected static function renderSqlPreview($path) {
		$handle = fopen($path, 'rb');

		if (!$handle) {
			return;
		}

		$lines = array();

		for ($i = 0; $i < 20 && !feof($handle); $i++) {
			$line = fgets($handle);

			if ($line === false) {
				break;
			}

			$lines[] = $line;
		}

		fclose($handle);
		?>
		<div class="card onespace-toolkit__summary">
			<h2>Начало SQL-дампа</h2>
			<pre class="onespace-toolkit__preview"><?php echo esc_html(implode('', $lines)); ?></pre>
			<p class="description">Показаны первые 20 строк. Полный дамп — по кнопке «Скачать».</p>
		</div>
		<?php
	}

	protected static function renderBackupList() {
		$files = self::backupFiles();
		$dir = Plugin::backup_dir();
		$archiveCount = 0;
		$sqlCount = 0;
		$incompleteCount = 0;
		$totalSize = 0;

		foreach ($files as $file) {
			$name = basename($file);

			if (self::isIncompleteName($name)) {
				$incompleteCount++;
			} elseif (self::isArchiveName($name)) {
				$archiveCount++;
			} else {
				$sqlCount++;
			}

			$totalSize += filesize($file);
		}
		?>
		<div class="onespace-toolkit__stats">
			<div class="onespace-toolkit__stat">
				<span>Архивов сайта</span>
				<strong><?php echo (int) $archiveCount; ?></strong>
			</div>
			<div class="onespace-toolkit__stat">
				<span>SQL-дампов</span>
				<strong><?php echo (int) $sqlCount; ?></strong>
			</div>
			<div class="onespace-toolkit__stat">
				<span>Общий размер</span>
				<strong><?php echo esc_html(size_format($totalSize)); ?></strong>
			</div>
			<?php if ($incompleteCount > 0) : ?>
				<div class="onespace-toolkit__stat is-warning">
					<span>Незавершённые</span>
					<strong><?php echo (int) $incompleteCount; ?></strong>
				</div>
			<?php endif; ?>
		</div>

		<div class="onespace-toolkit__list-head">
			<span>Папка: <code><?php echo esc_html($dir); ?></code></span>
			<div class="onespace-toolkit__list-actions">
				<form id="onespace-bulk-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="onespace-toolkit__bulk">
					<?php wp_nonce_field('onespace_toolkit_bulk_backups'); ?>
					<input type="hidden" name="action" value="onespace_toolkit_bulk_backups">
					<select name="bulk_action">
						<option value="">Действия</option>
						<option value="delete">Удалить выбранные</option>
					</select>
					<button type="submit" class="button" onclick="return confirm('Удалить выбранные файлы?');">Применить</button>
				</form>
				<a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=onespace-toolkit')); ?>">Создать новый бэкап</a>
			</div>
		</div>

		<?php if (empty($files)) : ?>
			<p>Бэкапов пока нет.</p>
		<?php else : ?>
			<table class="widefat striped onespace-toolkit__table">
				<thead>
					<tr>
						<th class="onespace-toolkit__check">
							<input type="checkbox" id="onespace-select-all" aria-label="Выбрать все">
						</th>
						<th>Файл</th>
						<th>Тип</th>
						<th>Размер</th>
						<th>Содержимое</th>
						<th>Дата</th>
						<th>Действия</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($files as $file) : ?>
						<?php
						$name = basename($file);
						$isIncomplete = self::isIncompleteName($name);
						$isArchive = self::isArchiveName($name);
						$info = ($isArchive && !$isIncomplete) ? self::archiveInfo($file) : null;
						$viewUrl = add_query_arg(
							array(
								'page' => 'onespace-toolkit-backup',
								'file' => $name,
							),
							admin_url('admin.php')
						);
						?>
						<tr class="<?php echo $isIncomplete ? 'onespace-toolkit__row-warning' : ''; ?>">
							<td class="onespace-toolkit__check">
								<input type="checkbox" class="onespace-toolkit__file-check" name="files[]" value="<?php echo esc_attr($name); ?>" form="onespace-bulk-form" aria-label="<?php echo esc_attr('Выбрать ' . $name); ?>">
							</td>
							<td><code><?php echo esc_html($name); ?></code></td>
							<td>
								<?php if ($isIncomplete) : ?>
									<span class="onespace-toolkit__badge is-warning">Незавершённый</span>
								<?php elseif ($isArchive) : ?>
									<span class="onespace-toolkit__badge is-archive">Архив сайта</span>
								<?php else : ?>
									<span class="onespace-toolkit__badge is-sql">База данных</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html(size_format(filesize($file))); ?></td>
							<td>
								<?php
								if ($isIncomplete) {
									echo '<span class="description">Экспорт прерван</span>';
								} elseif ($isArchive) {
									echo (int) $info['files'] . ' файлов';
								} else {
									echo 'SQL-дамп';
								}
								?>
							</td>
							<td><?php echo esc_html(wp_date('Y-m-d H:i', filemtime($file))); ?></td>
							<td class="onespace-toolkit__actions">
								<a class="button button-small" href="<?php echo esc_url($viewUrl); ?>"><?php echo ($isArchive && !$isIncomplete) ? 'Посмотреть файлы' : 'Открыть'; ?></a>
								<a class="button button-small" href="<?php echo esc_url(self::downloadUrl($name)); ?>">Скачать</a>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="onespace-toolkit__inline" onsubmit="return confirm('Удалить файл?');">
									<?php wp_nonce_field('onespace_toolkit_delete_backup'); ?>
									<input type="hidden" name="action" value="onespace_toolkit_delete_backup">
									<input type="hidden" name="file" value="<?php echo esc_attr($name); ?>">
									<button type="submit" class="button button-small button-link-delete">Удалить</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	protected static function renderArchiveView($filename, $search, $paged, $args = array()) {
		$args = array_merge(array(
			'base_url' => admin_url('admin.php'),
			'page_slug' => 'onespace-toolkit-backups',
			'file_param' => 'view',
			'show_header' => true,
			'show_back' => true,
		), $args);

		$path = self::resolveBackupPath($filename);

		if (!$path || !class_exists('ZipArchive')) {
			self::renderBackupError('Архив не найден.');
			return;
		}

		$zip = new \ZipArchive();

		if ($zip->open($path) !== true) {
			self::renderBackupError('Не удалось открыть архив.');
			return;
		}

		$entries = array();

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			$name = $stat['name'];

			if ($name === '' || substr($name, -1) === '/') {
				continue;
			}

			if ($name !== 'manifest.json' && $name !== 'database.sql' && strpos($name, 'files/') !== 0) {
				continue;
			}

			if ($search !== '' && stripos($name, $search) === false) {
				continue;
			}

			$entries[] = array(
				'name' => $name,
				'size' => (int) $stat['size'],
			);
		}

		usort($entries, function ($a, $b) {
			$priority = array('manifest.json' => 0, 'database.sql' => 1);
			$pa = $priority[$a['name']] ?? 2;
			$pb = $priority[$b['name']] ?? 2;

			if ($pa !== $pb) {
				return $pa <=> $pb;
			}

			return strnatcasecmp($a['name'], $b['name']);
		});

		$total = count($entries);
		$perPage = 100;
		$totalPages = max(1, (int) ceil($total / $perPage));
		$paged = min(max(1, $paged), $totalPages);
		$offset = ($paged - 1) * $perPage;
		$pageEntries = array_slice($entries, $offset, $perPage);
		$backUrl = add_query_arg('page', 'onespace-toolkit-backups', admin_url('admin.php'));
		?>
		<?php if ($args['show_back']) : ?>
			<p>
				<a href="<?php echo esc_url($backUrl); ?>">← Ко всем бэкапам</a>
			</p>
		<?php endif; ?>
		<?php if ($args['show_header']) : ?>
			<h2>Архив: <code><?php echo esc_html(basename($path)); ?></code></h2>
		<?php endif; ?>
		<p>
			Всего файлов в архиве: <strong><?php echo (int) $total; ?></strong>.
			Показано: <?php echo (int) ($total > 0 ? $offset + 1 : 0); ?>–<?php echo (int) min($offset + $perPage, $total); ?>.
		</p>

		<form method="get" action="<?php echo esc_url($args['base_url']); ?>" class="onespace-toolkit__search">
			<input type="hidden" name="page" value="<?php echo esc_attr($args['page_slug']); ?>">
			<input type="hidden" name="<?php echo esc_attr($args['file_param']); ?>" value="<?php echo esc_attr($filename); ?>">
			<input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Путь к файлу, например themes/">
			<?php submit_button('Искать', 'secondary', 'submit', false); ?>
		</form>

		<?php if (empty($pageEntries)) : ?>
			<p>Файлы не найдены.</p>
		<?php else : ?>
			<table class="widefat striped onespace-toolkit__table">
				<thead>
					<tr>
						<th>Файл в архиве</th>
						<th>Размер</th>
						<th>Действия</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($pageEntries as $entry) : ?>
						<?php
						$isFile = strpos($entry['name'], 'files/') === 0;
						$isSql = $entry['name'] === 'database.sql';
						?>
						<tr>
							<td><code><?php echo esc_html($entry['name']); ?></code></td>
							<td><?php echo esc_html(size_format($entry['size'])); ?></td>
							<td class="onespace-toolkit__actions">
								<a class="button button-small" href="<?php echo esc_url(self::entryDownloadUrl($filename, $entry['name'])); ?>">Скачать</a>
								<?php if ($isFile) : ?>
									<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="onespace-toolkit__inline" onsubmit="return confirm('Заменить файл на версию из архива? Текущая версия будет сохранена в restore-backup.');">
										<?php wp_nonce_field('onespace_toolkit_restore_file'); ?>
										<input type="hidden" name="action" value="onespace_toolkit_restore_file">
										<input type="hidden" name="archive" value="<?php echo esc_attr($filename); ?>">
										<input type="hidden" name="entry" value="<?php echo esc_attr($entry['name']); ?>">
										<button type="submit" class="button button-small button-primary">Восстановить</button>
									</form>
								<?php elseif ($isSql) : ?>
									<span class="description">Для базы используйте импорт</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php
		$base = add_query_arg(
			array(
				'page' => $args['page_slug'],
				$args['file_param'] => $filename,
				's' => $search,
			),
			$args['base_url']
		) . '&paged=%#%';

		$links = paginate_links(array(
			'base' => $base,
			'format' => '',
			'current' => $paged,
			'total' => $totalPages,
			'type' => 'plain',
		));

		if ($links) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . $links . '</div></div>';
		}

		$zip->close();
	}

	protected static function renderBackupError($message) {
		$backUrl = add_query_arg('page', 'onespace-toolkit-backups', admin_url('admin.php'));
		?>
		<div class="notice notice-error"><p><?php echo esc_html($message); ?></p></div>
		<p><a href="<?php echo esc_url($backUrl); ?>">← Ко всем бэкапам</a></p>
		<?php
	}

	public static function handleDeleteBackup() {
		self::guard('onespace_toolkit_delete_backup');

		$file = isset($_POST['file']) ? basename(wp_unslash($_POST['file'])) : '';
		$path = self::resolveBackupPath($file);

		if (!$path) {
			self::redirectBackups('error', 'Файл не найден.');
		}

		if (!@unlink($path)) {
			self::redirectBackups('error', 'Не удалось удалить файл.');
		}

		self::redirectBackups('deleted', $file);
	}

	public static function handleBulkBackups() {
		self::guard('onespace_toolkit_bulk_backups');

		$action = isset($_POST['bulk_action']) ? sanitize_key(wp_unslash($_POST['bulk_action'])) : '';

		if ($action !== 'delete') {
			self::redirectBackups('error', 'Выберите действие.');
		}

		$files = isset($_POST['files']) ? (array) wp_unslash($_POST['files']) : array();

		if (empty($files)) {
			self::redirectBackups('error', 'Файлы не выбраны.');
		}

		$deleted = 0;
		$failed = 0;

		foreach ($files as $file) {
			$file = basename((string) $file);
			$path = self::resolveBackupPath($file);

			if (!$path) {
				$failed++;
				continue;
			}

			if (@unlink($path)) {
				$deleted++;
			} else {
				$failed++;
			}
		}

		$message = 'Удалено файлов: ' . $deleted;

		if ($failed > 0) {
			$message .= ', не удалось: ' . $failed;
		}

		self::redirectBackups($deleted > 0 ? 'bulk_deleted' : 'error', $message);
	}

	public static function handleRestoreFile() {
		self::guard('onespace_toolkit_restore_file');

		@set_time_limit(0);
		wp_raise_memory_limit('admin');

		$archive = isset($_POST['archive']) ? basename(wp_unslash($_POST['archive'])) : '';
		$entry = isset($_POST['entry']) ? self::validateEntry(wp_unslash($_POST['entry'])) : null;
		$path = self::resolveBackupPath($archive);

		if (!$path || !$entry || !class_exists('ZipArchive')) {
			self::redirectBackups('error', 'Некорректный архив или файл.');
		}

		$zip = new \ZipArchive();

		if ($zip->open($path) !== true) {
			self::redirectBackups('error', 'Не удалось открыть архив.');
		}

		$stream = $zip->getStream($entry);

		if (!$stream) {
			$zip->close();
			self::redirectBackups('error', 'Файл не найден в архиве.');
		}

		$relative = ltrim(substr($entry, strlen('files/')), '/');

		if ($relative === '') {
			fclose($stream);
			$zip->close();
			self::redirectBackups('error', 'Некорректный путь файла.');
		}

		$target = WP_CONTENT_DIR . '/' . $relative;
		$dir = dirname($target);

		if (!is_dir($dir)) {
			wp_mkdir_p($dir);
		}

		$base = realpath(WP_CONTENT_DIR);
		$realDir = realpath($dir);

		if (!$base || !$realDir || strpos($realDir, $base) !== 0) {
			fclose($stream);
			$zip->close();
			self::redirectBackups('error', 'Некорректный путь файла.');
		}

		if (is_file($target)) {
			$restore = Plugin::backup_dir() . '/restore-backup/' . Plugin::dateStamp() . '/' . $relative;
			wp_mkdir_p(dirname($restore));
			@copy($target, $restore);
		}

		$out = fopen($target, 'wb');

		if (!$out) {
			fclose($stream);
			$zip->close();
			self::redirectBackups('error', 'Не удалось открыть файл для записи.');
		}

		stream_copy_to_stream($stream, $out);
		fclose($out);
		fclose($stream);
		$zip->close();

		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($target, true);
		}

		self::redirectBackups('restored', $relative);
	}

	public static function downloadEntry() {
		self::guard('onespace_toolkit_download_entry');

		$archive = isset($_GET['archive']) ? basename(wp_unslash($_GET['archive'])) : '';
		$entry = isset($_GET['entry']) ? self::validateEntry(wp_unslash($_GET['entry'])) : null;
		$path = self::resolveBackupPath($archive);

		if (!$path || !$entry || !class_exists('ZipArchive')) {
			wp_die('Файл не найден.');
		}

		$zip = new \ZipArchive();

		if ($zip->open($path) !== true) {
			wp_die('Не удалось открыть архив.');
		}

		$stream = $zip->getStream($entry);

		if (!$stream) {
			$zip->close();
			wp_die('Файл не найден в архиве.');
		}

		$stat = $zip->statName($entry);
		$size = $stat ? (int) $stat['size'] : 0;

		nocache_headers();
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . basename($entry) . '"');

		if ($size > 0) {
			header('Content-Length: ' . $size);
		}

		fpassthru($stream);
		fclose($stream);
		$zip->close();
		exit;
	}

	protected static function backupFiles() {
		$dir = Plugin::backup_dir();
		$files = array_merge(
			glob($dir . '/*.pomigrate') ?: array(),
			glob($dir . '/*.zip') ?: array(),
			glob($dir . '/*.sql') ?: array(),
			glob($dir . '/*.pomigrate.*') ?: array(),
			glob($dir . '/*.zip.*') ?: array()
		);

		$files = array_values(array_unique(array_filter($files, 'is_file')));

		usort($files, function ($a, $b) {
			return filemtime($b) <=> filemtime($a);
		});

		return $files;
	}

	protected static function isArchiveName($name) {
		return (bool) preg_match('/\.(pomigrate|zip)(?:\.[a-z0-9]+)?$/i', $name);
	}

	protected static function isIncompleteName($name) {
		return (bool) preg_match('/\.(pomigrate|zip)\.[a-z0-9]+$/i', $name);
	}

	protected static function archiveInfo($path) {
		$info = array('files' => 0);

		if (!class_exists('ZipArchive')) {
			return $info;
		}

		$zip = new \ZipArchive();

		if ($zip->open($path) !== true) {
			return $info;
		}

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);

			if ($stat && substr($stat['name'], -1) !== '/') {
				$info['files']++;
			}
		}

		$zip->close();

		return $info;
	}

	protected static function resolveBackupPath($file) {
		$file = basename((string) $file);
		$base = realpath(Plugin::backup_dir());
		$path = $base ? realpath($base . '/' . $file) : false;

		if (!$base || !$path || strpos($path, $base) !== 0 || !is_file($path)) {
			return null;
		}

		return $path;
	}

	protected static function validateEntry($entry) {
		$entry = str_replace('\\', '/', (string) $entry);

		if ($entry === '' || strpos($entry, 'files/') !== 0 || strpos($entry, '..') !== false || strpos($entry, "\0") !== false) {
			return null;
		}

		return $entry;
	}

	protected static function entryDownloadUrl($archive, $entry) {
		$url = add_query_arg(
			array(
				'action' => 'onespace_toolkit_download_entry',
				'archive' => rawurlencode($archive),
				'entry' => rawurlencode($entry),
			),
			admin_url('admin-post.php')
		);

		return wp_nonce_url($url, 'onespace_toolkit_download_entry');
	}

	protected static function redirectBackups($notice, $message = '') {
		$url = add_query_arg(
			array(
				'page' => 'onespace-toolkit-backups',
				'onespace_notice' => $notice,
				'onespace_message' => $message,
			),
			admin_url('admin.php')
		);

		wp_safe_redirect($url);
		exit;
	}

	public static function handleExport() {
		self::guard('onespace_toolkit_export');

		@set_time_limit(0);
		wp_raise_memory_limit('admin');

		$filename = Plugin::defaultArchiveName();
		$path = Plugin::backup_dir() . '/' . $filename;

		try {
			Exporter::export($path, array_merge(array('force' => true), self::exportOptions()));
		} catch (\Throwable $e) {
			wp_die(esc_html($e->getMessage()));
		}

		self::stream($path, $filename);
	}

	public static function handleBackup() {
		self::guard('onespace_toolkit_backup');

		@set_time_limit(0);
		wp_raise_memory_limit('admin');

		$filename = Plugin::defaultBackupName();
		$path = Plugin::backup_dir() . '/' . $filename;

		try {
			Database::dump($path);
		} catch (\Throwable $e) {
			wp_die(esc_html($e->getMessage()));
		}

		self::stream($path, $filename);
	}

	public static function handleImport() {
		self::guard('onespace_toolkit_import');

		@set_time_limit(0);
		wp_raise_memory_limit('admin');

		if (empty($_FILES['archive']['tmp_name']) || !is_uploaded_file($_FILES['archive']['tmp_name'])) {
			self::redirect('error', 'Файл не загружен.');
		}

		$name = sanitize_file_name($_FILES['archive']['name']);

		if (!preg_match('/\.(pomigrate|zip)$/i', $name)) {
			self::redirect('error', 'Нужен файл .pomigrate или .zip.');
		}

		$path = Plugin::backup_dir() . '/upload-' . Plugin::siteSlug() . '-' . Plugin::dateStamp() . '-' . $name;

		if (!move_uploaded_file($_FILES['archive']['tmp_name'], $path)) {
			self::redirect('error', 'Не удалось сохранить загруженный файл.');
		}

		$url = !empty($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : home_url();

		try {
			$result = Importer::import($path, array('url' => $url));
		} catch (\Throwable $e) {
			self::redirect('error', $e->getMessage());
		}

		$message = $result['database']
			? 'БД импортирована, SQL: ' . $result['statements'] . ', файлов: ' . $result['files'] . ', замен URL: ' . $result['replaced']
			: 'БД не импортировалась, файлов: ' . $result['files'];

		self::redirect('imported', $message);
	}

	public static function handleSettings() {
		self::guard('onespace_toolkit_settings');

		update_option('onespace_toolkit_translit', empty($_POST['translit']) ? '0' : '1');
		update_option('onespace_toolkit_cf_fix', empty($_POST['cf_fix']) ? '0' : '1');

		self::redirect('saved');
	}

	public static function ajaxExport() {
		self::ajaxGuard();

		@set_time_limit(0);
		wp_raise_memory_limit('admin');

		$job = self::jobFromRequest();
		$filename = Plugin::defaultArchiveName();
		$path = Plugin::backup_dir() . '/' . $filename;

		try {
			$result = Exporter::export($path, array_merge(array(
				'force' => true,
				'progress' => function ($stage, $current, $total, $message = '') use ($job) {
					Job::update($job, self::stageLabel($stage, $message), $current, $total);
				},
			), self::exportOptions()));

			$payload = array(
				'file' => $filename,
				'download' => self::downloadUrl($filename),
				'files' => $result['files'],
				'tables' => $result['tables'],
				'database' => $result['database'],
				'scopes' => $result['scopes'],
			);

			Job::finish($job, $payload);
			wp_send_json_success(array('job' => $job, 'result' => $payload));
		} catch (\Throwable $e) {
			Job::fail($job, $e->getMessage());
			wp_send_json_error(array('message' => $e->getMessage(), 'job' => $job));
		}
	}

	public static function ajaxBackup() {
		self::ajaxGuard();

		@set_time_limit(0);
		wp_raise_memory_limit('admin');

		$job = self::jobFromRequest();
		$filename = Plugin::defaultBackupName();
		$path = Plugin::backup_dir() . '/' . $filename;

		Job::update($job, 'Бэкап БД', 0, 0);

		try {
			Database::dump($path, function ($table, $current, $total) use ($job) {
				Job::update($job, self::stageLabel('backup', $table), $current, $total);
			});

			$payload = array(
				'file' => $filename,
				'download' => self::downloadUrl($filename),
			);

			Job::finish($job, $payload);
			wp_send_json_success(array('job' => $job, 'result' => $payload));
		} catch (\Throwable $e) {
			Job::fail($job, $e->getMessage());
			wp_send_json_error(array('message' => $e->getMessage(), 'job' => $job));
		}
	}

	public static function ajaxImport() {
		self::ajaxGuard();

		@set_time_limit(0);
		wp_raise_memory_limit('admin');

		$job = self::jobFromRequest();

		if (empty($_FILES['archive']['tmp_name']) || !is_uploaded_file($_FILES['archive']['tmp_name'])) {
			Job::fail($job, 'Файл не загружен.');
			wp_send_json_error(array('message' => 'Файл не загружен.', 'job' => $job));
		}

		$name = sanitize_file_name($_FILES['archive']['name']);

		if (!preg_match('/\.(pomigrate|zip)$/i', $name)) {
			Job::fail($job, 'Нужен файл .pomigrate или .zip.');
			wp_send_json_error(array('message' => 'Нужен файл .pomigrate или .zip.', 'job' => $job));
		}

		$path = Plugin::backup_dir() . '/upload-' . Plugin::siteSlug() . '-' . Plugin::dateStamp() . '-' . $name;

		if (!move_uploaded_file($_FILES['archive']['tmp_name'], $path)) {
			Job::fail($job, 'Не удалось сохранить загруженный файл.');
			wp_send_json_error(array('message' => 'Не удалось сохранить загруженный файл.', 'job' => $job));
		}

		$url = !empty($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : home_url();

		try {
			$result = Importer::import($path, array(
				'url' => $url,
				'progress' => function ($stage, $current, $total, $message = '') use ($job) {
					Job::update($job, self::stageLabel($stage, $message), $current, $total);
				},
			));

			Job::finish($job, $result);
			wp_send_json_success(array('job' => $job, 'result' => $result));
		} catch (\Throwable $e) {
			Job::fail($job, $e->getMessage());
			wp_send_json_error(array('message' => $e->getMessage(), 'job' => $job));
		}
	}

	public static function ajaxJob() {
		self::ajaxGuard();

		$job = isset($_GET['job']) ? Job::sanitize(wp_unslash($_GET['job'])) : '';
		$data = Job::get($job);

		if (!$data) {
			wp_send_json_error(array('message' => 'Задача не найдена.'));
		}

		wp_send_json_success($data);
	}

	public static function download() {
		if (!current_user_can('manage_options')) {
			wp_die('Недостаточно прав.');
		}

		check_admin_referer('onespace_toolkit_download');

		$file = isset($_GET['file']) ? basename(rawurldecode(wp_unslash($_GET['file']))) : '';
		$base = realpath(Plugin::backup_dir());
		$path = $base ? realpath($base . '/' . $file) : false;

		if (!$base || !$path || strpos($path, $base) !== 0 || !is_file($path)) {
			wp_die('Файл не найден.');
		}

		self::stream($path, basename($path));
	}

	protected static function exportOptions() {
		$allowed = array('database', 'uploads', 'themes', 'plugins', 'other');
		$scopes = isset($_POST['scopes']) ? (array) wp_unslash($_POST['scopes']) : array();
		$scopes = array_map('sanitize_key', $scopes);
		$scopes = array_values(array_intersect($allowed, $scopes));

		return array(
			'scopes' => $scopes,
			'include_paths' => isset($_POST['include_paths']) ? (string) wp_unslash($_POST['include_paths']) : '',
			'exclude_paths' => isset($_POST['exclude_paths']) ? (string) wp_unslash($_POST['exclude_paths']) : '',
		);
	}

	protected static function guard($action) {
		if (!current_user_can('manage_options')) {
			wp_die('Недостаточно прав.');
		}

		check_admin_referer($action);
	}

	protected static function ajaxGuard() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'Недостаточно прав.'), 403);
		}

		check_ajax_referer('onespace_toolkit_ajax', 'nonce');
	}

	protected static function jobFromRequest() {
		$job = isset($_POST['job']) ? Job::sanitize(wp_unslash($_POST['job'])) : '';

		if ($job === '') {
			$job = 'job-' . wp_generate_password(12, false, false);
		}

		return Job::init($job);
	}

	protected static function stageLabel($stage, $message = '') {
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

		return $label;
	}

	protected static function downloadUrl($filename) {
		$url = add_query_arg(
			array(
				'action' => 'onespace_toolkit_download',
				'file' => rawurlencode($filename),
			),
			admin_url('admin-post.php')
		);

		return wp_nonce_url($url, 'onespace_toolkit_download');
	}

	protected static function stream($path, $filename) {
		nocache_headers();
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . filesize($path));
		readfile($path);
		exit;
	}

	protected static function redirect($notice, $message = '') {
		$url = add_query_arg(
			array(
				'page' => 'onespace-toolkit',
				'onespace_notice' => $notice,
				'onespace_message' => $message,
			),
			admin_url('admin.php')
		);

		wp_safe_redirect($url);
		exit;
	}
}
