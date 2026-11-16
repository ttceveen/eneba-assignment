# Historical Docker setup report

**Recorded baseline: 10 November 2026.** This report describes initial environment bring-up, before catalog, authentication and purchases were implemented. Use the [current README](../README.md) for setup and features. Counts and configuration observations below are historical, not current assertions.

## Initial result

The backend, frontend and PostgreSQL images built and all three services were healthy. Recorded versions were PHP 8.4.27, Laravel 13.40.0, Node.js 24.22.2 and Vite 8.3.7. Backend/frontend ran as UID 1000 with bind-mounted source and separate Linux dependency volumes; PostgreSQL data used a persistent volume.

Frontend localhost:5173, backend localhost:8000 and `/api/health` returned 200; health executed a real PostgreSQL SELECT 1. Windows `curl.exe` confirmed accessibility. Three original Laravel migrations applied, and a repeat migrate had nothing to apply. Composer validation/platform checks passed. The initial scaffold had two tests/two assertions; TypeScript/Vite build and lint passed. These were infrastructure checks, not payment/product tests.

At that point PostgreSQL had no published host port. The current Compose file now explicitly publishes localhost:5433; containers still use postgres:5432. Payment configuration, demo inventory and purchase functionality were added in later milestones.

## Problems encountered during bring-up

- Local execution/sandbox restrictions required using the available elevated shell/Docker path for setup commands.
- Windows-mounted source and Linux dependency volumes required consistent UID 1000 ownership.
- Browser integration could not be launched through the original GUI tool because of its working-directory error; an existing headless Chromium/Playwright path and Windows curl were used instead.
- Missing Chromium libraries were extracted into a temporary test-tool directory; no global browser-library installation was required.
- Laravel and React were initially absent and were initialized with the selected PHP/Laravel and Node stack. Dependencies were locked; no extra infrastructure service or payment provider was part of that original setup.

## Files and operations established

The initial work created the Laravel/backend and Vite/frontend scaffolds, development Dockerfiles/entrypoint, Compose services, env examples, health route, frontend API URL/CORS wiring, documentation and an environment screenshot. Generated framework scaffold files were not individual feature implementations. Later tasks extended that foundation rather than replacing its services.

The normal development lifecycle remained Compose build/up, health verification, Artisan migrations, frontend build/lint, logs and shutdown preserving named volumes. Automatic local startup installs locked dependencies, generates a missing local key and applies migrations; it does not seed marketplace data. The production image uses a separate non-root entrypoint without automatic migrations/key generation.

## Current references

- [Local setup and demo data](../README.md#local-setup)
- [Testing and isolation](testing.md)
- [Production deployment](production-deployment.md)
- [Production database authorization](production-database.md)
- [Historical technical audit](technical-audit.md)

The original prompt and AI session export remain separate historical records. They were not rewritten as current documentation.
