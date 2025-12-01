<?php
// Migration helper to add categories support (safe, idempotent).
// Usage: php bin/migrate_categories.php

require_once __DIR__ . '/../src/Support/Bootstrap.php';

function migrate_categories(): void {
    $pdo = db();
    echo "Checking schema...\n";
    // 1) categories table
    try {
        ensure_categories_table();
        echo "Categories table present.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Failed to ensure categories table: {$e->getMessage()}\n");
        exit(1);
    }

    // 2) cards.category_id column
    $hasCat = db_supports_categories();
    if (!$hasCat) {
        echo "Adding category_id column to cards...\n";
        try {
            $pdo->exec("ALTER TABLE cards ADD COLUMN IF NOT EXISTS category_id VARCHAR(64) NOT NULL DEFAULT 'root' AFTER name");
            $hasCat = true;
        } catch (Throwable $e) {
            fwrite(STDERR, "Failed to add category_id column: {$e->getMessage()}\n");
            exit(1);
        }
    } else {
        echo "cards.category_id already present.\n";
    }

    // 3) index for category ordering
    if ($hasCat) {
        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cards_category_order ON cards (category_id, `order`)");
        } catch (Throwable $e) {
            // Some MariaDB versions lack IF NOT EXISTS; ignore duplicate index errors
        }
        try {
            $pdo->exec("UPDATE cards SET category_id='root' WHERE category_id IS NULL OR category_id=''");
        } catch (Throwable $e) { /* ignore */ }
        echo "Category column normalized.\n";
    }

    echo "Migration complete.\n";
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    migrate_categories();
}
