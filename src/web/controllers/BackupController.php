<?php

namespace larikmc\yii2dbbackup\web\controllers;

use RuntimeException;
use larikmc\yii2dbbackup\models\BackupJob;
use larikmc\yii2dbbackup\services\StorageInstaller;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\FileHelper;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class BackupController extends Controller
{
    public function behaviors(): array
    {
        $module = $this->getModuleInstance();
        $role = $module->accessRole;

        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => [$role],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'index' => ['GET'],
                    'start' => ['POST'],
                    'delete' => ['POST'],
                    'status' => ['GET'],
                    'download' => ['GET'],
                    'list' => ['GET'],
                ],
            ],
        ];
    }

    public function actionIndex(): string
    {
        $module = $this->getModuleInstance();
        $this->ensureStorageReady($module);

        $dataProvider = new ActiveDataProvider([
            'query' => BackupJob::find()->orderBy(['id' => SORT_DESC]),
            'pagination' => ['pageSize' => 50],
        ]);

        return $this->render('@yii2dbbackup/views/backup/index', [
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionStart()
    {
        $asJson = $this->wantsJson();
        if ($asJson) {
            Yii::$app->response->format = Response::FORMAT_JSON;
        }
        $module = $this->getModuleInstance();
        $this->ensureStorageReady($module);

        $active = (int)BackupJob::find()->where(['status' => [BackupJob::STATUS_QUEUED, BackupJob::STATUS_RUNNING]])->count();
        if ($active >= $module->maxConcurrent) {
            if ($asJson) {
                Yii::$app->response->statusCode = 409;
                return ['ok' => false, 'error' => 'Another backup is already running.'];
            }

            Yii::$app->session->setFlash('error', 'Уже выполняется backup-задача.');
            return $this->redirect(['index']);
        }

        $job = new BackupJob([
            'type' => BackupJob::TYPE_FULL,
            'status' => BackupJob::STATUS_QUEUED,
            'phase' => 'queued',
        ]);
        $job->setMeta([
            'requested_at' => date('c'),
            'requested_by' => Yii::$app->user->id ?? null,
            'requested_host' => Yii::$app->request->hostName,
        ]);

        if (!$job->save()) {
            if ($asJson) {
                Yii::$app->response->statusCode = 422;
                return ['ok' => false, 'error' => 'Unable to create backup job.'];
            }

            Yii::$app->session->setFlash('error', 'Не удалось создать backup-задачу.');
            return $this->redirect(['index']);
        }

        try {
            $this->launchBackgroundProcess((int)$job->id, $module->consoleRoute, $module->logDir);
        } catch (\Throwable $e) {
            $job->status = BackupJob::STATUS_FAILED;
            $job->phase = 'failed';
            $job->finished_at = time();
            $job->error_text = 'Cannot start background process: ' . $e->getMessage();
            $job->save(false, ['status', 'phase', 'finished_at', 'error_text', 'updated_at']);
            if ($asJson) {
                Yii::$app->response->statusCode = 500;
                return ['ok' => false, 'error' => $job->error_text];
            }

            Yii::$app->session->setFlash('error', 'Ошибка запуска backup: ' . $e->getMessage());
            return $this->redirect(['index']);
        }

        if ($asJson) {
            return ['ok' => true, 'jobId' => (int)$job->id, 'status' => $job->status];
        }

        Yii::$app->session->setFlash('success', 'Backup запущен. Job ID: ' . (int)$job->id);
        return $this->redirect(['index']);
    }

    public function actionStatus(int $id): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $this->ensureStorageReady($this->getModuleInstance());
        $job = BackupJob::findOne($id);
        if ($job === null) {
            throw new NotFoundHttpException('Job not found.');
        }
        return $this->serializeJob($job);
    }

    public function actionList(int $limit = 50): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        $this->ensureStorageReady($this->getModuleInstance());
        $limit = max(1, min(200, $limit));
        $rows = BackupJob::find()->orderBy(['id' => SORT_DESC])->limit($limit)->all();
        return array_map([$this, 'serializeJob'], $rows);
    }

    public function actionDownload(int $id): Response
    {
        $this->ensureStorageReady($this->getModuleInstance());
        $job = BackupJob::findOne($id);
        if ($job === null || !$job->file_path || !is_file($job->file_path)) {
            throw new NotFoundHttpException('File not found.');
        }
        return Yii::$app->response->sendFile($job->file_path, basename($job->file_path), ['inline' => false]);
    }

    public function actionDelete(int $id)
    {
        $asJson = $this->wantsJson();
        if ($asJson) {
            Yii::$app->response->format = Response::FORMAT_JSON;
        }
        $this->ensureStorageReady($this->getModuleInstance());
        $job = BackupJob::findOne($id);
        if ($job === null) {
            throw new NotFoundHttpException('Job not found.');
        }
        if (!in_array($job->status, [BackupJob::STATUS_SUCCESS, BackupJob::STATUS_FAILED], true)) {
            if ($asJson) {
                Yii::$app->response->statusCode = 409;
                return ['ok' => false, 'error' => 'Only completed jobs can be deleted.'];
            }

            Yii::$app->session->setFlash('error', 'Удалять можно только завершенные задачи.');
            return $this->redirect(['index']);
        }

        $deleted = [];
        foreach ([$job->file_path, $job->file_path ? $job->file_path . '.manifest.json' : null] as $path) {
            if ($path && is_file($path) && @unlink($path)) {
                $deleted[] = $path;
            }
        }
        $job->delete();
        if ($asJson) {
            return ['ok' => true, 'deleted' => $deleted];
        }

        Yii::$app->session->setFlash('success', 'Задача удалена.');
        return $this->redirect(['index']);
    }

    private function serializeJob(BackupJob $job): array
    {
        return [
            'id' => (int)$job->id,
            'status' => $job->status,
            'phase' => $job->phase,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'file_path' => $job->file_path,
            'file_size' => $job->file_size !== null ? (int)$job->file_size : null,
            'sha256' => $job->sha256,
            'error_text' => $job->error_text,
            'meta' => $job->getMeta(),
            'created_at' => (int)$job->created_at,
            'updated_at' => (int)$job->updated_at,
        ];
    }

    private function launchBackgroundProcess(int $jobId, string $consoleRoute, string $logDir): void
    {
        $yiiPath = $this->findYiiScript();
        $phpBinary = $this->resolvePhpCliBinary();
        $logPath = Yii::getAlias($logDir) . DIRECTORY_SEPARATOR . 'db-backup-launch-' . $jobId . '.log';
        FileHelper::createDirectory(dirname($logPath));

        $php = escapeshellarg($phpBinary);
        $yii = escapeshellarg($yiiPath);
        $log = escapeshellarg($logPath);

        if (DIRECTORY_SEPARATOR === '\\') {
            $command = 'start /B "" ' . $php . ' ' . $yii . ' ' . $consoleRoute . ' ' . $jobId . ' >> ' . $log . ' 2>&1';
            $h = @popen($command, 'r');
            if (!is_resource($h)) {
                throw new RuntimeException('Unable to start process.');
            }
            pclose($h);
            return;
        }

        $command = 'nohup ' . $php . ' ' . $yii . ' ' . $consoleRoute . ' ' . $jobId . ' >> ' . $log . ' 2>&1 &';
        exec($command, $output, $code);
        if ($code !== 0) {
            throw new RuntimeException('Unable to start process, exit code ' . $code);
        }
    }

    private function findYiiScript(): string
    {
        $appPath = Yii::getAlias('@app');
        $candidates = [
            $appPath . DIRECTORY_SEPARATOR . 'yii',
            dirname($appPath) . DIRECTORY_SEPARATOR . 'yii',
            dirname(dirname($appPath)) . DIRECTORY_SEPARATOR . 'yii',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException('yii script not found.');
    }

    private function resolvePhpCliBinary(): string
    {
        $binary = PHP_BINARY;
        if (DIRECTORY_SEPARATOR !== '\\') {
            return $binary;
        }
        if (stripos(basename($binary), 'php-cgi') === false) {
            return $binary;
        }
        $cli = dirname($binary) . DIRECTORY_SEPARATOR . 'php.exe';
        return is_file($cli) ? $cli : $binary;
    }

    private function getModuleInstance(): \larikmc\yii2dbbackup\Module
    {
        $module = Yii::$app->getModule('dbbackup');
        if (!$module instanceof \larikmc\yii2dbbackup\Module) {
            throw new RuntimeException("Module 'dbbackup' is not configured.");
        }
        return $module;
    }

    private function ensureStorageReady(\larikmc\yii2dbbackup\Module $module): void
    {
        (new StorageInstaller($module))->ensureTable();
    }

    private function wantsJson(): bool
    {
        if (Yii::$app->request->isAjax) {
            return true;
        }
        return strpos((string)Yii::$app->request->headers->get('Accept'), 'application/json') !== false;
    }
}
