# Vault defaults Specification

**Status**: done

**OpenSpec changes:**
- [vault-defaults-and-recently-used-widget](../../changes/archive/2026-09-30-vault-defaults-and-recently-used-widget/) _(archived 2026-09-30)_

## Purpose
A user picks the item type a new secret starts as and the view the secret list opens in, once, in the personal settings. Parity row vault-20.

## Requirements

### Requirement: Default item type and view

The system MUST let a user choose a default item type and a default list view in personal settings and MUST persist both through the existing preferences endpoint. The create dialog MUST preselect the saved type, and the secret list MUST open in the saved view. A saved type that no longer exists MUST fall back to `login`.

#### Scenario: A user sets SSH Key as the default type

@e2e exclude Needs the personal settings dialog and a create dialog on a live instance with a vault; covered by vitest tests/components/DefaultsSection.spec.js 'saves the picked default type' and tests/dialogs/SecretCreateDialog.defaultType.spec.js 'opens with the saved default type selected'.

- **GIVEN** a signed-in user on personal settings
- **WHEN** the user picks SSH Key as the default item type and saves, then clicks New secret
- **THEN** the create dialog opens with SSH Key selected

#### Scenario: The list opens in the saved view

@e2e exclude Covered by vitest tests/views/SecretList.defaultView.spec.js 'opens in the saved view' and 'loads the saved preferences when the list mounts'; the Playwright browser service is not available to this lane.

- **GIVEN** a user who saved the cards view
- **WHEN** the user opens /secrets in a new session
- **THEN** the list renders in the cards view

#### Scenario: A deleted type falls back

@e2e exclude Needs an administrator to delete a type on a live instance; covered by vitest tests/dialogs/SecretCreateDialog.defaultType.spec.js 'preselects Login when the saved type was deleted'.

- **GIVEN** a user whose saved default type was deleted by an administrator
- **WHEN** the user clicks New secret
- **THEN** the dialog preselects Login and shows no error
