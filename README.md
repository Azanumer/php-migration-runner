# PHP Migration Runner

Dependency-free database migration runner for plain PHP apps — the missing piece every hand-rolled PDO project ends up reinventing. No framework, no Composer, one class.

## Why

If you write PHP without Laravel or Symfony, schema changes are usually a pile of `.sql` files run by hand, in the wrong order, twice. This runner gives you timestamped migrations, transactional up/down, batch tracking and rollbacks — the same workflow, with zero dependencies.

## Features

- **Timestamped migrations** — `YYYYMMDDHHMMSS_name.php`, applied in order, never twice.
- **Transactional** — each migration runs inside a transaction; a failure rolls back the schema change *and* the tracking row.
- **Batch rollbacks** — `rollback()` reverts exactly the last batch (or the last N batches).
- **MySQL, PostgreSQL and SQLite** — tracking table is created per-driver.
- **Scaffolding** — `migrate.php create add_users_table` writes a ready-to-fill stub.
- **CLI included** — `status`, `migrate`, `rollback --steps=N`, `create`, with DB credentials from flags or `DB_DSN`/`DB_USER`/`DB_PASS` env vars.

## Usage

```php
use Azanumer\MigrationRunner\MigrationRunner;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'root', '');
$runner = new MigrationRunner($pdo, __DIR__ . '/migrations');

$runner->migrate();            // apply everything pending
$runner->status();             // every migration + applied/pending
$runner->rollback();           // undo the last batch
$runner->create('add_posts');  // scaffold a new migration file
```

A migration file returns `up`/`down` callables:

```php
return [
    'up'   => function (PDO $pdo) { $pdo->exec('CREATE TABLE posts (...)'); },
    'down' => function (PDO $pdo) { $pdo->exec('DROP TABLE posts'); },
];
```

## CLI

```bash
php migrate.php status   --dir=./migrations --dsn="mysql:host=localhost;dbname=app" --user=root
php migrate.php migrate  --dir=./migrations --dsn="mysql:host=localhost;dbname=app" --user=root
php migrate.php rollback --dir=./migrations --dsn="mysql:host=localhost;dbname=app" --user=root --steps=1
php migrate.php create add_users_table --dir=./migrations
```

## Example

`php examples/migration-example.php` runs the bundled example migration against an in-memory SQLite database — migrate, status, rollback — so you can see the whole flow without a server.

## Requirements

PHP 7.4+, PDO with a MySQL, PostgreSQL or SQLite driver.
