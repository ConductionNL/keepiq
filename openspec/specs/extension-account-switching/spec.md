# Extension account switching Specification

**Status**: done

**OpenSpec changes:**
- [clients-extension-unlock-lock-and-accounts](../../changes/archive/2026-10-02-clients-extension-unlock-lock-and-accounts/) _(archived 2026-10-02)_

## Purpose
One browser extension holds up to five paired Nextcloud accounts, on one or more servers, and switches between them from the popup header. Each account has its own keys, lock state, idle timer and settings, and filling uses the active account only. Parity row clients-21.

## Requirements

### Requirement: Up to five paired accounts in one extension

The extension MUST let a user pair up to five Nextcloud accounts, on the same or different servers, each with its own app password, lock state, idle timer and settings, and MUST refuse a sixth pairing with a clear message. An extension paired before this change MUST keep its pairing as the first account without asking the user to pair again.

#### Scenario: Work and personal servers side by side

- **GIVEN** a user who has paired `alice@cloud.work.example` in the extension
- **WHEN** they choose "Add account" in the popup and pair `alice@cloud.home.example`
- **THEN** the popup header MUST list both accounts
- **AND** the first account MUST keep its lock state

#### Scenario: An existing pairing survives the update

- **GIVEN** an extension paired under the old single-pairing storage
- **WHEN** the updated extension starts
- **THEN** the pairing MUST appear as the first account and the old storage key MUST be removed

### Requirement: Switching and isolation between accounts

The extension MUST show the active account in the popup header and switch to another account in one click. Matching, filling, the one-time code and save prompts MUST use the active account only. Key material, cached blobs and idle timers MUST be kept per account, and the worker MUST refuse to fill a secret id that did not come from the active account's own match.

#### Scenario: Switching changes the candidates

- **GIVEN** two unlocked accounts with different logins for `example.com`
- **WHEN** the user switches the active account in the popup header while on `example.com`
- **THEN** the candidate list MUST show only the newly active account's logins

#### Scenario: A cross-account fill is refused

- **GIVEN** a match result from account A
- **WHEN** a fill message arrives for one of those secret ids while account B is active
- **THEN** the worker MUST refuse the fill and decrypt nothing

#### Scenario: Each account locks on its own timer

- **GIVEN** account A with a 5 minute idle period and account B with a 60 minute idle period, both unlocked
- **WHEN** 5 idle minutes pass
- **THEN** account A MUST be locked and account B MUST stay unlocked
- **AND** an OS lock MUST lock both
