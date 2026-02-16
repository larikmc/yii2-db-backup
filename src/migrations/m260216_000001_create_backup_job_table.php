<?php

namespace larikmc\yii2dbbackup\migrations;

use yii\db\Migration;

class m260216_000001_create_backup_job_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%backup_job}}', [
            'id' => $this->primaryKey(),
            'type' => $this->string(32)->notNull()->defaultValue('full'),
            'status' => $this->string(32)->notNull()->defaultValue('queued'),
            'phase' => $this->string(64)->null(),
            'started_at' => $this->integer()->null(),
            'finished_at' => $this->integer()->null(),
            'file_path' => $this->string(1024)->null(),
            'file_size' => $this->bigInteger()->null(),
            'sha256' => $this->string(64)->null(),
            'error_text' => $this->text()->null(),
            'meta_json' => $this->text()->null(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);

        $this->createIndex('idx-backup_job-status', '{{%backup_job}}', ['status']);
        $this->createIndex('idx-backup_job-created_at', '{{%backup_job}}', ['created_at']);
    }

    public function safeDown()
    {
        $this->dropTable('{{%backup_job}}');
    }
}

