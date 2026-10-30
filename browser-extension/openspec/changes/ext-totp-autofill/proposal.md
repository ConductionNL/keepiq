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

Keepiq stores authenticator seeds and the popup shows their codes, but a user who fills a login still has to open the popup, find the authenticator item and copy its code for the second step. Bitwarden puts the code on the clipboard after the fill and fills it into the code field; Keepiq should too.

## What Changes

- Mirrors Bitwarden's TOTP autofill. Keepiq keeps a seed in its own `totp` item, not on the login (ADR-003; the importers split a Bitwarden or KeePass login's seed into a `totp` item with the same `url` and `login`), so the extension pairs a login with its authenticator item:
  - the `totp` items matching the page under the URI match rule, whose decrypted `login` equals the filled username, or, when none does, whose `login` is empty;
  - exactly one candidate pairs; none or several pair nothing, and the user picks from the inline list or the context menu instead.
- **Copy after fill.** After a fill from the popup, the inline list, the context menu or the shortcut, the paired item's current code goes to the clipboard, and the clipboard clears after ext-settings' "Clear clipboard" delay. Fill on page load copies nothing, as in Bitwarden.
- **Setting.** "Copy TOTP automatically" in the Autofill view, per account, on by default, as in Bitwarden.
- **Fill into a code field.** The same fills also put the paired code into a one-time-code field of the frame, including a code split over one-character inputs. On a second-step page with only a code field, a login fill from the popup, the menu or the shortcut fills just the code.
- **Inline list on a code field.** A one-time-code field opens the inline list with the matching `totp` items, each with its name, username and current code; a pick fills the code. Bitwarden's inline menu does the same with logins that have a TOTP.
- **Context menu.** "Copy verification code" lists the matching `totp` items, as in Bitwarden.
- **Lock.** A lock or logout clears a code the extension put on the clipboard, at once, whatever the delay.
- **Field detection.** One-character code inputs form one code group, and recovery and backup code fields are not one-time-code fields.
- Out of scope, each a later change:
  - "Copy verification code" on a login's card and the code in a login's detail view, through the pairing. Bitwarden shows both; in Keepiq the `totp` item's own card and detail already offer them.
  - Scanning an authenticator QR code from the page.
  - Counting `totp` items on the toolbar badge.
  - An explicit link from a login to its `totp` item. The pairing above needs none; a link would need a Keepiq field.

## Capabilities

### New Capabilities
- `totp-autofill`: pairing a login with its authenticator item, copy after fill, fill into a code field, the inline list on a code field, the "Copy verification code" menu entry, the "Copy TOTP automatically" setting, and clearing a copied code on lock.

### Modified Capabilities
- `autofill` (ext-autofill): `totp` items match for the surfaces of `totp-autofill`; a fill may hand the content script the paired code with the login.
- `inline-list` (ext-autofill): a code field, split ones included, opens the list in its code mode; a recovery code field opens none.

## Deviations from Bitwarden

- The code comes from a separate `totp` item, paired by URL and username; a login with no unambiguous pair copies and fills no code. Forced: Keepiq has no TOTP field on a login (ADR-003).
- The inline list on a code field and the "Copy verification code" menu list `totp` items, not logins with a TOTP. Forced by the same.
- A lock or logout clears a code the extension copied. Keepiq's `secrets` spec requires a lock to discard every generated code; Bitwarden leaves the clipboard alone.
- Use-only `totp` copies are never paired, listed, copied or filled. Keepiq's `use-only-shares` lets the extension fill a use-only value only into a password field.
- A fill puts the code into one code group per frame: the focused one, else the first. Bitwarden fills a TOTP field next to each password field it fills. Not forced; a page has one code step.
- The inline list's code rows hide the digits from assistive technology, so a screen reader does not re-announce them every period; a pick fills them anyway. Not forced.
- No Premium gate: Bitwarden's Premium is out of scope (ADR-001), so every account gets it.

## Keepiq API used

None. Pairing and matching read the cached snapshot's plaintext `url` and `typeId`; the seed and the `totp` item's `login` are decrypted from the cached ciphertext. The `totp` system type is in every Keepiq release since 0.1.4, older than `MIN_KEEPIQ_VERSION` (0.3.12), so nothing is gated in `KEEPIQ_FEATURES`.

No Keepiq backend change is needed.

## Impact

- `src/autofill/fill.ts`: pairs the login, adds the code to `fill.execute`, copies after the fill. New `src/autofill/totp-pair.ts` (pairing) and `src/autofill/code-fill.ts` (the code list's pick).
- `src/autofill/fields.ts` and `src/autofill/execute.ts`: split code groups, recovery-code exclusion, filling a code.
- `src/autofill/inline-session.ts`, `src/autofill/inline-host.ts` and `entrypoints/inline-list/`: the code mode.
- `src/menus.ts`: "Copy verification code". `src/clipboard.ts`: the clear on lock.
- `src/totp/totp.ts` is used by the background too; it stays free of popup code.
- `src/accounts/settings.ts` and the Autofill view: `autoCopyTotp`.
- `src/messages.ts`: `fill.execute`, its reply, `FillResult`, `inline.open`, the inline rows and the state reply gain fields; no new kind.
- `test-site/`: a split-code page, a login with its code on one page, and a recovery-code page.
- No new permission: `clipboardWrite` and Chrome's `offscreen` are declared (ext-vault-browse).
