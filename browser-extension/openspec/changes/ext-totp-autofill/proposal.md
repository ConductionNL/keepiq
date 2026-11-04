---
kind: code
depends_on: [ext-vault-browse, ext-settings, ext-autofill]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-share-sync
  - ext-i18n
  - ext-generator
  - ext-send
  - ext-theme
  - ext-settings
  - ext-autofill
  - ext-totp-autofill
---

## Why

Since keepiq#1324 a login keeps its TOTP seed in its own encrypted `totp` field, and the web app shows its code. A user who fills a login in the extension still has to open the web app for the code of the second step. Bitwarden puts the code on the clipboard after the fill and fills it into the code field; Keepiq should too.

## What Changes

- Mirrors Bitwarden's TOTP autofill, with the seed from the login's own `totp` field (keepiq#1325). Standalone `totp` items are not used: no Keepiq client creates them any more (keepiq#1324), and the extension never guesses which one belongs to a login.
- **Copy after fill.** After a fill from the popup, the inline list, the context menu or the shortcut, the login's current code goes to the clipboard, and the clipboard clears after ext-settings' "Clear clipboard" delay. Fill on page load copies nothing, as in Bitwarden.
- **Setting.** "Copy TOTP automatically" in the Autofill view, per account, on by default, as in Bitwarden.
- **Fill into a code field.** The same fills also put the code into a one-time-code field of the frame, including a code split over several inputs. On a second-step page with only a code field, a login fill from the popup, the menu or the shortcut fills just the code.
- **Inline list on a code field.** A one-time-code field opens the inline list with the matching logins that have a code, each with its name, username and current code; a pick fills the code.
- **Context menu.** "Copy verification code" lists the matching logins that have a code, as in Bitwarden.
- **Detail view.** A login with a code shows it with its countdown and a copy button, as the web app does.
- **Lock.** A lock or logout clears a code the extension put on the clipboard, at once, whatever the delay.
- **Field detection.** One-character inputs, or two to four short inputs with a code token, form one code group, and recovery and backup code fields are not one-time-code fields.
- **Blocked rows** drop their `totp` and `passkeys` ciphertext from the cache, as they drop `key`, `login` and `additionalFields`.
- Out of scope, each a later change:
  - Adding, replacing or removing a login's seed in the extension, and reading an authenticator QR code from a page.
  - "Copy verification code" on a login's card in the vault list.
  - Counting codes on the toolbar badge.

## Capabilities

### New Capabilities
- `totp-autofill`: a login's code from its `totp` field, copy after fill, fill into a code field, the inline list on a code field, "Copy verification code" in the context menu, the code in the detail view, the "Copy TOTP automatically" setting, and clearing a copied code on lock.

### Modified Capabilities
- `autofill` (ext-autofill): a fill may hand the content script the login's code with its username and password.
- `inline-list` (ext-autofill): a code field, split ones included, opens the list in its code mode; a recovery code field opens none.

## Deviations from Bitwarden

- A use-only copy's code is listed, filled and copied, never its seed. Keepiq's `use-only-shares` lets a client show and copy it.
- A lock or logout clears a code the extension copied. Keepiq's `secrets` spec requires a lock to discard every generated code; Bitwarden leaves the clipboard alone.
- A fill puts the code into one code group per frame: the focused one, else the first. Bitwarden fills a TOTP field next to each password field it fills. Not forced; a page has one code step.
- The inline list's code rows hide the digits from assistive technology, so a screen reader does not re-announce them every period; a pick fills them anyway. Not forced.
- No Premium gate: Bitwarden's Premium is out of scope (ADR-001), so every account gets it.

## Keepiq API used

None. The cached snapshot holds each row's `totp` ciphertext as the list routes send it. The field is in Keepiq 0.3.12 (keepiq#1325), the minimum, so nothing is gated in `KEEPIQ_FEATURES`.

No Keepiq backend change is needed.

## Impact

- `src/autofill/login-codes.ts` (new): the logins with a code for a page, and current codes. `src/autofill/code-fill.ts` (new): the code list's pick.
- `src/autofill/fill.ts`: the login's code in `fill.execute`, the copy after the fill.
- `src/autofill/fields.ts` and `src/autofill/execute.ts`: code groups, recovery-code exclusion, filling a code.
- `src/autofill/inline-session.ts`, `src/autofill/inline-host.ts` and `entrypoints/inline-list/`: the code mode.
- `src/menus.ts`: "Copy verification code". `src/clipboard.ts`: the clear on lock.
- `src/api/types.ts`, `src/vault/sync.ts`: `totp` and `passkeys` on a row, dropped from blocked rows.
- `src/messages.ts`: `totp` as a field `item.decrypt` opens, also for a use-only copy; `fill.execute`, its reply, `FillResult`, `inline.open`, the inline rows and the state reply gain fields; no new kind.
- `entrypoints/popup/views/ItemDetail.tsx`: the code of a login.
- `src/totp/totp.ts` is used by the background too; it stays free of popup code.
- `src/accounts/settings.ts` and the Autofill view: `autoCopyTotp`.
- `test-site/`: code steps in three layouts, a login with its code on one page, a recovery-code page, a login leading to the code step, and a setup page with the test key.
- No new permission: `clipboardWrite` and Chrome's `offscreen` are declared (ext-vault-browse).
