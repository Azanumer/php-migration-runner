<?php
/**
 * Dependency-free database migration runner (PDO).
 *
 * Migration files live in a directory and are named
 * YYYYMMDDHHMMSS_descriptive_name.php. Each file returns an array:
 *
 *     return [
 *         'up'   => function (PDO $pdo) { $pdo->exec('CREATE TABLE ...'); },
 *         'down' => function (PDO $pdo) { $pdo->exec('DROP TABLE ...'); },
 *     ];
 *
 * Applied migrations are recorded in a `migrations` table with a batch
 * number, so rollback() reverts exactly the last batch. Every migration
 * runs inside a transaction. Works with MySQL, PostgreSQL and SQLite.
 *
 * PHP 7.4+ compatible.
 */

declare(strict_types=1);

namespace Azanumer\MigrationRunner;

use PDO;
use RuntimeException;
use InvalidArgumentException;

class MigrationRunner
{
    /** @var PDO */
    private $pdo;

    /** @var string */
    private $migrationsDir;

    /** @var string */
    private $table;

    /**
     * @param PDO    $pdo           Connected PDO instance.
     * @param string $migrationsDir Directory holding migration files.
     * @param string $table         Table tracking applied migrations.
     */
    public function __construct(PDO $pdo, string $migrationsDir, string $table = 'migrations')
    {
        if (!is_dir($migrationsDir)) {
            throw new InvalidArgumentException("Migrations directory does not exist: {$migrationsDir}");
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException("Invalid migrations table name: {$table}");
        }

        $this->pdo           = $pdo;
        $this->migrationsDir = rtrim($migrationsDir, DIRECTORY_SEPARATOR);
        $this->table         = $table;
        $this->ensureTable();
    }

    /**
     * Create the tracking table if it does not exist yet.
     */
    public function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ('sqlite' === $driver) {
            $auto = 'INTEGER PRIMARY KEY AUTOINCREMENT';
        } elseif ('pgsql' === $driver) {
            $auto = 'SERIAL PRIMARY KEY';
        } else {
            $auto = 'INT AUTO_INCREMENT PRIMARY KEY';
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                id {$auto},
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }

    /**
     * All migration files on disk, sorted by name (timestamp order).
     *
     * @return string[] File basenames without the .php extension.
     */
    public function all(): array
    {
        $names = [];
        foreach (glob($this->migrationsDir . DIRECTORY_SEPARATOR . '*.php') as $file) {
            $names[] = basename($file, '.php');
        }
        sort($names);
        return $names;
    }

    /**
     * Names of migrations already applied, in application order.
     *
     * @return string[]
     */
    public function applied(): array
    {
        $stmt = $this->pdo->query("SELECT migration FROM {$this->table} ORDER BY id ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /**
     * Names of migrations on disk that have not been applied yet.
     *
     * @return string[]
     */
    public function pending(): array
    {
        return array_values(array_diff($this->all(), $this->applied()));
    }

    /**
     * Status of every migration: name, applied flag and batch number.
     *
     * @return array<int, array{name: string, applied: bool, batch: int|null}>
     */
    public function status(): array
    {
        $stmt = $this->pdo->query("SELECT migration, batch FROM {$this->table} ORDER BY id ASC");
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[$row['migration']] = (int) $row['batch'];
        }

        $status = [];
        foreach ($this->all() as $name) {
            $status[] = [
                'name'    => $name,
                'applied' => isset($rows[$name]),
                'batch'   => $rows[$name] ?? null,
            ];
        }
        return $status;
    }

    /**
     * Run every pending migration in order.
     *
     * @return string[] Names of the migrations that were applied.
     */
    public function migrate(): array
    {
        $pending = $this->pending();
        if (empty($pending)) {
            return [];
        }

        $batch = $this->nextBatch();
        foreach ($pending as $name) {
            $migration = $this->load($name);
            $this->pdo->beginTransaction();
            try {
                $migration['up']($this->pdo);
                $stmt = $this->pdo->prepare(
                    "INSERT INTO {$this->table} (migration, batch) VALUES (:migration, :batch)"
                );
                $stmt->execute([':migration' => $name, ':batch' => $batch]);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw new RuntimeException("Migration {$name} failed: " . $e->getMessage(), 0, $e);
            }
        }
        return $pending;
    }

    /**
     * Roll back the last batch (or the last $steps batches).
     *
     * @param int $steps Number of batches to roll back.
     * @return string[] Names of the migrations that were rolled back.
     */
    public function rollback(int $steps = 1): array
    {
        if ($steps < 1) {
            throw new InvalidArgumentException('Steps must be at least 1.');
        }

        $stmt = $this->pdo->query(
            "SELECT DISTINCT batch FROM {$this->table} ORDER BY batch DESC LIMIT {$steps}"
        );
        $batches = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        if (empty($batches)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($batches), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT migration FROM {$this->table} WHERE batch IN ({$placeholders}) ORDER BY id DESC"
        );
        $stmt->execute($batches);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        foreach ($names as $name) {
            $migration = $this->load($name);
            $this->pdo->beginTransaction();
            try {
                $migration['down']($this->pdo);
                $stmt = $this->pdo->prepare(
                    "DELETE FROM {$this->table} WHERE migration = :migration"
                );
                $stmt->execute([':migration' => $name]);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw new RuntimeException("Rollback of {$name} failed: " . $e->getMessage(), 0, $e);
            }
        }
        return $names;
    }

    /**
     * Scaffold a new migration file with a timestamp prefix.
     *
     * @param string $name Descriptive snake_case name, e.g. add_users_table.
     * @return string Full path of the created file.
     */
    public function create(string $name): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Migration name must be lowercase letters, digits and underscores.');
        }

        $filename = date('YmdHis') . '_' . $name . '.php';
        $path     = $this->migrationsDir . DIRECTORY_SEPARATOR . $filename;

        $stub = <<<'PHP'
<?php

return [
    'up' => function (PDO $pdo) {
        // $pdo->exec('CREATE TABLE ...');
    },
    'down' => function (PDO $pdo) {
        // $pdo->exec('DROP TABLE ...');
    },
];
PHP;
        file_put_contents($path, $stub . "\n");
        return $path;
    }

    /**
     * Next batch number (one higher than the highest recorded batch).
     */
    private function nextBatch(): int
    {
        $stmt = $this->pdo->query("SELECT MAX(batch) AS max_batch FROM {$this->table}");
        $max  = $stmt->fetchColumn();
        return (false === $max || null === $max) ? 1 : ((int) $max + 1);
    }

    /**
     * Load and validate one migration file.
     *
     * @param string $name Migration name without the .php extension.
     * @return array{up: callable, down: callable}
     */
    private function load(string $name): array
    {
        $path = $this->migrationsDir . DIRECTORY_SEPARATOR . $name . '.php';
        if (!is_file($path)) {
            throw new RuntimeException("Migration file missing: {$path}");
        }

        $migration = require $path;
        if (!is_array($migration) || !isset($migration['up'], $migration['down'])
            || !is_callable($migration['up']) || !is_callable($migration['down'])
        ) {
            throw new RuntimeException(
                "Migration {$name} must return ['up' => callable, 'down' => callable]."
            );
        }
        return $migration;
    }
}
