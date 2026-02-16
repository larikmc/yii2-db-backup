<?php

namespace soft2soft\yii2dbbackup\services;

class BackupRetentionService
{
    /**
     * @return string[]
     */
    public function prune(string $dir, int $retentionDays): array
    {
        if ($retentionDays <= 0 || !is_dir($dir)) {
            return [];
        }

        $deleted = [];
        $cutoff = time() - ($retentionDays * 86400);
        $files = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql.gz');
        if (!is_array($files)) {
            return [];
        }

        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime >= $cutoff) {
                continue;
            }

            @unlink($file);
            @unlink($file . '.manifest.json');
            $deleted[] = $file;
        }

        return $deleted;
    }
}

