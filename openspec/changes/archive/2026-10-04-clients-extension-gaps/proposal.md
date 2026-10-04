---
kind: code
---

# Close the gaps against the keepiq-extension plans

## Why

`openspec/references/keepiq-extension/mapping.md` holds all 163 requirements of the former keepiq-extension plans against Keepiq. 57 are specified, 6 are built without a spec, 53 are partly built, 28 were decided differently and 19 are missing. This change proposes what to do with the gaps.

## Decisions (2026-10-04)

- **Group A:** build all twelve.
- **Group B:** record as specs. The name limit is 255 characters everywhere, the width of the server's `name` column (the earlier 4096 was the import limit for addresses and values).
- **Group C:** build all four groups: release, unlock and accounts, autofill extras, list and settings.

Each build step adds its own spec delta with its tests.

The row references below (`account-management:16`) point to the old spec and line, listed in `mapping.md`.

## What Changes

### A. Security and privacy (proposed: build all)

1. **Pairing accepts any URL.** Store only the origin and refuse `http` except for local hosts, so an app password never travels in clear (account-management:16).
2. **A 401 locks but keeps the app password.** Treat it as revocation: purge the app password, show the account as logged out and offer to sign in again (api-client:21, account-management:145).
3. **Decrypted values stay in the popup after a lock.** The open item, the edit form and the TOTP timer must clear when the worker locks (item-detail:115).
4. **Only the suite id is compared.** Also compare `unlockKeyEpoch`, so a master password change locks the extension (vault-sync:68, vault-unlock:43).
5. **Blocked rows are offered for fill when online.** The server's match does not filter them; filter them in the worker too (autofill:3, vault-sync:82).
6. **A fill reaches every frame.** Send it to the frame of the matched host only, and clear the values in the content script after filling (autofill:66, autofill:176).
7. **The save prompt trusts the page.** Take the host from `sender.url`, key the capture by tab, expire it after 5 minutes and drop it when the tab closes (login-capture:3, login-capture:21).
8. **Copies stay on the clipboard.** Clear every copy after a delay the user sets, not only TOTP codes (vault-list:90, settings:156, credential-generator:113).
9. **Server requests send cookies.** Set `credentials: 'omit'` (api-client:10).
10. **Large vaults are cut off.** The page-by-page fallback stops after 100 pages without an error (vault-sync:35).
11. **No warning before filling an https login into an http page**, which the archived autofill design promised (autofill:79).
12. **Lock locks every account and disconnect asks nothing.** Lock the active account only, and confirm a disconnect (settings:65).

### B. Record what Keepiq already decided (proposed: specs only, no code)

Behaviour that exists without a spec, or a deliberate difference without a record:

- web pages cannot reach the vault (`PAGE_MESSAGES`);
- HTTP runs in the worker only;
- sends keep no plaintext in storage;
- disconnect revokes the app password;
- duplicate accounts are refused;
- the popup keeps no state between opens;
- the timeout options are 1 to 240 minutes, with no Immediately, Never or Custom;
- there is no logged-out state as a lock action;
- one fixed match rule;
- the special-character set;
- a 1-day default for sends;
- filters as dropdowns;
- actions in the item detail, not on the card;
- no favicons fetched;
- a plain JavaScript popup, not React.

The name limit conflicted: the worker allowed 255 characters, the form and Keepiq's spec 4096 (item-editing:167). Decided: 255, the width of the name column; the import is held to it too.

### C. Features the plans had and Keepiq lacks (proposed: decide per item)

- **Unlock:**
  - unlock with a PIN (settings:78);
  - offline unlock from the cached suite (vault-unlock:15);
  - "Invalid master password" instead of the raw error (vault-unlock:23);
  - a show/hide toggle and autofocus (vault-unlock:3).
- **Accounts:**
  - distinct pairing errors (account-management:50);
  - per-account Lock and Log out, and Log out all (account-management:100, :133);
  - avatar or initials (account-management:89).
- **Autofill:**
  - a context menu (autofill:110);
  - a keyboard shortcut (autofill:142);
  - "Never for this site" excluded domains (login-capture:66);
  - field detection in shadow roots and by label (autofill:92);
  - "Unable to autofill" (autofill:46);
  - a folder choice when saving (login-capture:34).
- **Vault list:**
  - a Copy menu, a type icon and a subtitle on each card (vault-list:69, :90);
  - Launch from the card (vault-list:82);
  - a "No folder" filter (vault-list:25);
  - empty, all-blocked and first-sync states (vault-list:125).
- **Settings:**
  - autofill settings (settings:127);
  - save-prompt toggles (settings:164);
  - notifications (settings:182);
  - Import, Export and a default item type (settings:205);
  - appearance (settings:222);
  - About (settings:234);
  - a Change master password link (settings:115).
- **Send:**
  - Create off while offline (send:155);
  - a progress state during Argon2id (send:112);
  - Send for logins only (send:142);
  - expiry and password badges in the list (send:3).
- **Release:**
  - extension icons (none ship today);
  - Firefox's `data_collection_permissions` key for new AMO add-ons;
  - the CC BY attribution of the EFF word list in the package (credential-generator:211).

## Impact

The extension only (`browser-extension/`, `tests/extension/`), plus one server change for item A5. Group A touches the worker's router, vault handlers, sync, content script and save prompt.
