# OneSpace Toolkit

WordPress-плагин для переноса сайта, бэкапов, транслитерации слагов и исправления Carbon Fields в Gutenberg.

## Возможности

- **Экспорт сайта** в один архив `.pomigrate`: база данных, медиатека, темы, плагины и остальные файлы `wp-content`.
- **Выбор состава экспорта**: чекбоксы для БД, `uploads`, `themes`, `plugins`, остального, а также include/exclude для отдельных папок и файлов.
- **Импорт**: замена БД и файлов, SQL-бэкап старой базы, замена URL в обычных и serialized-данных.
- **Файловый импорт**: если в архиве нет базы, импортируются только файлы, БД и URL не меняются.
- **Управление бэкапами**: отдельная страница со списком, содержимым архивов, скачиванием, восстановлением отдельного файла и массовым удалением.
- **Прогресс**: этапы и счётчики для экспорта, SQL-бэкапа и импорта.
- **Проверка диска**: экспорт не стартует при нехватке места, одновременно можно запускать только один экспорт.
- **Незавершённые экспорты** видны на странице «Бэкапы» с предупреждением и кнопкой удаления.
- **Транслитерация слагов**: `Тестовая запись` → `testovaya-zapis`.
- **Carbon Fields**: исправление стилей в iframe Gutenberg и русских переводов интерфейса.

## Требования

- WordPress 6.0+
- PHP 7.4+
- Расширение PHP `zip`

## Установка одной командой

```powershell
powershell -ExecutionPolicy Bypass -File .\install.ps1 -Path C:\path\to\wordpress
```

Скрипт собирает архив плагина и устанавливает его через WP-CLI. Если `wp-cli.phar` не передан, он скачивается во временную папку.

Можно указать свой WP-CLI:

```powershell
powershell -ExecutionPolicy Bypass -File .\install.ps1 -Path C:\path\to\wordpress -WpCli C:\tools\wp-cli.phar
```

## Админка

После установки в меню слева появляется **OneSpace Toolkit**:

- **Инструменты** — экспорт, SQL-бэкап, импорт и настройки.
- **Бэкапы** — список файлов, просмотр содержимого архива, скачивание, восстановление файла, удаление и массовый выбор.

## Экспорт

В админке можно выбрать:

- База данных
- Медиатека `uploads`
- Темы `themes`
- Плагины `plugins`
- Остальное в `wp-content`

Дополнительно можно указать пути относительно `wp-content`:

- `include_paths` — что включить;
- `exclude_paths` — что исключить.

Примеры:

```text
themes/my-theme/style.css
uploads/2026
uploads/2025
plugins/woocommerce
```

## Если мало места на диске

Перед экспортом плагин проверяет свободное место и не создаёт архив, если места заведомо не хватает. Если экспорт всё же прервался, файл появится на странице «Бэкапы» как **Незавершённый** — его можно удалить.

При нехватке места экспортируйте по частям:

1. База данных.
2. Медиатека `uploads`.
3. Темы, плагины и остальное.

Импортировать можно несколькими архивами: сначала архив с базой, затем файловые архивы — они не трогают БД.

## WP-CLI

```bash
wp onespace migrate export
wp onespace migrate export --scopes=database,uploads,themes
wp onespace migrate export --scopes= --include=themes/my-theme/style.css
wp onespace migrate export --exclude=uploads/2025
wp onespace migrate backup
wp onespace migrate import backup.pomigrate --target-url=https://new-domain.ru --yes
wp onespace migrate replace https://old-domain.ru https://new-domain.ru
```

По умолчанию файлы создаются в `wp-content/onespace-toolkit-backups` с именем домена и датой:

```text
example.com-2026-10-25_17-30-00.pomigrate
example.com-2026-10-25_17-30-00.sql
example.com-pre-import-2026-10-25_17-31-00.sql
```

## Лицензия

GPL-3.0-or-later.
