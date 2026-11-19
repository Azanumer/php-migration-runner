<?php
/**
 * CLI for the migration runner.
 *
 * Usage:
 *   php migrate.php status    --dir=./migrations --dsn="mysql:host=localhost;dbname=app" --user=root
 *   php migrate.php migrate   --dir=./migrations --dsn=... --user=... [--pass=...]
 *   php migrate.php rollback  --dir=./migrations --dsn=... --user=... [--steps=1]
 *   php migrate.php create add_users_table --dir=./migrations
 *
 * DSN, user and password can also come from environment variables:
 *   DB_DSN, DB_USER, DB_PASS
 *
 * The `create` command needs no database connection.
 */

declare(strict_types=1);

require __DIR__ . '/src/MigrationRunner.php';

use Azanumer\MigrationRunner\MigrationRunner;

function cli_usage(): void
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php migrate.php status    --dir=DIR --dsn=DSN [--user=U] [--pass=P]\n");
    fwrite(STDERR, "  php migrate.php migrate   --dir=DIR --dsn=DSN [--user=U] [--pass=P]\n");
    fwrite(STDERR, "  php migrate.php rollback  --dir=DIR --dsn=DSN [--user=U] [--pass=P] [--steps=N]\n");
    fwrite(STDERR, "  php migrate.php create NAME --dir=DIR\n");
    exit(1);
}

/**
 * Read a --key=value option from $argv.
 */
function cli_option(array $argv, string $key, ?string $default = null): ?string
{
    foreach ($argv as $arg) {
        if (0 === strpos($arg, '--' . $key . '=')) {
            return substr($arg, strlen($key) + 3);
        }
    }
    return $default;
}

$command = $argv[1] ?? null;
if (!in_array($command, ['status', 'migrate', 'rollback', 'create'], true)) {
    cli_usage();
}

$dir = cli_option($argv, 'dir');
if (null === $dir || '' === $dir) {
    fwrite(STDERR, "Error: --dir is required.\n");
    exit(1);
}
if (!is_dir($dir)) {
    fwrite(STDERR, "Error: migrations directory does not exist: {$dir}\n");
    exit(1);
}

if ('create' === $command) {
    $name = $argv[2] ?? null;
    if (null === $name || '' === $name) {
        fwrite(STDERR, "Error: create needs a migration name, e.g. add_users_table\n");
        exit(1);
    }
    // No DB connection needed for scaffolding.
    $pdo    = new PDO('sqlite::memory:');
    $runner = new MigrationRunner($pdo, $dir);
    $path   = $runner->create($name);
    echo "Created: {$path}\n";
    exit(0);
}

$dsn  = cli_option($argv, 'dsn', getenv('DB_DSN') ?: null);
$user = cli_option($argv, 'user', getenv('DB_USER') ?: null);
$pass = cli_option($argv, 'pass', getenv('DB_PASS') ?: null);
if (null === $dsn || '' === $dsn) {
    fwrite(STDERR, "Error: --dsn (or DB_DSN) is required.\n");
    exit(1);
}

try {
    $pdo = new PDO($dsn, (string) $user, (string) $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$runner = new MigrationRunner($pdo, $dir);

try {
    if ('status' === $command) {
        $rows = $runner->status();
        if (empty($rows)) {
            echo "No migrations found.\n";
            exit(0);
        }
        foreach ($rows as $row) {
            $state = $row['applied'] ? "applied (batch {$row['batch']})" : 'pending';
            echo str_pad($row['name'], 50) . $state . "\n";
        }
        $pending = count($runner->pending());
        echo "\n" . $pending . " pending migration(s).\n";
    } elseif ('migrate' === $command) {
        $applied = $runner->migrate();
        if (empty($applied)) {
            echo "Nothing to migrate — already up to date.\n";
        } else {
            foreach ($applied as $name) {
                echo "Migrated: {$name}\n";
            }
        }
    } elseif ('rollback' === $command) {
        $steps      = (int) (cli_option($argv, 'steps', '1') ?? '1');
        $rolledBack = $runner->rollback(max(1, $steps));
        if (empty($rolledBack)) {
            echo "Nothing to roll back.\n";
        } else {
            foreach ($rolledBack as $name) {
                echo "Rolled back: {$name}\n";
            }
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
