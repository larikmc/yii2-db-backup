<?php

namespace soft2soft\yii2dbbackup\services;

use RuntimeException;

class BackupCommandBuilder
{
    public function hasGzipBinary(): bool
    {
        try {
            $this->resolveGzipBinary();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function resolveDumpBinary(string $configuredBinary = ''): string
    {
        $configuredBinary = trim($configuredBinary);
        if ($configuredBinary !== '') {
            return $configuredBinary;
        }

        $candidates = DIRECTORY_SEPARATOR === '\\'
            ? ['mariadb-dump.exe', 'mysqldump.exe']
            : ['/usr/bin/mariadb-dump', '/usr/bin/mysqldump', 'mariadb-dump', 'mysqldump'];

        foreach ($candidates as $candidate) {
            if ($this->binaryExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Dump binary not found.');
    }

    public function buildDumpCommand(array $dbConfig, string $dumpBinary, string $defaultsFile, string $outputPartFile): string
    {
        $dbName = $this->extractDbName((string)($dbConfig['dsn'] ?? ''));
        if ($dbName === '') {
            throw new RuntimeException('Unable to detect dbname from DSN.');
        }

        $charset = trim((string)($dbConfig['charset'] ?? 'utf8mb4'));
        if ($charset === '') {
            $charset = 'utf8mb4';
        }

        $base = $this->buildBaseDumpCommand($dumpBinary, $defaultsFile, $charset, $dbName);
        $gzip = $this->resolveGzipBinary();
        $pipeline = $base . ' | ' . escapeshellarg($gzip) . ' -1 > ' . escapeshellarg($outputPartFile);

        if (DIRECTORY_SEPARATOR === '\\') {
            return $pipeline;
        }

        $nice = $this->binaryExists('/usr/bin/nice') ? '/usr/bin/nice' : '';
        $ionice = $this->binaryExists('/usr/bin/ionice') ? '/usr/bin/ionice' : '';
        if ($nice !== '' && $ionice !== '') {
            return escapeshellarg($nice) . ' -n 15 ' . escapeshellarg($ionice) . ' -c2 -n7 ' . $pipeline;
        }

        return $pipeline;
    }

    public function buildDumpCommandRaw(array $dbConfig, string $dumpBinary, string $defaultsFile, string $outputSqlFile): string
    {
        $dbName = $this->extractDbName((string)($dbConfig['dsn'] ?? ''));
        if ($dbName === '') {
            throw new RuntimeException('Unable to detect dbname from DSN.');
        }

        $charset = trim((string)($dbConfig['charset'] ?? 'utf8mb4'));
        if ($charset === '') {
            $charset = 'utf8mb4';
        }

        $command = $this->buildBaseDumpCommand($dumpBinary, $defaultsFile, $charset, $dbName);
        $command .= ' --result-file=' . escapeshellarg($outputSqlFile);

        if (DIRECTORY_SEPARATOR === '\\') {
            return $command;
        }

        $nice = $this->binaryExists('/usr/bin/nice') ? '/usr/bin/nice' : '';
        $ionice = $this->binaryExists('/usr/bin/ionice') ? '/usr/bin/ionice' : '';
        if ($nice !== '' && $ionice !== '') {
            return escapeshellarg($nice) . ' -n 15 ' . escapeshellarg($ionice) . ' -c2 -n7 ' . $command;
        }

        return $command;
    }

    public function extractDbName(string $dsn): string
    {
        if (!preg_match('/(?:^|;)dbname=([^;]+)/i', $dsn, $m)) {
            return '';
        }

        return trim($m[1]);
    }

    private function buildBaseDumpCommand(string $dumpBinary, string $defaultsFile, string $charset, string $dbName): string
    {
        return implode(' ', [
            escapeshellarg($dumpBinary),
            '--defaults-extra-file=' . escapeshellarg($defaultsFile),
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--routines',
            '--events',
            '--triggers',
            '--hex-blob',
            '--default-character-set=' . escapeshellarg($charset),
            escapeshellarg($dbName),
        ]);
    }

    private function resolveGzipBinary(): string
    {
        $candidates = DIRECTORY_SEPARATOR === '\\'
            ? ['gzip.exe', 'gzip']
            : ['/usr/bin/gzip', '/bin/gzip', 'gzip'];

        foreach ($candidates as $candidate) {
            if ($this->binaryExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('gzip binary not found.');
    }

    private function binaryExists(string $binary): bool
    {
        if ($binary === '') {
            return false;
        }

        if (strpos($binary, DIRECTORY_SEPARATOR) !== false) {
            return is_file($binary);
        }

        $probe = DIRECTORY_SEPARATOR === '\\'
            ? 'where ' . escapeshellarg($binary)
            : 'command -v ' . escapeshellarg($binary);
        exec($probe, $out, $exitCode);
        return $exitCode === 0;
    }
}

