---
kind: code
---

# Finish the extension against the keepiq-extension plans

## Why

`clients-extension-gaps` closed most of what `openspec/references/keepiq-extension/mapping.md` found, and listed what stayed open under "Still open". On 4 October 2026 Ruben chose to build that too. Delete stays allowed for a blocked item (item-detail:107): it can be the only way to clear out an item of a revoked suite.

## What Changes

Four steps, each landing on its own:

1. **Send details:** no send while offline, a progress note while Argon2id runs, Send for logins only, what each send is in the list, asking before a send ends, and the server's own message (send:155, :112, :142, :3, :50, :86).
2. **Save prompt details:** several logins with the same username, an update that changes the password only, the bar leaving with the site, and a save that confirms (login-capture:75, :88, :110, :52).
3. **Generator under a policy:** controls the policy fixes shown as such, the policy refreshed on unlock and sync, and every chosen class present (credential-generator:172, :42).
4. **Small list and form items:** suggestions by last use, the tab address for a new item, a checked authenticator secret, folder buttons off while offline, the scroll position kept, and the last tab without session storage (autofill:155, item-editing:3, :61, folder-management:110, item-detail:3, popup-shell:25).

## Impact

The extension only (`browser-extension/`, `tests/extension/`).
