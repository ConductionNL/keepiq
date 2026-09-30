# Clients save prompt Specification

**Status**: done

**OpenSpec changes:**
- [clients-extension-save-prompt-and-passkey-origin](../../changes/archive/2026-09-30-clients-extension-save-prompt-and-passkey-origin/) _(archived 2026-09-30)_

## Purpose
After a login form is submitted the browser extension offers, in the page and at once, to save the login or to update the saved one whose password changed. Parity row clients-03.

## Requirements

### Requirement: Save or update prompt

After a login form is submitted, the extension MUST show a prompt on the page offering to save the login. When a saved login exists for the same origin and username with a different password, the prompt MUST offer Update and MUST update that secret instead of creating a new one. The prompt MUST NOT appear for a submit that matches an existing saved password, and MUST NOT be readable by page scripts.

#### Scenario: A new login is offered

@e2e exclude Needs the extension loaded in a browser against a paired instance; covered by vitest tests/extension/saveCapture.spec.js 'offers a save for another username or no saved login' and 'resolves with the action on a user click and removes itself'.

- **GIVEN** a user logged in to the extension who submits a login form on a site with no saved login
- **WHEN** the page reloads after the submit
- **THEN** a prompt offers Save, and Save creates one secret

#### Scenario: A changed password is offered as an update

@e2e exclude Covered by vitest tests/extension/saveCapture.spec.js 'offers an update with the id when the saved password changed'; the worker then calls the update path with that id (service-worker.js doCaptureDecision -> doSaveCapture).

- **GIVEN** a saved login for the same site and username
- **WHEN** the user submits a different password
- **THEN** the prompt offers Update and choosing it changes that secret without creating a duplicate

#### Scenario: An unchanged login is not offered

@e2e exclude Covered by vitest tests/extension/saveCapture.spec.js 'offers nothing when the password is unchanged'.

- **GIVEN** a saved login
- **WHEN** the user submits the same password
- **THEN** no prompt appears
