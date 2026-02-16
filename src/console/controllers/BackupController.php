<?php

namespace soft2soft\yii2dbbackup\console\controllers;

use soft2soft\yii2dbbackup\models\BackupJob;
use soft2soft\yii2dbbackup\services\BackupExecutor;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class BackupController extends Controller
{
    public function actionRun(int $id): int
    {
        $job = BackupJob::findOne($id);
        if ($job === null) {
            $this->stderr("Backup job #{$id} not found." . PHP_EOL);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!in_array($job->status, [BackupJob::STATUS_QUEUED, BackupJob::STATUS_RUNNING], true)) {
            $this->stdout("Backup job #{$id} already finished ({$job->status})." . PHP_EOL);
            return ExitCode::OK;
        }

        $module = Yii::$app->getModule('dbbackup');
        if (!$module instanceof \soft2soft\yii2dbbackup\Module) {
            $this->stderr("Module 'dbbackup' is not configured." . PHP_EOL);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            (new BackupExecutor($module))->run($job);
            $this->stdout("Backup job #{$id} done." . PHP_EOL);
            return ExitCode::OK;
        } catch (\Throwable $e) {
            $this->stderr("Backup failed: {$e->getMessage()}" . PHP_EOL);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}

