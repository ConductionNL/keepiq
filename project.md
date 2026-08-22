# Keepiq — Encrypted Secrets Manager

## Overview

Keepiq is an encrypted secrets manager for Nextcloud. It securely stores and shares secrets (passwords, API keys, certificates) for Nextcloud users and applications, using end-to-end RSA/AES encryption backed by a private Certificate Authority.

## Architecture

- **Type**: Nextcloud App (PHP backend + Vue 2 frontend)
- **Data layer**: Own database tables (Doctrine ORM + ISchemaWrapper migrations)
- **Pattern**: Thick backend — Keepiq owns all encrypted data; no OpenRegister, no n8n
- **Encryption**: RSA-4096 + AES-256 via OpenSSL; private CA with root and intermediate certificates
- **License**: EUPL-1.2

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.1+, Nextcloud AppFramework, OpenSSL |
| Frontend | Vue 2.7, Pinia, @nextcloud/vue, @conduction/nextcloud-vue |
| Data | PostgreSQL (own tables, encrypted fields) |
| Testing | PHPUnit (unit + integration), Newman (API) |
| Quality | PHPCS, PHPMD, Psalm, PHPStan, ESLint, Stylelint |

## Key Files

| File | Purpose |
|------|---------|
| `lib/AppInfo/Application.php` | App bootstrap, listener + repair registration |
| `lib/Controller/SettingsController.php` | Settings API endpoints |
| `lib/Service/SettingsService.php` | Settings business logic |
| `lib/Listener/DeepLinkRegistrationListener.php` | Registers deep link patterns with search |
| `lib/Repair/InitializeSettings.php` | Initialize settings on install/upgrade |
| `lib/Settings/keepiq_register.json` | Register schema definition |
| `src/App.vue` | App shell (navigation + routing) |
| `src/navigation/MainMenu.vue` | App navigation sidebar |
| `src/views/settings/UserSettings.vue` | User settings dialog |
| `openspec/config.yaml` | OpenSpec project configuration |
| `docs/ARCHITECTURE.md` | Architecture, data model, standards |
| `docs/FEATURES.md` | Feature analysis, competitive landscape |
| `docs/DESIGN-REFERENCES.md` | Design patterns, ASCII wireframes |

## Development Setup

See the workspace-level `.claude/docs/` for:
- `commands.md` — available Claude commands
- `testing.md` — testing workflows
- `app-lifecycle.md` — full development lifecycle

## Standards

This app follows all [Conduction app standards](../.claude/openspec/architecture/).
