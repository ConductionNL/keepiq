# Nextcloud App Template

A starting point for building Nextcloud apps following [ConductionNL](https://github.com/ConductionNL) conventions.

## What's included

- **PHP scaffold** — `Application`, `DashboardController`, `AdminSettings`, `SettingsSection`
- **Vue 2 + Pinia frontend** — `App.vue`, router, settings store, object store
- **Admin settings page** — version card + configurable settings form
- **OpenRegister integration** — pre-wired object store for the OpenRegister data layer
- **Full quality pipeline** — PHPCS, PHPMD, Psalm, PHPStan, ESLint, Stylelint
- **CI/CD workflows** — code quality, beta/stable releases, branch protection, OpenSpec sync, issue triage
- **Custom PHPCS sniff** — enforces named parameters on all internal code calls

## Quick start

### 1. Use this template

Click **Use this template** on GitHub, or use the `/app-create` skill in the workspace.

### 2. Replace placeholders

| Placeholder | Replace with |
|---|---|
| `app-template` | Your app ID (kebab-case, e.g. `my-new-app`) |
| `app_template` | Snake-case variant (e.g. `my_new_app`) |
| `AppTemplate` | PascalCase namespace (e.g. `MyNewApp`) |
| `APP_TEMPLATE` | SCREAMING_SNAKE constant (e.g. `MY_NEW_APP`) |
| `Nextcloud App Template` | Human-readable name |
| `A template for creating new Nextcloud apps` | Your app description |

### 3. Install dependencies

```bash
composer install
npm install
```

### 4. Build frontend

```bash
npm run dev       # development build with watch
npm run build     # production build
```

### 5. Enable in Nextcloud

```bash
docker exec nextcloud php occ app:enable app-template
```

## Code quality

```bash
composer check:strict   # lint + phpcs + phpmd + psalm + phpstan + tests
composer cs:fix         # auto-fix PHPCS issues
npm run lint            # ESLint
npm run stylelint       # Stylelint
```

## Project structure

```
app-template/
├── appinfo/
│   ├── info.xml              # App metadata
│   └── routes.php            # API + SPA routes
├── lib/
│   ├── AppInfo/Application.php
│   ├── Controller/DashboardController.php
│   ├── Settings/AdminSettings.php
│   └── Sections/SettingsSection.php
├── templates/
│   ├── index.php             # Main SPA shell
│   └── settings/admin.php   # Admin settings shell
├── src/
│   ├── main.js               # Main app entry
│   ├── settings.js           # Admin settings entry
│   ├── App.vue               # Root component
│   ├── pinia.js              # Pinia instance
│   ├── router/index.js       # Vue Router
│   ├── store/
│   │   ├── store.js          # Store initializer
│   │   └── modules/
│   │       ├── settings.js   # Settings Pinia store
│   │       └── object.js     # Generic OpenRegister object store
│   ├── views/
│   │   ├── Dashboard.vue
│   │   └── settings/
│   │       ├── AdminRoot.vue
│   │       └── Settings.vue
│   └── assets/app.css
├── .github/workflows/        # CI/CD pipelines
├── phpcs-custom-sniffs/      # Named parameters enforcement
├── composer.json
├── package.json
├── webpack.config.js
├── psalm.xml
├── phpstan.neon
├── phpcs.xml
└── phpmd.xml
```

## Branches

| Branch | Purpose |
|---|---|
| `main` | Stable releases — triggers release workflow |
| `development` | Active development — auto-syncs to `beta` |
| `beta` | Beta releases |
| `documentation` | Docs site (GitHub Pages) |

## Requirements

- PHP 8.1+
- Nextcloud 28–33
- Node.js 20+, npm 10+
- [OpenRegister](https://github.com/ConductionNL/openregister) (runtime dependency)

## License

EUPL-1.2 — see [LICENSE](LICENSE)

**Support:** support@conduction.nl | [conduction.nl](https://conduction.nl)
