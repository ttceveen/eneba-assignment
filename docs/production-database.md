# Authorized production database operations

All schema changes use Laravel migrations. Review and verify them against local/disposable PostgreSQL first. Production/Neon changes do not run during development, build, application startup or ordinary deployment. Each migration or selected seeder requires explicit user authorization.

## Prerequisites

Use Docker Desktop and Windows PowerShell 5.1+ with the Docker CLI available. Review migration `up()` methods and the intended seeder, and confirm a suitable recovery point/backup. An allowlisted Artisan command does not prove arbitrary PHP inside it is safe.

Use the [testing guide](testing.md) for SQLite in-memory and separate PostgreSQL suites. Never point `RefreshDatabase` or the disposable test configurations at working PostgreSQL or Neon. Do not delete data to make a migration/rollback succeed.

## Private configuration

Prepare `backend/.env.production.local` privately, or an explicitly selected `-EnvFile`. Required script variables are `APP_ENV`, `APP_KEY`, `DB_CONNECTION` and `DATABASE_URL`; use the existing production key and the production/pgsql modes checked by the script. The file must use uppercase `KEY=value` lines without `export`, surrounding value quotes or spaces around `=`.

The script accepts a direct Neon host ending in `.neon.tech`, not a `-pooler` host. The URL must require TLS and mandatory channel binding. URL-encode credential characters. Remove conflicting DB_URL and separate DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD entries. Preserve other application settings, including the independent fingerprint secret when writing encrypted inventory.

Do not disclose the file or its values. Its `.env*` name is excluded from the production Docker build context. The script validates configuration without printing credentials and does not configure cloud services.

## Migrations

From the repository root:

```powershell
.\scripts\production-db.ps1 -Action Migrate -DryRun
.\scripts\production-db.ps1 -Action Migrate
```

DryRun validates the private file and displays a plan; it does not invoke Docker or connect to the DB. The actual action requires the exact manually entered confirmation `MIGRATE NEON`. A different answer cancels before building or connecting. After confirmation it builds the current production image and runs `migrate --force --no-interaction` in a temporary non-root container.

## Selected seeders

```powershell
.\scripts\production-db.ps1 -Action Seed -Seeder GameSeeder -DryRun
.\scripts\production-db.ps1 -Action Seed -Seeder GameSeeder
```

This separate action requires `SEED GameSeeder NEON` and runs only the named seeder, not migrations. For another class, explicitly choose it and review its local behavior first. GameSeeder preserves existing games; the generic DatabaseSeeder uses dev-only factory support and is not an appropriate default production bootstrap. See [demo inventory restrictions](local-marketplace-offers.md).

There is no automatic confirmation option, arbitrary Artisan action or destructive migration action. Never pipe confirmation text into the script. Each retry is a separately authorized operation after diagnosing the prior result; do not assume multiple migration files/seed operations are one atomic release.

The script uses a private env file and suppresses raw Docker/Artisan output, including failure output, to avoid credential disclosure. It reports operation stages and exit codes. After a successful authorized action, verify the application through safe reads.

## Offline script tests

```powershell
.\scripts\tests\production-db.tests.ps1
```

These tests use synthetic env values and mock Docker/Read-Host. They verify DryRun, cancellation, exact confirmations/arguments, invalid configuration, failure propagation and output redaction without accessing a real env file or Neon. If necessary, apply a process-only execution-policy exception rather than changing global policy.

## Historical validation

The original workflow validation passed offline PowerShell 5.1 tests. Production Artisan behavior was also checked on a separate temporary PostgreSQL 17 TLS/SCRAM server: migrations applied and GameSeeder created 40 records, with repeat operations preserving them. A then-current SQLite smoke run had 13 tests/218 assertions. These are historical observations, not current suite counts or proof of a live Neon migration.

In that validation session the Windows shell could not find native `docker.exe`; the PowerShell-to-Docker path was mocked, while image/Artisan behavior was exercised through the available Linux Docker CLI. This documents the boundary of the historical check, not a claim about a reviewer's current installation.
