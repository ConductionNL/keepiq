# adopt-connection-registry tasks

## 1. Declare

- [x] 1.1 Write `lib/Settings/connections.json` with `hibp` and `siem`.
- [x] 1.2 Give the Breach checking and SIEM audit export sections the ids the file links to.
- [x] 1.3 Guard the file in `tests/Unit/Settings/ConnectionsDeclarationTest.php`, against integriq's schema vendored in `tests/fixtures/Integriq/connections.schema.json`.

## 2. Page

- [x] 2.1 Add `src/manifest.d/80-connection-registry.json` with the page and its settings-gear menu entry.
- [x] 2.2 Add `src/services/connectionRegistry.js` with the two formatters and the Add integration handler.
- [x] 2.3 Wire the formatters and the handler in `src/App.vue`; register `PowerPlugOutline` in `src/icons.js`.
- [x] 2.4 Teach `KeepiqAppNav` the menu `query`, `permission: admin` and `visibleIf.appInstalled`, through `src/utils/navEntries.js`.
- [x] 2.5 Add the strings to `l10n/en` and `l10n/nl`.
- [x] 2.6 Cover it in `tests/vitest/connectionRegistry.spec.js` and `tests/vitest/navEntries.spec.js`.

## 3. Reports and refresh

- [x] 3.1 Add `lib/Service/Connection/ConnectionObservations.php` and `lib/Service/Connection/ConnectionReporter.php`.
- [x] 3.2 Refresh from the admin settings save in `SettingsController`, and pass the reporter in `DomainOverrideRegistrar`.
- [x] 3.3 Report range lookup outcomes from `BreachProxyController`.
- [x] 3.4 Refresh and report from `SiemSinkService` sink changes, and report drain outcomes from `SiemService::deliverDue()`.
- [x] 3.5 Add the integriq event stubs for PHPUnit, psalm and phpstan.
- [x] 3.6 Cover it in `ConnectionObservationsTest`, `ConnectionReporterTest`, `SettingsControllerConnectionRefreshTest`, `BreachProxyControllerConnectionReportTest` and `SiemConnectionReportCallersTest`.

## 4. End to end

- [x] 4.1 Write `tests/e2e/workflows/integrations-page.spec.ts`.
- [x] 4.2 Install integriq in the CI `additional-apps`.

## 5. After integriq ships

- [ ] 5.1 Run the e2e spec against an instance with both apps, then archive this change.
