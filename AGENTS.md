# Production database operations

- Express every schema change as a Laravel migration. Apply and verify it locally first.
- Never change Neon during ordinary development, builds, application startup, or deployment.
- Obtain the user's explicit approval for each production migration or seeding operation. Earlier approval for another operation is not approval for a new one.
- Use `scripts/production-db.ps1` for manual production migrations and seeders. Do not answer its interactive confirmation on behalf of the user or pipe an automatic confirmation into it.
- Without explicit approval, limit production-operation verification to `-DryRun`, offline mocked tests, and disposable local databases. Never use real Neon credentials for those tests.
- Never print, commit, or copy `backend/.env.production.local` into an image. Keep credentials out of command-line arguments and logs.
- Do not use `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, or schema-drop commands against production.
- Review migration `up()` methods and selected seeders before approval: an allowlisted Artisan command cannot make arbitrary application code non-destructive.
