<?php

declare(strict_types=1);

namespace Renote\Command;

class BackupCommand
{
    public function run(string $backupDir): ?string
    {
        if (!function_exists('exec')) {
            $this->printLine("exec() disabled; skipping backup. Please run mysqldump manually.");
            return null;
        }
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                $this->printLine("Failed to create backup dir: $backupDir");
                return null;
            }
        }
        $ts = date('Ymd-His');
        $file = rtrim($backupDir, '/\\') . "/renote-backup-$ts.sql";
        $host = defined('MYSQL_HOST') ? MYSQL_HOST : '127.0.0.1';
        $port = defined('MYSQL_PORT') ? MYSQL_PORT : 3306;
        $db   = defined('MYSQL_DB') ? MYSQL_DB : 'renote';
        $user = defined('MYSQL_USER') ? MYSQL_USER : '';
        $pass = defined('MYSQL_PASS') ? MYSQL_PASS : '';
        $socket = (defined('MYSQL_USE_SOCKET') && MYSQL_USE_SOCKET) ? MYSQL_SOCKET : null;
        $cmd = ['mysqldump'];
        if ($socket) {
            $cmd[] = "--socket=" . escapeshellarg($socket);
        } else {
            $cmd[] = "-h" . escapeshellarg($host);
            $cmd[] = "-P" . escapeshellarg((string)$port);
        }
        if ($user !== '') {
            $cmd[] = "-u" . escapeshellarg($user);
        }
        if ($pass !== '') {
            $cmd[] = "-p" . escapeshellarg($pass);
        }
        $cmd[] = escapeshellarg($db);
        $command = implode(' ', $cmd) . " > " . escapeshellarg($file);
        $this->printLine("Running backup: $command");
        exec($command, $out, $code);
        if ($code !== 0) {
            $this->printLine("Backup failed with code $code. Please run mysqldump manually.");
            return null;
        }
        $this->printLine("Backup saved to $file");
        return $file;
    }

    private function printLine(string $msg): void
    {
        echo $msg . PHP_EOL;
    }
}
