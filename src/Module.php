<?php

namespace soft2soft\yii2dbbackup;

class Module extends \yii\base\Module
{
    public string $controllerNamespace = 'soft2soft\\yii2dbbackup\\web\\controllers';

    public string $tableName = '{{%backup_job}}';
    public string $backupDir = '@runtime/backups/db';
    public string $dumpBinary = '';
    public int $retentionDays = 14;
    public int $minFreeSpaceGb = 10;
    public int $maxConcurrent = 1;

    public string $lockFile = '@runtime/locks/db-backup.lock';
    public string $logDir = '@runtime/logs';
    public string $tmpDir = '@runtime/backups/tmp';
    public string $consoleRoute = 'dbbackup/backup/run';
    public string $accessRole = '@';
    public bool $autoCreateTable = true;
    public string $tableCharset = 'utf8mb4';
    public string $tableCollation = 'utf8mb4_unicode_ci';

    public function init(): void
    {
        parent::init();
    }
}
