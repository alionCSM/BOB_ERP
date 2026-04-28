# Migrations

Plain `.sql` files applied in lexicographic order by `bin/migrate.php`. Tracked in the `bb_migrations` table (created automatically on first run).

## Naming

`YYYY_MM_DD_NNN_short_description.sql` — date + 3-digit sequence keeps lexicographic order = chronological order, even when several land on the same day.

Example: `2026_04_29_001_login_attempts_username.sql`

## Running

```bash
php bin/migrate.php          # apply all pending
php bin/migrate.php status   # list applied + pending, do nothing
```

Each migration runs in its own transaction. Note that MySQL implicitly commits DDL (`ALTER`, `CREATE`, `DROP`), so a DDL statement that fails mid-file cannot be rolled back — split risky changes across separate migrations.

## Authoring rules

- **Forward-only.** No `down`/rollback. If you need to undo a migration, write a new one.
- **Idempotency-friendly when possible** — prefer `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS` (MySQL 8.0.29+). Without `IF NOT EXISTS`, re-running on a partially-applied DB will fail loudly, which is by design.
- **Don't edit a migration after it lands in main.** Once a teammate has applied it, the row in `bb_migrations` references that filename + state forever. Add a follow-up migration instead.
