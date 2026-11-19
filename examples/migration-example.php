<?php
/**
 * Example: run migrations against an in-memory SQLite database.
 *
 * Run: php examples/migration-example.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/MigrationRunner.php';

use Azanumer\MigrationRunner\MigrationRunner;

$pdo    = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$runner = new MigrationRunner($pdo, __DIR__ . '/../migrations');

echo "Pending before migrate: " . implode(', ', $runner->pending()) . PHP_EOL;

$applied = $runner->migrate();
echo 'Applied: ' . implode(', ', $applied) . PHP_EOL;

foreach ($runner->status() as $row) {
    echo $row['name'] . ' — ' . ($row['applied'] ? 'applied (batch ' . $row['batch'] . ')' : 'pending') . PHP_EOL;
}

$rolledBack = $runner->rollback();
echo 'Rolled back: ' . implode(', ', $rolledBack) . PHP_EOL;
echo 'Pending after rollback: ' . implode(', ', $runner->pending()) . PHP_EOL;
