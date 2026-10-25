=== OneSpace Toolkit ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv3 or later

Перенос сайта, SQL-бэкапы, транслитерация слагов и исправление Carbon Fields.

== Что делает плагин ==

1. Экспорт сайта.
   Создаёт один архив .pomigrate. Можно выбрать: база данных, медиатека,
   темы, плагины, остальное в wp-content. Плюс отдельные include/exclude пути.
   Показывает прогресс по таблицам и файлам в админке.

2. Импорт сайта.
   Загружает .pomigrate. Если в архиве есть база — заменяет её, делает SQL-бэкап
   старой базы, меняет URL (включая serialized-данные) и обновляет siteurl/home.
   Если базы нет — заменяет только файлы и не трогает БД.

3. SQL-бэкап.
   Отдельный дамп только базы данных. Быстро перед обновлениями.

4. Транслитерация.
   Автоматически делает латинские слаги: «Тестовая запись» → testovaya-zapis.
   Работает для записей, страниц, товаров, рубрик и произвольных типов.

5. Carbon Fields.
   Исправляет загрузку стилей в iframe Gutenberg и русские переводы интерфейса.
   Нужен для сайтов, где Carbon Fields ещё не обновлён.

== Имена файлов ==

Файлы называются по домену и дате:

  forestsnab.local-2026-10-25_17-30-00.pomigrate
  forestsnab.local-2026-10-25_17-30-00.sql
  forestsnab.local-pre-import-2026-10-25_17-31-00.sql

== Что экспортировать ==

В админке на карточке «Экспорт сайта» есть чекбоксы:

- База данных;
- Медиатека (uploads);
- Темы (themes);
- Плагины (plugins);
- Остальное в wp-content (languages и т.д.).

В блоке «Дополнительно» можно указать отдельные папки и файлы:

- include_paths — что включить;
- exclude_paths — что исключить.

Пути пишутся относительно wp-content, например:

  themes/forest-test/style.css
  uploads/2026
  uploads/2025
  plugins/woocommerce

CLI:

  wp onespace migrate export --scopes=database,uploads,themes
  wp onespace migrate export --scopes= --include=themes/forest-test/style.css
  wp onespace migrate export --exclude=uploads/2025

Если база не выбрана, архив содержит только файлы, а импорт не трогает БД.

== Установка одной командой ==

powershell -ExecutionPolicy Bypass -File .\install.ps1 -Path C:\OSPanel\domains\forestsnab.local

== Админка ==

Меню слева → OneSpace Toolkit → Инструменты.

Кнопки:
- «Создать и скачать архив» — экспорт .pomigrate;
- «Создать SQL-бэкап» — дамп базы;
- «Импортировать» — загрузка .pomigrate;
- настройки транслита и Carbon Fields.

Во время работы показывается прогресс-бар: этап, текущая таблица/файл,
сколько обработано.

== Управление бэкапами ==

OneSpace Toolkit → Бэкапы.

На странице видно все файлы .pomigrate, .zip и .sql:
- «Посмотреть файлы» — у каждого бэкапа своя страница: манифест,
  содержимое архива с поиском, кнопки скачивания и восстановления;
- «Скачать» — скачать весь бэкап или отдельный файл из архива;
- «Восстановить» — заменить файл на версию из архива (текущая версия
  сохраняется в onespace-toolkit-backups/restore-backup/);
- «Удалить» — удалить бэкап.

== WP-CLI ==

wp onespace migrate export
wp onespace migrate export --output=backup.pomigrate --force
wp onespace migrate backup
wp onespace migrate import backup.pomigrate --target-url=https://new-domain.ru --yes
wp onespace migrate replace https://old-domain.ru https://new-domain.ru

Если --output не указан, файлы создаются в
wp-content/onespace-toolkit-backups с именем домена и датой.

== Формат .pomigrate ==

manifest.json + database.sql + files/ (wp-content без cache, upgrade и бэкапов).

== Настройки ==

wp option update onespace_toolkit_translit 0
wp option update onespace_toolkit_cf_fix 0
