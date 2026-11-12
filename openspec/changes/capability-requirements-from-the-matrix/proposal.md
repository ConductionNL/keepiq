---
kind: code
---

# Requirements for five matrix rows that no spec states

## Why

Every row in `openspec/parity/capabilities.json` should record the requirement that states it. For 186 of the 191 rows that recorded none, a requirement whose scenarios say what the row says already exists, and those rows now name it in `built.requirement`. For the five rows below, the cited spec has no such requirement: either the behaviour is built but never written down, or the row asks for more than the spec promises. This change writes those five requirements so that each row points at a requirement that says it.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `vault-29` | Compare two versions of a secret side by side to see what changed. | `partial`: one old version opens read-only in the version details dialog; there is no comparison |
| `sharing-14` | Share a secret through a password-protected public link that expires. | `partial`: the link is password-protected and capped by a number of uses; the dialog offers no end date, although the link share has an `expires_at` field |
| `sharing-26` | Require an approved access request before someone may use a privileged login, limited by time window and IP address. | `partial`: a recipient can ask the owner for a share, but nothing limits use of a login by time window or address |
| `apps-14` | See which secrets belong to which application. | `yes`: the application detail page lists the secrets in the application's vault, but no requirement states it |
| `audit-15` | Get alerted in real time about risky user or admin events. | `partial`: five risky events notify the people concerned at once; the audit trail spec does not state it |

## What Changes

- **Compare versions.** The version history panel lets the owner pick two versions, or one version and the current value, and shows them side by side in the browser with the changed fields marked.
- **An end date on a link share.** The link share dialog takes an optional end date. After it, the link answers not found and the snapshot is deleted, the same as at the usage limit.
- **Time-boxed access to a privileged login.** The owner can mark a shared login as needing approval. A recipient then asks for access for a time window; the owner approves the window, optionally limited to an address range. Outside an approved window or range, the server does not serve the recipient's copy.
- **The secrets of an application.** The application detail page lists the secrets in the application's vault by name, type and creation date, without values.
- **Alerts on risky events.** A decoy being touched, an emergency access request, a secret flagged as compromised, a new-device approval request and an account recovery request each send a Nextcloud notification to the people concerned when the event is recorded.

## Security

- Comparing versions decrypts both versions in the browser with the key already in session. No plaintext and no diff is sent to the server.
- The link end date is enforced on the server at access time, independent of the cleanup job, as the usage limit already is.
- The privileged-login check is enforced on the server before the recipient's ciphertext is returned. It limits who receives the ciphertext; it cannot take back a value a recipient already decrypted, so the owner is told to rotate after a window that was misused.
- The application secrets list returns metadata only: no `key`, `login`, `additional_fields` or other ciphertext.
- Alert notifications carry the event type, the actor and the secret name, never secret material, following "No Secret Material in Audit Entries".

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `secret-version-history`: compare two versions.
- `link-sharing`: an optional end date.
- `user-sharing`: approval and a time window for a privileged login.
- `application-mgmt`: the application page lists its secrets.
- `secret-audit-trail`: risky events raise an alert at once.

## Impact

No impact on OpenConnector or integriq: the machine API is unchanged.
