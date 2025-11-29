<?php
// Upgrade helper: optional backup + schema migrations (safe/idempotent).
// Usage: php bin/upgrade.php [--no-backup] [--backup-dir=./backups]

require_once __DIR__ . '/../src/Support/Bootstrap.php';
require_once __DIR__ . '/migrate_categories.php';

$args = $argv ?? [];
$doBackup = !in_array('--no-backup', $args, true);
$backupDir = __DIR__ . '/../backups';
foreach ($args as $arg) {
    if (strpos($arg, '--backup-dir=') === 0) {
        $backupDir = substr($arg, strlen('--backup-dir='));
    }
}

function print_line(string $msg): void { echo $msg . PHP_EOL; }

function run_backup(string $backupDir): ?string {
    if (!function_exists('exec')) {
        print_line("exec() disabled; skipping backup. Please run mysqldump manually.");
        return null;
    }
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            print_line("Failed to create backup dir: $backupDir");
            return null;
        }
    }
    $ts = date('Ymd-His');
    $file = rtrim($backupDir, '/\\') . "/pnotes-backup-$ts.sql";
    $host = defined('MYSQL_HOST') ? MYSQL_HOST : '127.0.0.1';
    $port = defined('MYSQL_PORT') ? MYSQL_PORT : 3306;
    $db   = defined('MYSQL_DB') ? MYSQL_DB : 'pnotes';
    $user = defined('MYSQL_USER') ? MYSQL_USER : '';
    $pass = defined('MYSQL_PASS') ? MYSQL_PASS : '';
    $socket = (defined('MYSQL_USE_SOCKET') && MYSQL_USE_SOCKET) ? MYSQL_SOCKET : null;
    $cmd = ['mysqldump'];
    if ($socket) { $cmd[] = "--socket=" . escapeshellarg($socket); }
    else { $cmd[] = "-h" . escapeshellarg($host); $cmd[] = "-P" . escapeshellarg((string)$port); }
    if ($user !== '') $cmd[] = "-u" . escapeshellarg($user);
    if ($pass !== '') $cmd[] = "-p" . escapeshellarg($pass);
    $cmd[] = escapeshellarg($db);
    $command = implode(' ', $cmd) . " > " . escapeshellarg($file);
    print_line("Running backup: $command");
    exec($command, $out, $code);
    if ($code !== 0) {
        print_line("Backup failed with code $code. Please run mysqldump manually.");
        return null;
    }
    print_line("Backup saved to $file");
    return $file;
}

print_line("Starting upgrade...");
if ($doBackup) {
    run_backup($backupDir);
} else {
    print_line("Skipping backup (--no-backup).");
}

print_line("Applying schema migrations...");
migrate_categories();

print_line("Upgrade complete.");
