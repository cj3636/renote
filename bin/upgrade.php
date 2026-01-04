<?php

/* bin/upgrade.php
    * This script performs an upgrade of the Renote application.
    * It creates a backup of the current state unless the --no-backup flag is provided.
    * It then applies any necessary database schema migrations.
*/

declare(strict_types=1);

namespace Renote\Bin;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Support/Bootstrap.php';

use Renote\Command\BackupCommand;
use Renote\Command\MigrationCommand;

$args = $argv ?? [];
$doBackup = !in_array('--no-backup', $args, true);
$backupDir = __DIR__ . '/../backups';
foreach ($args as $arg) {
    if (strpos($arg, '--backup-dir=') === 0) {
        $backupDir = substr($arg, strlen('--backup-dir='));
    }
}

function print_line(string $msg): void
{
    echo $msg . PHP_EOL;
}

print_line("Starting upgrade...");

if ($doBackup) {
    $backupCommand = new BackupCommand();
    $backupCommand->run($backupDir);
} else {
    print_line("Skipping backup (--no-backup).");
}

print_line("Applying schema migrations...");
$migrationCommand = new MigrationCommand();
$migrationCommand->run();

print_line("Upgrade complete.");
