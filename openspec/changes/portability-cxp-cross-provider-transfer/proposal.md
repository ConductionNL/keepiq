---
kind: code
---

# Exchange a vault with another provider through Credential Exchange files

## Why

Keepiq implements the Credential Exchange Protocol handshake between two sessions on one server (`src/dialogs/CxpTransferDialog.vue`, `src/crypto/cxp.js`, `lib/Controller/CxpRelayController.php`), so it cannot receive from or send to another provider, which is the point of the standard. The send side always sends the whole vault (`src/store/modules/export.js:206`). Bitwarden and 1Password document the standard for direct transfer. Two competitors rate yes.

One row, one change.

### Matrix rows (`keepiq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `portability-08` | Move a vault straight to or from another provider with the Credential Exchange Protocol. | `partial`: `partial`: the sealed handshake works through a relay on the same Nextcloud only, so both sides must be Keepiq; there is no way to a different provider, and sending always sends the whole vault |

### Demand

- `portability-08`: no demand row.

### Competitors rated yes

- `portability-08`, bitwarden: "code not public in the cloned repos (bitwarden/clients@web-v2026.9.0 has no CXP code; the iOS and Android apps are separate repos); docs rating kept: https://bitwarden.com/help/import-data/ Note: CXP direct import and export is a "
- `portability-08`, onepassword: "https://support.1password.com/import/ : Credential Exchange standard on iOS 26+ and Android 14+ for direct transfer"

## What Changes

- Add Create import request as a file or QR: the receive side publishes its HPKE public key and request in the CXP request format so another provider can seal to it, and accepts the sealed CXF file it returns.
- Add Send to another provider: the user pastes or loads another provider's request and downloads a sealed CXF file for it.
- Let the send side choose what goes: all items, a folder or a selection.

## Capabilities

### New Capabilities

- `portability-cxp`

### Modified Capabilities

- None in delta form.

## Impact

- **Frontend**: `CxpTransferDialog.vue` gains file and QR modes; `src/crypto/cxp.js` exposes request creation and open for file transport; `export.js` takes an item filter.
- **Backend**: none for file transport; the relay stays for same-server transfers.
- **Security**: sealing is HPKE to the requester key as the protocol defines; no new key material is stored.
- **Risk**: interoperability depends on the other provider's current draft of the protocol; the task list starts with a test against the published test vectors.
