# AGENTS.md

Guidance for coding agents working in this repository. Every command below is defined in `composer.json`, `package.json` or `docs/package.json`.

## What this app is

Keepiq is an encrypted secrets manager for Nextcloud: a password manager and key store for users and applications. It stores and shares secrets (passwords, API keys, certificates) in its own encrypted database tables, using RSA/AES encryption backed by a private Certificate Authority (see [README.md](README.md) and [appinfo/info.xml](appinfo/info.xml)).

## Setup

The local development stack is [docker-compose.yml](docker-compose.yml): PostgreSQL plus Nextcloud on http://localhost:8080 (admin/admin), with this repo mounted as the `keepiq` app. OpenRegister is optional; a checkout at `../openregister` is enabled automatically when present.

```bash
composer install && npm install && npm run build
docker compose up -d
```

`docker/docker-compose.yml` is a separate evaluation image for trying the app out, not the development stack. Installing dependencies also sets `git config core.hooksPath .githooks`.

## Build

```bash
npm run build        # production frontend build (webpack)
npm run dev          # development build
npm run watch        # development build in watch mode
npm run l10n:build   # regenerate l10n/<locale>.js from l10n/<locale>.json
```

The documentation site is Docusaurus in `docs/`: `cd docs && npm run build`.

## Test

```bash
composer test:unit   # PHPUnit, tests/Unit (phpunit.xml)
npm run test         # Vitest (vitest.config.js)
npm run test:coverage
```

Playwright end-to-end tests live in `tests/e2e/` and use [playwright.config.ts](playwright.config.ts). They need a running Nextcloud and have no default URL:

```bash
PLAYWRIGHT_BASE_URL=http://localhost:8080 npx playwright test
```

## Lint and quality

```bash
composer check:strict  # PHP lint, PHPCS, PHPMD, Psalm, PHPStan and PHPUnit
composer check         # faster subset: lint, PHPCS, Psalm, unit tests
composer cs:fix        # auto-fix PHP style
npm run lint           # ESLint on src, tests, scripts
npm run stylelint      # CSS and Vue styles
npm run format         # Prettier check
npm run check:manifest # validate src/manifest.json
npm run test:l10n      # t('keepiq', ...) strings are in l10n/en.json and every locale is complete
npm run check:l10n-js  # l10n/<locale>.js matches its .json
```

Translations: edit only `l10n/<locale>.json`; the `.js` files are generated. The README section "Translations" describes the full procedure.

## OpenSpec flow

Features are specified in `openspec/` (see [openspec/README.md](openspec/README.md)):

1. A change starts in `openspec/changes/<name>/` with `proposal.md`, `design.md`, `tasks.md` and delta specs under `specs/<capability>/spec.md`.
2. Once implemented, its specs are merged into `openspec/specs/<capability>/`, the source of truth for what the app does.
3. The completed change moves to `openspec/changes/archive/YYYY-MM-DD-<name>/`.

Project rules for proposals, specs and designs are in [openspec/config.yaml](openspec/config.yaml).

## Architecture decisions

App-specific Architectural Decision Records live in [openspec/architecture/](openspec/architecture/) as `adr-NNN-<slug>.md`, indexed in its README. Per-change design decisions are recorded in each change's `design.md`. The broader architecture and data model are in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Repo conventions

- Keepiq owns its own database tables and does not store secrets in OpenRegister; it needs no other app (ADR-001, ADR-004, ADR-006 in `openspec/architecture/`).
- Pages are manifest configuration: `src/manifest.json` declares the pages, menu and deep links, and `src/main.js` builds the router from its `pages`.
- User-visible frontend strings are wrapped as `t('keepiq', '...')` and listed in `l10n/en.json`.

## Commit format

Conventional Commits, for example `feat(vault): ...`, `fix: ...`, `docs: ...`, `test(e2e): ...`, `l10n: ...`, as seen in the git history. Feature branches merge into `development`.
