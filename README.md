# larikmc/yii2-db-backup

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
    "larikmc/yii2-db-backup": "*"
  }
}
```

Затем:

```bash
composer update larikmc/yii2-db-backup
```

## Конфигурация

Подключите модуль в web и console приложениях под ID `dbbackup`.

### Готовая вставка для `backend/config/main.php`

```php
<?php

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php'
);

$defaultDumpBinary = DIRECTORY_SEPARATOR === '\\'
    ? 'D:\\OpenServer646\\modules\\MariaDB-11.8\\bin\\mysqldump.exe'
    : '';
$dumpBinary = getenv('DBBACKUP_DUMP_BINARY') ?: $defaultDumpBinary;

$config = [
    // ...
    'modules' => [
        // ...
        'dbbackup' => [
            'class' => larikmc\yii2dbbackup\Module::class,
            'controllerNamespace' => 'larikmc\\yii2dbbackup\\web\\controllers',
            'backupDir' => '@runtime/backups/db',
            'dumpBinary' => $dumpBinary,
            'retentionDays' => 14,
            'minFreeSpaceGb' => 2,
            'maxConcurrent' => 1,
            'accessRole' => '@',
            'consoleRoute' => 'dbbackup/backup/run',
            'autoCreateTable' => true,
        ],
    ],
    'components' => [
        // ...
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'db-backup/<action:\w+>' => 'dbbackup/backup/<action>',
            ],
        ],
    ],
];

return $config;
```

### Готовая вставка для `console/config/main.php`

```php
<?php

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php'
);

$defaultDumpBinary = DIRECTORY_SEPARATOR === '\\'
    ? 'D:\\OpenServer646\\modules\\MariaDB-11.8\\bin\\mysqldump.exe'
    : '';
$dumpBinary = getenv('DBBACKUP_DUMP_BINARY') ?: $defaultDumpBinary;

return [
    'id' => 'app-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'console\\controllers',
    'modules' => [
        'dbbackup' => [
            'class' => larikmc\yii2dbbackup\Module::class,
            'controllerNamespace' => 'larikmc\\yii2dbbackup\\console\\controllers',
            'backupDir' => '@runtime/backups/db',
            'dumpBinary' => $dumpBinary,
            'retentionDays' => 14,
            'minFreeSpaceGb' => 2,
            'maxConcurrent' => 1,
            'consoleRoute' => 'dbbackup/backup/run',
            'autoCreateTable' => true,
        ],
    ],
    'params' => $params,
];
```

### Пример backend (web)

```php
'modules' => [
    'dbbackup' => [
        'class' => larikmc\yii2dbbackup\Module::class,
        'controllerNamespace' => 'larikmc\\yii2dbbackup\\web\\controllers',
        'backupDir' => '@runtime/backups/db',
        'dumpBinary' => '', // путь к mariadb-dump/mysqldump, если нужен явный
        'retentionDays' => 14,
        'minFreeSpaceGb' => 2,
        'maxConcurrent' => 1,
        'accessRole' => '@',
        'consoleRoute' => 'dbbackup/backup/run',
        'autoCreateTable' => true,
    ],
],
```

### Пример console

```php
'modules' => [
    'dbbackup' => [
        'class' => larikmc\yii2dbbackup\Module::class,
        'controllerNamespace' => 'larikmc\\yii2dbbackup\\console\\controllers',
        'backupDir' => '@runtime/backups/db',
        'dumpBinary' => '',
        'retentionDays' => 14,
        'minFreeSpaceGb' => 2,
        'maxConcurrent' => 1,
        'consoleRoute' => 'dbbackup/backup/run',
        'autoCreateTable' => true,
    ],
],
```

### Разные `dumpBinary` для локалки и прода

```php
$defaultDumpBinary = DIRECTORY_SEPARATOR === '\\'
    ? 'D:\\OpenServer646\\modules\\MariaDB-11.8\\bin\\mysqldump.exe'
    : '';
$dumpBinary = getenv('DBBACKUP_DUMP_BINARY') ?: $defaultDumpBinary;
```

И в модуле:

```php
'dumpBinary' => $dumpBinary,
```

## Хранилище (таблица backup_job)

По умолчанию таблица создается автоматически при первом запуске (`autoCreateTable = true`).

Если хотите управлять схемой только через миграции:
- отключите автосоздание: `'autoCreateTable' => false`
- примените миграцию `larikmc\yii2dbbackup\migrations\m260216_000001_create_backup_job_table`

## API

- `GET /dbbackup/backup/index` — встроенная web-страница управления
- `POST /dbbackup/backup/start` — создать и запустить задачу
- `GET /dbbackup/backup/status?id=...` — статус одной задачи
- `GET /dbbackup/backup/list?limit=50` — список задач
- `GET /dbbackup/backup/download?id=...` — скачать `.sql.gz`
- `POST /dbbackup/backup/delete?id=...` — удалить задачу и файл

`index` отдает HTML-страницу, `download` отдает файл, служебные endpoints возвращают JSON.

## Ссылка в админке (готовый HTML)

Если админка одинаковая на всех проектах, можно вставить ссылку прямо в шаблон меню.

### Вариант 1: простая ссылка

```php
<a href="<?= \yii\helpers\Url::to(['/dbbackup/backup/index']) ?>">Бэкап БД</a>
```

### Вариант 2: под ваш sidebar-стиль (полный блок с submenu)

```php
<li class="sz-nav__item">
    <a href="<?= \yii\helpers\Url::to(['/dbbackup/backup/index']) ?>" class="sz-nav__link">
        <span class="material-symbols-rounded">backup</span>
        <span class="sz-nav__label">Бэкап БД</span>
    </a>
    <ul class="sz-submenu">
        <li class="sz-submenu__item"><span class="sz-submenu__title">Бэкап БД</span></li>
    </ul>
</li>
```

### Если хотите URL вида `/admin/db-backup/index`

Добавьте правило в `urlManager` backend:

```php
'rules' => [
    'db-backup/<action:\w+>' => 'dbbackup/backup/<action>',
]
```

Тогда ссылка будет:

```php
<a href="<?= \yii\helpers\Url::to(['/db-backup/index']) ?>">Бэкап БД</a>
```

## Примечания

- Для Linux используются `nice/ionice` (если доступны).
- Если `gzip` binary отсутствует, включается fallback: dump в `.sql` и потоковое сжатие через `zlib`.
- Имя файла включает домен: `example_com_YYYYmmdd_HHMMSS_jN.sql.gz`.
- Лог конкретной задачи пишется в `@runtime/logs/db-backup-{jobId}.log`.
