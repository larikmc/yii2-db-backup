<?php

namespace larikmc\yii2dbbackup\models;

use larikmc\yii2dbbackup\Module;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property string $type
 * @property string $status
 * @property string|null $phase
 * @property int|null $started_at
 * @property int|null $finished_at
 * @property string|null $file_path
 * @property int|null $file_size
 * @property string|null $sha256
 * @property string|null $error_text
 * @property string|null $meta_json
 * @property int $created_at
 * @property int $updated_at
 */
class BackupJob extends ActiveRecord
{
    public const TYPE_FULL = 'full';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PRUNED = 'pruned';

    public static function tableName(): string
    {
        $module = Yii::$app->getModule('dbbackup');
        if ($module instanceof Module) {
            return $module->tableName;
        }

        return '{{%backup_job}}';
    }

    public function behaviors(): array
    {
        return [
            TimestampBehavior::class,
        ];
    }

    public function rules(): array
    {
        return [
            [['type', 'status'], 'required'],
            [['started_at', 'finished_at', 'file_size', 'created_at', 'updated_at'], 'integer'],
            [['error_text', 'meta_json'], 'string'],
            [['type', 'status'], 'string', 'max' => 32],
            [['phase'], 'string', 'max' => 64],
            [['file_path'], 'string', 'max' => 1024],
            [['sha256'], 'string', 'max' => 64],
        ];
    }

    public function setMeta(array $meta): void
    {
        $this->meta_json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function getMeta(): array
    {
        if (!$this->meta_json) {
            return [];
        }

        $data = json_decode($this->meta_json, true);
        return is_array($data) ? $data : [];
    }
}

