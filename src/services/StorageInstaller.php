<?php

namespace larikmc\yii2dbbackup\services;

use larikmc\yii2dbbackup\Module;
use Yii;

class StorageInstaller
{
    private Module $module;

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    public function ensureTable(): void
    {
        if (!$this->module->autoCreateTable) {
            return;
        }

        $db = Yii::$app->db;
        $tableName = $this->module->tableName;
        if ($db->getTableSchema($tableName, true) !== null) {
            return;
        }

        $rawName = $db->schema->getRawTableName($tableName);
        $charset = trim($this->module->tableCharset);
        $collation = trim($this->module->tableCollation);

        $tail = ' ENGINE=InnoDB';
        if ($charset !== '') {
            $tail .= ' DEFAULT CHARSET=' . $charset;
        }
        if ($collation !== '') {
            $tail .= ' COLLATE=' . $collation;
        }

        $sql = "CREATE TABLE IF NOT EXISTS `{$rawName}` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `type` VARCHAR(32) NOT NULL DEFAULT 'full',
            `status` VARCHAR(32) NOT NULL DEFAULT 'queued',
            `phase` VARCHAR(64) NULL,
            `started_at` INT NULL,
            `finished_at` INT NULL,
            `file_path` VARCHAR(1024) NULL,
            `file_size` BIGINT NULL,
            `sha256` VARCHAR(64) NULL,
            `error_text` TEXT NULL,
            `meta_json` TEXT NULL,
            `created_at` INT NOT NULL,
            `updated_at` INT NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx-backup_job-status` (`status`),
            KEY `idx-backup_job-created_at` (`created_at`)
        ){$tail}";

        $db->createCommand($sql)->execute();
    }
}

