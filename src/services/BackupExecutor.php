<?php

namespace larikmc\yii2dbbackup\services;

use RuntimeException;
use larikmc\yii2dbbackup\Module;
use larikmc\yii2dbbackup\models\BackupJob;
use Yii;
use yii\helpers\FileHelper;

class BackupExecutor
{
    private Module $module;
    private BackupCommandBuilder $builder;
    private BackupRetentionService $retention;
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(Module $module, ?BackupCommandBuilder $builder = null, ?BackupRetentionService $retention = null)
    {
        $this->module = $module;
        $this->builder = $builder ?? new BackupCommandBuilder();
        $this->retention = $retention ?? new BackupRetentionService();
    }

    public function run(BackupJob $job): void
    {
        $defaultsFile = null;
        $tmpGz = null;
        $tmpSql = null;

        $this->acquireLock();
        try {
            $this->setState($job, BackupJob::STATUS_RUNNING, 'precheck');
            $backupDir = Yii::getAlias($this->module->backupDir);
            $this->assertFreeDiskSpace($backupDir, $this->module->minFreeSpaceGb);

            $this->setState($job, BackupJob::STATUS_RUNNING, 'dump');
            $name = $this->buildBackupFileName($job);
            $finalPath = rtrim($backupDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            $tmpGz = $finalPath . '.part';
            $tmpSql = $finalPath . '.tmp.sql';
            $logPath = Yii::getAlias($this->module->logDir) . DIRECTORY_SEPARATOR . 'db-backup-' . (int)$job->id . '.log';

            FileHelper::createDirectory(dirname($finalPath));
            FileHelper::createDirectory(dirname($logPath));

            $job->file_path = $finalPath;
            $meta = $job->getMeta();
            $meta['log_file'] = $logPath;
            $job->setMeta($meta);
            $job->save(false, ['file_path', 'meta_json', 'updated_at']);

            $dbConfig = $this->getDbConfig();
            $defaultsFile = $this->createDefaultsFile($dbConfig);
            $binary = $this->builder->resolveDumpBinary($this->module->dumpBinary);

            if ($this->builder->hasGzipBinary()) {
                $command = $this->builder->buildDumpCommand($dbConfig, $binary, $defaultsFile, $tmpGz);
                $this->runProcess($command, $logPath);
                if (!is_file($tmpGz) || filesize($tmpGz) === 0) {
                    throw new RuntimeException('Dump file not created or empty.');
                }
            } else {
                $command = $this->builder->buildDumpCommandRaw($dbConfig, $binary, $defaultsFile, $tmpSql);
                $this->runProcess($command, $logPath);
                if (!is_file($tmpSql) || filesize($tmpSql) === 0) {
                    throw new RuntimeException('SQL dump file not created or empty.');
                }
                $this->setState($job, BackupJob::STATUS_RUNNING, 'compress');
                $this->compressSqlToGzip($tmpSql, $tmpGz);
            }

            $this->setState($job, BackupJob::STATUS_RUNNING, 'finalize');
            if (!@rename($tmpGz, $finalPath)) {
                throw new RuntimeException('Failed to move temporary dump file.');
            }

            $size = (int)filesize($finalPath);
            $sha = hash_file('sha256', $finalPath);
            if ($sha === false) {
                throw new RuntimeException('SHA256 failed.');
            }

            $manifestPath = $finalPath . '.manifest.json';
            $manifest = [
                'job_id' => (int)$job->id,
                'created_at' => date('c'),
                'file_path' => $finalPath,
                'file_size' => $size,
                'sha256' => $sha,
            ];
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->setState($job, BackupJob::STATUS_RUNNING, 'retention');
            $deleted = $this->retention->prune($backupDir, $this->module->retentionDays);

            $meta = $job->getMeta();
            $meta['manifest_file'] = $manifestPath;
            $meta['retention_deleted'] = $deleted;
            $job->setMeta($meta);
            $job->status = BackupJob::STATUS_SUCCESS;
            $job->phase = 'done';
            $job->finished_at = time();
            $job->file_size = $size;
            $job->sha256 = $sha;
            $job->error_text = null;
            $job->save(false, ['status', 'phase', 'finished_at', 'file_size', 'sha256', 'error_text', 'meta_json', 'updated_at']);
        } catch (\Throwable $e) {
            $job->status = BackupJob::STATUS_FAILED;
            $job->phase = 'failed';
            $job->finished_at = time();
            $job->error_text = $e->getMessage();
            $job->save(false, ['status', 'phase', 'finished_at', 'error_text', 'updated_at']);
            throw $e;
        } finally {
            if ($defaultsFile && is_file($defaultsFile)) {
                @unlink($defaultsFile);
            }
            if ($tmpGz && is_file($tmpGz)) {
                @unlink($tmpGz);
            }
            if ($tmpSql && is_file($tmpSql)) {
                @unlink($tmpSql);
            }
            $this->releaseLock();
        }
    }

    private function setState(BackupJob $job, string $status, string $phase): void
    {
        $job->status = $status;
        $job->phase = $phase;
        if ($status === BackupJob::STATUS_RUNNING && $job->started_at === null) {
            $job->started_at = time();
        }
        $job->save(false, ['status', 'phase', 'started_at', 'updated_at']);
    }

    private function getDbConfig(): array
    {
        $db = Yii::$app->db;
        return [
            'dsn' => (string)$db->dsn,
            'username' => (string)$db->username,
            'password' => (string)$db->password,
            'charset' => (string)$db->charset,
        ];
    }

    private function createDefaultsFile(array $dbConfig): string
    {
        $dsn = (string)($dbConfig['dsn'] ?? '');
        $host = '127.0.0.1';
        $port = '';
        if (preg_match('/host=([^;]+)/i', $dsn, $m)) {
            $host = trim($m[1]);
        }
        if (preg_match('/port=([^;]+)/i', $dsn, $m)) {
            $port = trim($m[1]);
        }

        $content = "[client]\n";
        $content .= 'host=' . $host . "\n";
        if ($port !== '') {
            $content .= 'port=' . $port . "\n";
        }
        $content .= 'user=' . (string)($dbConfig['username'] ?? '') . "\n";
        $content .= 'password=' . (string)($dbConfig['password'] ?? '') . "\n";

        $tmpDir = Yii::getAlias($this->module->tmpDir);
        FileHelper::createDirectory($tmpDir);
        $file = $tmpDir . DIRECTORY_SEPARATOR . 'dbb-' . uniqid('', true) . '.cnf';
        file_put_contents($file, $content);
        @chmod($file, 0600);
        return $file;
    }

    private function runProcess(string $command, string $logFile): void
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];
        $proc = proc_open($command, $descriptor, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Unable to start backup process.');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new RuntimeException('Backup process failed with exit code ' . $code);
        }
    }

    private function assertFreeDiskSpace(string $dir, int $minGb): void
    {
        FileHelper::createDirectory($dir);
        $free = @disk_free_space($dir);
        if (!is_numeric($free)) {
            throw new RuntimeException('Unable to check free disk space.');
        }
        $required = $minGb * 1024 * 1024 * 1024;
        if ((float)$free < $required) {
            $freeGb = round(((float)$free) / 1024 / 1024 / 1024, 2);
            throw new RuntimeException(sprintf('Insufficient disk space: required %d GB, available %s GB.', $minGb, $freeGb));
        }
    }

    private function acquireLock(): void
    {
        $lockPath = Yii::getAlias($this->module->lockFile);
        FileHelper::createDirectory(dirname($lockPath));
        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open lock file.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another backup is already running.');
        }
        $this->lockHandle = $handle;
    }

    private function releaseLock(): void
    {
        if (!is_resource($this->lockHandle)) {
            return;
        }
        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }

    private function compressSqlToGzip(string $sourceSql, string $targetGz): void
    {
        if (!function_exists('gzopen')) {
            throw new RuntimeException('zlib extension is not available.');
        }
        $in = fopen($sourceSql, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot read SQL dump for compression.');
        }
        $out = gzopen($targetGz, 'wb1');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Cannot write gzip dump.');
        }
        try {
            while (!feof($in)) {
                $chunk = fread($in, 8 * 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Read error during compression.');
                }
                if ($chunk !== '' && gzwrite($out, $chunk) === false) {
                    throw new RuntimeException('Write error during compression.');
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
        @unlink($sourceSql);
    }

    private function resolveHostPrefix(BackupJob $job): string
    {
        $meta = $job->getMeta();
        $host = '';
        if (!empty($meta['requested_host']) && is_string($meta['requested_host'])) {
            $host = $meta['requested_host'];
        }
        $host = trim(strtolower($host));
        if ($host === '') {
            return 'site';
        }
        $safe = preg_replace('/[^a-z0-9\.\-]/', '_', $host);
        $safe = str_replace('.', '_', (string)$safe);
        $safe = preg_replace('/_+/', '_', (string)$safe);
        $safe = trim((string)$safe, '_');
        return $safe !== '' ? $safe : 'site';
    }

    private function buildBackupFileName(BackupJob $job): string
    {
        $prefix = $this->resolveHostPrefix($job);
        if (strlen($prefix) > 24) {
            $prefix = substr($prefix, 0, 24);
            $prefix = rtrim($prefix, '_');
        }

        return sprintf('%s_%s_j%d.sql.gz', $prefix ?: 'site', date('Ymd_His'), (int)$job->id);
    }
}
