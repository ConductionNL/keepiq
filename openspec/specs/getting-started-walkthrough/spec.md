# Getting started walkthrough Specification

**Status**: done

**OpenSpec changes:** none. Written after the fact on 7 October 2026 to describe the code on development (parity row clients-17).

## Purpose
A user who opens Keepiq for the first time gets a short guided tour of the main screens. Keepiq declares the tour in its manifest and decides when it may show; the tour itself is drawn by `CnWalkthrough` from `@conduction/nextcloud-vue`, which Keepiq consumes. Code: `src/manifest.json` (the `walkthrough` block), `src/router/guards.js` (`manifestForLockState`), `src/main.js` (the root render), `src/components/KeepiqAppNav/KeepiqAppNav.vue` (`data-cn-route` on each menu entry) and `lib/Controller/PreferencesController.php` (the completion preference).

## Requirements

### Requirement: The manifest declares a first-visit tour

Keepiq SHALL declare one tour, `keepiq:getting-started`, in the `walkthrough` block of `src/manifest.json`, with `trigger: "first-visit"` and `completionConfigKey: "walkthrough_completed_version"`. The tour MUST open with a welcome step and then ask the user to open Dashboard, All secrets, Features and roadmap, Reports and Flows from the menu, each step advancing when the user reaches that route. The Flows step MUST let the user move on without opening Flows.

#### Scenario: A first visit starts the tour

@e2e exclude The e2e global setup (tests/e2e/global-setup.ts) suppresses the tour for every automated run, so no browser test sees it; the tour logic is CnWalkthrough's, tested in @conduction/nextcloud-vue.

- **WHEN** a user who has never finished the tour opens Keepiq with the vault unlocked
- **THEN** the welcome step of `keepiq:getting-started` SHALL appear
- **AND** each later step SHALL point at its menu entry and advance when the user opens that page

#### Scenario: A user skips the last step

@e2e exclude Suppressed in e2e runs by tests/e2e/global-setup.ts; `allowManualNext` on the go-flows step in src/manifest.json is declarative.

- **WHEN** the tour reaches the Flows step
- **THEN** the user MAY continue without opening Flows

### Requirement: Tour steps find their menu entries

Every menu entry that Keepiq renders in its own navigation SHALL carry `data-cn-route` set to its route name, so the tour can resolve a step whose target is `{kind: "nav-item", ref: <route>}`.

#### Scenario: A nav-item step highlights its entry

@e2e exclude Suppressed in e2e runs by tests/e2e/global-setup.ts; the attribute is rendered at src/components/KeepiqAppNav/KeepiqAppNav.vue:52, :179 and :219.

- **WHEN** the tour shows the step that targets `SecretList`
- **THEN** the step SHALL anchor to the menu entry rendered with `data-cn-route="SecretList"`

### Requirement: The tour waits for an unlocked vault

Keepiq SHALL withhold the `walkthrough` block from the manifest it passes to the app shell while the vault is locked, and SHALL pass it again as soon as the vault is unlocked. A missing session store or a lock flag that is not a boolean MUST count as locked. While locked, the shell MUST NOT request the completion preference.

#### Scenario: The lock screen shows no tour

@e2e exclude Covered by vitest tests/router/guards.spec.js 'withholds the walkthrough while the vault is locked' and by tests/e2e/workflows/vault-unlock.spec.ts, which fails on any preference request behind the lock screen.

- **WHEN** a user opens Keepiq and the vault is locked
- **THEN** the manifest passed to the shell SHALL have no `walkthrough` block
- **AND** no request to `/api/preferences/walkthrough_completed_version` SHALL be sent

#### Scenario: Unlocking brings the tour

@e2e exclude Covered by vitest tests/router/guards.spec.js 'offers the walkthrough once the vault is unlocked'; src/main.js:236 reads the lock state in the root render, so the manifest updates on unlock.

- **WHEN** the user unlocks the vault on a first visit
- **THEN** the shell SHALL receive the manifest with its `walkthrough` block and the tour SHALL start

### Requirement: Completion is stored per user

Keepiq SHALL serve `GET` and `PUT /api/preferences/{key}` for the signed-in user only. The key MUST be lower-cased and reduced to `[a-z0-9-]`, at most 64 characters, so `walkthrough_completed_version` is stored as the user value `pref_walkthroughcompletedversion`, the key OpenRegister's AppHost used before. A value over 4 KB MUST be refused with 400, and an empty value MUST delete the preference. A finished tour MUST NOT start again for that user.

#### Scenario: Completion survives a reload

@e2e exclude Covered by PHPUnit tests/Unit/Controller/AppShellControllersTest.php testThePreferenceRoundTripsUnderTheLegacyKey; the PUT itself comes from CnWalkthrough in @conduction/nextcloud-vue.

- **WHEN** the user finishes the tour and the shell stores the version under `walkthrough_completed_version`
- **THEN** a later `GET /api/preferences/walkthrough_completed_version` SHALL return that version
- **AND** the tour SHALL NOT start on the next visit

#### Scenario: Another user still gets the tour

@e2e exclude Covered by PHPUnit tests/Unit/Controller/AppShellControllersTest.php testPreferencesArePerUser.

- **WHEN** alice has finished the tour and bob opens Keepiq for the first time
- **THEN** bob's `GET /api/preferences/walkthrough_completed_version` SHALL return `{"value": null}`
