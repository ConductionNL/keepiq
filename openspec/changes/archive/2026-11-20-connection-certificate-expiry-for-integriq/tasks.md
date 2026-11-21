# Tasks: connection-certificate-expiry-for-integriq (keepiq)

Spec only in this PR. Tier V1. integriq's monitor waits on this.

## 1. Expiry at mint

- [x] 1.1 The broker mint path accepts `notAfter` for a certificate secret and stores it as `expires_at`.
  - spec_ref: `specs/certificate-lifecycle/spec.md#requirement-a-certificate-minted-for-a-connection-carries-its-expiry`
  - test: `vendor/bin/phpunit --no-coverage --filter Mint`

## 2. Answer integriq

- [x] 2.1 `CredentialExpiryRequestedEvent` and its listener: organisation and consumer checks, two fields only, no answer otherwise.
  - spec_ref: `specs/integration-boundary/spec.md#requirement-integriq-may-learn-when-its-own-connection-certificate-expires`
  - files: `lib/Event/CredentialExpiryRequestedEvent.php`, `lib/Listener/CredentialExpiryRequestedListener.php`, `lib/AppInfo/Application.php`, tests
  - acceptance: a test proves a personal credential and another organisation's credential get no answer

## 3. Verify

- [x] 3.1 `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run test:l10n` once before push. Done on 20 Nov 2026 over development with the change merged (#1899): lint, PHPCS, Psalm, PHPStan and PHPUnit (5749 tests, 0 failed) pass; PHPMD reports only the existing `ExcessiveClassComplexity` of `SecretRequestService` (52 > 50), which is also on development and not in this change's files; `npm run lint` 0 errors; `npm run test:l10n` OK.
