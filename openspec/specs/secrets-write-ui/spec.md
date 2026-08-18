# secrets-write-ui Specification

**Status**: in-progress

**OpenSpec changes:**
- `implement-secrets-write-ui` (2026-06-11) — Original write UI: create/edit dialogs, folder create + move, public-link share, modal isolation, keyboard-operable rows
- `owner-editable-additional-fields` (2026-08-18) — An owner could SEE additional fields but never write one: the whole stack supports them (controller, service, store encrypt/decrypt, SecretDetail renders them) except SecretCreateDialog and SecretEditDialog, which reference them nowhere. The only writers were the write-for-application dialog, import, and an external recipient filling a secret request — so obtaining one on your own secret meant asking a stranger to submit it. Both requirements below enumerated the collectable fields and omitted this one, which is how it shipped

## Purpose
TBD - created by archiving change implement-secrets-write-ui. Update Purpose after archive.

## Requirements
### Requirement: Create a Secret from the UI
The system MUST provide a dialog reachable from the vault view that lets the user
create a new secret. The dialog MUST collect a name (required), a secret type
(from the seeded type catalogue), the secret value (required), and optional URL
and login fields. The optional target folder MUST default to the folder the user
is currently viewing.

On submit, the browser MUST encrypt the secret value (and the login, when present)
with the owner's suite certificate via the existing `importPublicKey` +
`rsaEncrypt` crypto BEFORE the request leaves the page, then POST the ciphertext
through `secret.createSecret`. The server MUST never receive the plaintext value.
The dialog MUST be blocked (disabled) while the vault is locked.

#### Scenario: Create a secret with required fields
- **WHEN** the user opens the "New secret" dialog, enters a name and a secret value, and submits
- **THEN** the browser MUST RSA-encrypt the value with the suite certificate
- **THEN** the system MUST POST the ciphertext and create the secret (HTTP 201)
- **THEN** the new secret MUST appear in the vault list and its value MUST round-trip: opening it and decrypting MUST return the exact value entered

#### Scenario: Create a secret inside the current folder
- **WHEN** the user is viewing a folder and creates a secret
- **THEN** the dialog's folder field MUST default to that folder and the created secret MUST persist that `folderId`

#### Scenario: Name and value are required
- **WHEN** the user submits with an empty name or empty value
- **THEN** the submit control MUST be disabled and no request MUST be sent

### Requirement: Edit a Secret from the UI
The system MUST provide an "Edit" affordance on the secret detail view that opens a
dialog pre-filled with the secret's current (decrypted) fields. The user MUST be
able to change the name, type, URL, login, and secret value. Only changed
sensitive fields (value / login) MUST be re-encrypted client-side before the PUT
via `secret.updateSecret`; metadata-only edits (name / type / URL) MUST NOT require
re-encryption.

#### Scenario: Edit a secret's value
- **WHEN** the user edits a secret, changes the value, and saves
- **THEN** the browser MUST RSA-encrypt the new value and PUT it
- **THEN** re-opening the secret and decrypting MUST return the updated value

#### Scenario: Edit metadata only
- **WHEN** the user changes only the name and saves
- **THEN** the system MUST persist the new name and MUST NOT alter the stored ciphertext

### Requirement: Create a Folder and Move a Secret
The system MUST provide a "New folder" affordance in the vault view that creates a
folder (optionally under a parent) via `folder.createFolder`, and a "Move"
affordance on the secret detail view that moves the secret into a chosen folder
(or to the vault root) via `secret.updateSecret({ folderId })`.

#### Scenario: Create a folder
- **WHEN** the user opens "New folder", enters a name, and submits
- **THEN** the system MUST create the folder (HTTP 200) and it MUST appear in the folder tree

#### Scenario: Move a secret into a folder
- **WHEN** the user opens "Move" on a secret and selects a folder
- **THEN** the system MUST persist the secret's new `folderId`
- **THEN** the secret MUST appear under that folder's filter in the vault list

### Requirement: Share a Secret via a Public Link
The system MUST provide a "Share" affordance on the secret detail view that opens a
dialog to create a password-protected public link share. The dialog MUST let the
user pick a usage limit (1–10, default 1). On create, the browser MUST decrypt the
secret, build a snapshot of its sensitive fields, generate a one-time link
password, derive an AES-256 key via Argon2id, encrypt the snapshot client-side,
and POST only the encrypted blob via `linkShare.createLinkShare`. The dialog MUST
display the resulting link URL and the generated password EXACTLY ONCE, each with
a copy control. The link password MUST NOT be sent to or stored on the server.

The dialog MUST list existing active link shares for the secret and let the owner
revoke one.

#### Scenario: Create a public link share
- **WHEN** the owner opens "Share", chooses a usage limit, and creates the link
- **THEN** the browser MUST encrypt the snapshot with an Argon2id-derived key and POST only the blob
- **THEN** the dialog MUST show the link URL and the one-time password with copy buttons
- **THEN** the share token MUST resolve via the public endpoint `GET /api/v1/public/link-shares/{token}`

#### Scenario: Revoke a link share
- **WHEN** the owner revokes an existing link share from the dialog
- **THEN** the system MUST delete it and it MUST disappear from the list

#### Scenario: User-to-user sharing is deferred
- **WHEN** the user opens the share dialog
- **THEN** a user-to-user share affordance MAY be shown as disabled / "coming soon"
- **THEN** it MUST NOT issue any request until the `implement-user-sharing` backend exists

### Requirement: Dialogs Honour Modal Isolation and Registry Dispatch
Every write dialog MUST live in its own `.vue` file under `src/dialogs/` (ADR-004
modal-isolation) and MUST be registered in `src/registry.js` as a `kind:"modal"`
entry (ADR-036). Affordances MUST open dialogs through the injected
`cnOpenModal(key, props)` dispatcher provided by `CnAppRoot`, forwarding the
target secret / folder context as props.

#### Scenario: Modal opened via registry dispatcher
- **WHEN** the user clicks a write affordance
- **THEN** the corresponding registry-registered modal MUST mount via `cnOpenModal`
- **THEN** closing the dialog MUST emit `close` and unmount it

### Requirement: Secret List Rows MUST Be Keyboard-Operable

The secret row rendered by `SecretListItem.vue` for every entry in the vault list MUST expose a real interactive semantic (a native `<button type="button">`, or a `<div>` carrying `role="button"`, `tabindex="0"`, and an accessible `aria-label` built from the secret's name) instead of a bare non-interactive `<div>` with only a mouse click handler. The row MUST be reachable via `Tab` and MUST open the secret's detail view when activated via `Enter` or `Space`, in addition to the existing mouse click. This satisfies WCAG 2.1 AA Success Criteria 2.1.1 (Keyboard) and 4.1.2 (Name, Role, Value), and ADR-010's mandatory keyboard-navigable requirement.

The row MUST display a visible focus indicator using Nextcloud focus-ring CSS custom properties (no hardcoded colors) when it receives keyboard focus via `:focus-visible`.

The inner copy-password control MUST remain independently focusable, and activating it via mouse or keyboard MUST NOT also trigger the row's `open` navigation.

#### Scenario: Opening a secret row via keyboard only

- **GIVEN** the user is tabbing through the vault list with no mouse input
- **WHEN** the user presses `Tab` until a secret row receives focus and then presses `Enter`
- **THEN** the system MUST emit the row's `open` event and navigate to that secret's detail view

#### Scenario: Space also activates a focused row

- **GIVEN** a secret row has keyboard focus
- **WHEN** the user presses `Space`
- **THEN** the system MUST emit the row's `open` event exactly once, matching the click behaviour

#### Scenario: Focused row shows a visible focus indicator

- **GIVEN** a secret row receives keyboard focus
- **WHEN** the row is rendered in that focused state
- **THEN** the system MUST render a `:focus-visible` outline using an NC CSS custom property, with no hardcoded color value

#### Scenario: Copy control does not trigger row navigation

- **GIVEN** a secret row's inner copy-password control has keyboard focus
- **WHEN** the user activates it via `Enter` or `Space`
- **THEN** the system MUST copy the password and MUST NOT also emit the row's `open` event

