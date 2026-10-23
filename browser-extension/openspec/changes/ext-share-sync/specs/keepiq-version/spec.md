## ADDED Requirements

### Requirement: A minimum Keepiq version
The extension SHALL read Keepiq's version from `version` in the answer of the public `GET /index.php/apps/keepiq/api/health`, which carries it with a 503 too, and MUST add or re-login an account only on a Keepiq of at least `MIN_KEEPIQ_VERSION` in `src/api/keepiq-version.ts`, 0.3.12, the first with share-save; below it the form MUST say "Keepiq on <host> is too old for this extension, which needs version 0.3.12 or later. Ask your administrator to update Keepiq." Versions compare by `major.minor.patch` alone, so a beta counts as its release. A build whose prerelease starts with `unstable` MUST always pass, since a development checkout reports an old version; a missing or unreadable version MUST fail. The version MUST be kept on the account record, read again by every full sync, and kept as it was when the answer names none. Every released Keepiq version from the minimum on MUST stay supported for five years from its release date, so the minimum MUST NOT pass a version before then; supported versions are listed in `docs/browser-extension/supported-keepiq-versions.md`.

#### Scenario: Adding an account on an older Keepiq
- **GIVEN** the server runs Keepiq 0.3.11
- **WHEN** Alice adds her account
- **THEN** nothing MUST be stored and the form MUST say "Keepiq on cloud.example.org is too old for this extension, which needs version 0.3.12 or later. Ask your administrator to update Keepiq."

#### Scenario: A development server
- **GIVEN** the server runs a checkout reporting `0.3.9-unstable.20261019140000`
- **WHEN** Alice adds her account
- **THEN** it MUST be added

### Requirement: Unlock checks the Keepiq version it last read
The extension SHALL, before unlocking, read the version again when the stored one is missing or below the minimum, since the server may have been updated, and MUST refuse the unlock with `keepiq_outdated` when it is below the minimum. Offline, a stored version below the minimum MUST refuse the unlock and a missing one MUST NOT. A stored version that meets the minimum MUST NOT cost a request.

#### Scenario: Keepiq was updated
- **GIVEN** Alice's account stored version 0.3.11 and the server now runs 0.3.12
- **WHEN** she unlocks
- **THEN** the vault MUST unlock and the account MUST store 0.3.12

### Requirement: Features of a newer Keepiq
The extension SHALL list each feature that needs a Keepiq newer than the minimum in `KEEPIQ_FEATURES`, by the first version that has it, and MUST offer it only where `keepiqSupports` says the account's stored version has it, decided in the background. A supported version without it MUST get the older path or a refusal naming the version it needs, never a request to a route it lacks. An entry MUST be removed once the minimum reaches it.

### Requirement: A route this Keepiq lacks
A share-save Nextcloud answers with its HTML 404, as an `unstable` build from before share-save does, SHALL fail the save with `keepiq_outdated`, and the form MUST say "Your Keepiq server is too old to save this change. Nothing was saved; ask your administrator to update Keepiq to version 0.3.12 or later." A 404 with a JSON body stays `not_found`.

### Requirement: An unreadable vault key
An unlock whose master password opens the vault key, but whose key is not exactly one PEM block with nothing around it and no bytes after its DER, or does not import, SHALL fail with `key_unreadable`, and the unlock screen MUST say "Your vault key in Keepiq could not be read, so this browser can't unlock it. Tell your Keepiq administrator."
