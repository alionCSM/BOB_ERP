# Migrations

Managed by [Phinx](https://book.cakephp.org/phinx/0/en/index.html). Configuration lives in `phinx.php` at the repo root; it reads DB credentials from the application's `.env` file so each environment migrates against its own database.

## Running

```bash
# Apply all pending migrations
composer migrate
# or:
vendor/bin/phinx migrate

# Show what's applied vs pending
composer migrate:status

# Rollback the most recent migration
composer migrate:rollback
```

## Authoring a new migration

```bash
vendor/bin/phinx create AddSomethingToSomeTable
```

Phinx generates `db/migrations/<timestamp>_add_something_to_some_table.php` containing an `AbstractMigration` subclass. Implement either:

- `change()` — Phinx works out the down direction automatically. Use this for reversible schema changes (`addColumn`, `addIndex`, `addForeignKey`, etc.).
- `up()` + `down()` — for irreversible or complex changes (data migrations, raw SQL with side effects).

Inside the migration use Phinx's table builder DSL:

```php
$this->table('bb_example')
    ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
    ->addIndex(['name'])
    ->create();
```

Or drop into raw SQL when needed:

```php
$this->execute("UPDATE bb_users SET ... WHERE ...");
```

## Conventions

- **Use `change()` whenever possible** — it's automatically reversible.
- **Don't edit a migration after it's been applied anywhere.** Phinx tracks applied migrations by their numeric timestamp; if the file content changes it won't be re-run, but teammates' DBs will diverge from yours. Add a follow-up migration instead.
- **Class name = camel-cased filename suffix.** Phinx enforces this.
- **One logical change per migration.** Easier to roll back, easier to review.

## Tracking table

Phinx maintains `phinxlog` automatically. Don't touch it manually unless you really know what you're doing — `vendor/bin/phinx breakpoint` and `vendor/bin/phinx status` are the safe ways to inspect/manipulate state.
