# soft2soft/yii2-db-backup

Переиспользуемый модуль бэкапа MySQL/MariaDB для Yii2:
- запуск из web (`POST /dbbackup/backup/start`)
- выполнение в фоне через console worker
- щадящий dump (`--single-transaction --quick --skip-lock-tables`)
- `.sql.gz`, checksum, manifest, lock от параллельного запуска, ротация

## Установка

Добавьте пакет в `composer.json` проекта (path repo или git):

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "extensions/yii2-db-backup"
    }
  ],
  "require": {
    "soft2soft/yii2-db-backup": "*"
  }
}
```

Затем:

```bash
composer update soft2soft/yii2-db-backup
```

## Конфигурация

Подключите модуль в web и console приложениях под ID `dbbackup`.

### Пример (web/console config)

```php
'modules' => [
    'dbbackup' => [
        'class' => soft2soft\yii2dbbackup\Module::class,
        'controllerNamespace' => 'soft2soft\\yii2dbbackup\\web\\controllers',
        'backupDir' => '@runtime/backups/db',
        'dumpBinary' => '', // путь к mariadb-dump/mysqldump, если нужен явный
        'retentionDays' => 14,
        'minFreeSpaceGb' => 2,
        'maxConcurrent' => 1,
        'accessRole' => '@',
        'consoleRoute' => 'dbbackup/backup/run',
    ],
],
```

Для console приложения:

```php
'controllerMap' => [
    'dbbackup/backup' => [
        'class' => soft2soft\yii2dbbackup\console\controllers\BackupController::class,
    ],
],
```

## Хранилище (таблица backup_job)

По умолчанию таблица создается автоматически при первом запуске (`autoCreateTable = true`).

Если хотите управлять схемой только через миграции:
- отключите автосоздание: `'autoCreateTable' => false`
- примените миграцию `soft2soft\yii2dbbackup\migrations\m260216_000001_create_backup_job_table`

## API

- `POST /dbbackup/backup/start` — создать и запустить задачу
- `GET /dbbackup/backup/status?id=...` — статус одной задачи
- `GET /dbbackup/backup/list?limit=50` — список задач
- `GET /dbbackup/backup/download?id=...` — скачать `.sql.gz`
- `POST /dbbackup/backup/delete?id=...` — удалить задачу и файл

Все web-ответы JSON, кроме `download`.

## Примечания

- Для Linux используются `nice/ionice` (если доступны).
- Если `gzip` binary отсутствует, включается fallback: dump в `.sql` и потоковое сжатие через `zlib`.
- Имя файла включает домен: `example_com_db_YYYYmmdd_HHMMSS_jobN.sql.gz`.
