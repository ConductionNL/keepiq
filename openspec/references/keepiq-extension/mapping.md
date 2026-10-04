# Mapping: old keepiq-extension requirements in Keepiq

Every requirement in `changes/*/specs/*/spec.md` of this reference, held against Keepiq at commit `61329cb0` (development, 2026-10-03). Each row was checked by reading Keepiq's specs and code, not by matching names.

## Classes

| Class | Meaning |
| --- | --- |
| SPEC | A Keepiq spec states the same obligation. |
| CODE-ONLY | Keepiq does it, but no Keepiq spec says so. |
| PARTIAL | Keepiq does part of it; the row names the missing part. |
| CHANGED | Keepiq decided differently. The row says whether that decision is recorded. |
| MISSING | Neither a spec nor code. |

## Totals

| Old change | Requirements | SPEC | CODE-ONLY | PARTIAL | CHANGED | MISSING |
| --- | --- | --- | --- | --- | --- | --- |
| ext-accounts-and-unlock | 35 | 6 | 3 | 14 | 8 | 4 |
| ext-vault-edit | 22 | 18 | 0 | 3 | 1 | 0 |
| ext-vault-browse | 45 | 21 | 2 | 13 | 8 | 1 |
| ext-settings | 14 | 0 | 0 | 4 | 2 | 8 |
| ext-autofill | 21 | 0 | 0 | 10 | 5 | 6 |
| ext-generator | 13 | 7 | 0 | 3 | 3 | 0 |
| ext-send | 13 | 5 | 1 | 6 | 1 | 0 |
| **All** | **163** | **57** | **6** | **53** | **28** | **19** |

Fixed since this snapshot: a passkey's private key reached the popup's edit form and Clone copied it (item-editing, old line 61 and 141). The worker now keeps it; see the requirement "A passkey's private key stays in the worker" in `openspec/changes/clients-extension-complete/specs/extension-vault/spec.md`.

## The ADRs

| Old decision | In Keepiq |
| --- | --- |
| ADR-001: Bitwarden is the reference design, deviations listed | Not recorded for the extension. |
| ADR-002: the private key as PKCS#8 bytes in `storage.session` | Changed: the key lives only in the worker's memory (`browser-extension/README.md`). Not recorded as replacing ADR-002. |
| ADR-002: lock and log out are separate states | Changed: Keepiq has lock and disconnect, no logged-out state. Not recorded. |
| ADR-002: timeout options Immediately, On system lock, On browser restart, Never, Custom | Changed: 1, 5, 15, 30, 60 or 240 minutes, capped by an admin maximum, and an OS lock always locks (`browser-extension-autofill` spec). Why the old options were dropped is not recorded. |
| ADR-002: purge the cache when the suite or `unlockKeyEpoch` changes | Partial: only the suite id is compared (`vault-sync.js`). |
| ADR-002: never fill from blocked rows | Partial: the offline path filters them; online, the server's match does not. |
| ADR-002: the cheap sync check | Recorded in `clients-extension-complete/specs/extension-vault-sync`. |
| ADR-002: never fetch favicons | Holds in code (the extension fetches none; the web app only when an admin sets an icon service). Not recorded for the extension. |
| ADR-002: clear the clipboard after a copy | Partial: only an auto-copied TOTP code is cleared, after 30 seconds. |
| ADR-003: the API contract | Keepiq's own routes and specs own this. The old "do not use `/api/v1/extension/*`" does not apply: those are Keepiq's extension routes. |
| ADR-004: a React popup | Not adopted: the popup is plain JavaScript bundled with esbuild. Not recorded as a decision. |

The old docs (`docs/WXT-AND-BROWSERS.md`) describe WXT, Firefox on MV2 and `browser.*` APIs. Keepiq builds with esbuild, runs MV3 on Firefox 115 and later, and uses `chrome.*` (`browser-extension/manifests/browsers.mjs`).

## Accounts, unlock and vault edit

### openspec/changes/ext-accounts-and-unlock/specs/account-management/spec.md
| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| 1 | First-run add account screen | 3 | PARTIAL | BE/popup/popup.html:26-35 `view-pair` (Server URL, Nextcloud user, App password; hint "Use a Nextcloud app password (Settings → Security), not your login password"); BE/background/router.js:355 `doPair` calls `api.pair` before `api.addAccount`, so nothing is stored until it verifies | The hint never links to `<origin>/index.php/settings/user/security`. The Connect button is not disabled while fields are empty. No keepiq spec states the screen. |
| 2 | Server URL normalisation | 16 | MISSING | BE/popup/popup.js:408 only runs `.trim()`; BE/lib/api.js:206 `base()` strips trailing slashes; BE/lib/api.js:133 stores `url` as typed | Missing: "MUST store only the origin" and "`http` MUST be rejected unless the host is `localhost`, `127.0.0.1`, or ends in `.test` or `.local`". A pasted `/index.php/apps/keepiq/...` URL is stored whole and breaks every route. An http URL sends the app password in clear (the manifest allows `http://*/*`). |
| 3 | Host permission requested at add time | 31 | CHANGED (recorded) | browser-extension/manifest.json:276-279 asks for `http://*/*` and `https://*/*` at install; there is no optional per-origin request | Recorded at docs/browser-extension/permissions.md:13 and openspec/changes/clients-extension-store-release/design.md:78 ("Wide host permissions ... needed to detect login fields on any site"). |
| 4 | Credentials verified before storing | 42 | PARTIAL | BE/background/router.js:369 `api.pair` → `POST /api/v1/extension/pair` (lib/Controller/ExtensionController.php:121 returns `user`, `serverVersion`); BE/lib/api.js:118 `addAccount` | No check that an `active` suite exists before storing. The uid comes from the typed username (`user: config.user`, api.js:136), not from the server's `user`. No `/ocs/v2.php/cloud/user` identity call, so there is no display name. |
| 5 | Distinct verification errors | 50 | PARTIAL | BE/popup/popup.js:412-415 shows `res.error` and keeps the form values (it clears them only on success, :417-419) | No distinct messages. Every failure surfaces as the raw `Keepiq POST /api/v1/extension/pair failed (<status>)` from BE/lib/api.js:223, or a fetch TypeError. "Wrong username or app password", "Keepiq is not installed on <host>", "not a Nextcloud server", "Could not reach <host>" and the no-suite hint are all absent. |
| 6 | Account record storage | 69 | CHANGED (recorded) | BE/lib/api.js:8-11,16-17,133-141: one array `keepiq.accounts` of `{id,url,user,appPassword,label,idleMinutes,serverVersion}` plus `keepiq.activeAccountId`; settings sit inside the record | No `displayName`, `email` or `avatarDataUrl`, and no separate `settings.<id>`. Recorded in openspec/changes/archive/2026-10-02-clients-extension-unlock-lock-and-accounts/design.md:73 and docs/browser-extension/privacy.md:21. The app password is cleared only by removal, not by a 401 (see api-client #3). |
| 7 | Account limit and duplicates | 76 | SPEC (limit) + CODE-ONLY (duplicate) | Limit: openspec/specs/extension-account-switching/spec.md "Up to five paired accounts in one extension"; BE/lib/api.js:20,120 and router.js:356; tests/extension/accounts.spec.js "refuses a sixth pairing". Duplicate: BE/lib/api.js:127-132 `addAccount` ("This account is already connected.") | The duplicate refusal is in code only, with no spec and no test. The switcher does not disable "Add account" with a hint: BE/popup/popup.js:276 hides it instead. |
| 8 | Avatar fetched and cached | 89 | MISSING | No avatar fetch anywhere in BE (no `/index.php/avatar` route) | "fetch `GET /index.php/avatar/{uid}/64` ... store it as a data URL ... render it in the popup's top-right corner ... initials instead" when it fails. |
| 9 | Account switcher panel | 100 | PARTIAL | BE/popup/popup.html:12-17 `<select id="account-select">` plus "Add account"; BE/popup/views.js:15 `renderAccountSwitcher` labels each account `user@host` with " (locked)"; tests/extension/popupAccounts.spec.js "lists three accounts with their lock state and switches the active one" | Missing: avatar or initials, a "Logged out" status, per-account "Lock" and "Log out", and "Lock all" / "Log out all" in the panel. |
| 10 | Exactly one active account | 113 | SPEC | openspec/specs/extension-account-switching/spec.md "Switching and isolation between accounts"; BE/background/router.js:406 `doSwitchAccount` only sets the active id; BE/lib/api.js:82 `activeAccountId` | None material. Switching never locks the previous account. |
| 11 | Log out removes the account | 121 | CODE-ONLY | BE/background/router.js:377 `doUnpair` locks the account, forgets generator state and the snapshot, and calls `api.removeAccount`; it also deletes the app password server-side (`revokeAppPassword`, api.js:281). BE/lib/api.js:178 makes the first remaining account active. tests/extension/unpair.spec.js "deletes the app password and clears the pairing" | Keepiq goes further than the old spec (it revokes the app password in Nextcloud). The `@spec` tag points at browser-extension-autofill "Pairing", which does not state the local purge. Only extension-vault-sync "Keep a snapshot" covers part of it (snapshot discarded on removal). The UI word is "Disconnect", not "Log out". |
| 12 | Lock all and Log out all | 133 | PARTIAL | BE/popup/popup.js:477-480: the Lock button sends `lock` with no account id, and router.js:895-898 → `lockEverything`, so "Lock" locks every account | No "Log out all" with confirmation. There is no separate per-account Lock in the UI. |
| 13 | Re-login after revocation | 145 | MISSING | There is no logged-out state. A 401 during sync only locks the account (BE/background/vault-sync.js:179-181) | Missing: keep the identity record, show a "Log in again" screen with a single App password field, show "Session revoked, please log in again", and re-verify without creating a second record. In keepiq a revoked app password leaves the account paired and failing until the user disconnects and pairs again. |

### openspec/changes/ext-accounts-and-unlock/specs/api-client/spec.md
| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| 1 | Single background-only client | 3 | CODE-ONLY | BE/lib/api.js is the only fetch site (none in BE/popup or BE/content, checked by grep); BE/background/router.js:1-15,244 `fromExtensionPage`, :982 refuses non-page messages from tabs; tests/extension/accounts.spec.js "a web page cannot use the popup messages" | No keepiq spec requirement states that all server calls run in the worker (browser-extension-autofill names "Extension architecture" only in code comments). |
| 2 | Request shape | 10 | PARTIAL | BE/lib/api.js:210-220 `request`: base `<url>/index.php/apps/keepiq`, Basic auth, `OCS-APIRequest: true`, `Accept: application/json`, JSON body; :268 OCS `cloud/user?format=json` | `credentials: 'omit'` is not set. `Content-Type: application/json` goes on every request, GETs included. No spec. |
| 3 | 401 is revocation | 21 | PARTIAL | BE/background/vault-sync.js:179-181: a 401 during sync locks the account (`lastError: 'auth'`); spec openspec/changes/clients-extension-complete/specs/extension-vault-sync/spec.md "A changed suite discards the snapshot" ("An authentication failure MUST lock the account") | Only the sync path reacts. A 401 on match, fill, save, item or unlock is a generic error. The app password is never purged, there is no "Logged out" state and no `SessionRevoked` message. Keepiq locks the account on a 401; it does not log out. |
| 4 | 423 is a retryable write lock | 34 | SPEC | extension-vault-sync "A changed suite discards the snapshot" ("a key-migration lock MUST keep the snapshot and try again later"); extension-vault "Edit every kind of item" ("a key migration in progress"); BE/lib/item-form.js:258 `writeErrorMessage`; BE/background/vault-sync.js:189 | None material. Nothing is purged and the account stays unlocked. |
| 5 | Network failure is offline | 41 | SPEC | extension-vault-sync "Work offline from the snapshot"; BE/background/vault-sync.js:25 `isOffline` (no status or ≥500); router.js:489-498 offline match from the snapshot; tests/extension/vaultSync.spec.js "keeps the snapshot and says offline when the server cannot be reached" | Keepiq also treats 5xx as offline, a broader rule than the old spec's. |
| 6 | Other error responses | 49 | PARTIAL | BE/lib/api.js:221-227 attaches `status` and the raw `body`; BE/lib/item-form.js:262-270 parses `{message}` for 400/409 only | Outside writes the error is `Keepiq <method> <path> failed (<status>)`, not the server's `message` or the status text. There is no `KeepiqNotInstalled` for a 404 with an HTML body. The closest feature is a too-old server, which the version check handles (router.js:281 `view-update`). |
| 7 | Typed response shapes | 60 | CHANGED (unrecorded) | The extension is plain JS (BE/**/*.js); responses are narrowed ad hoc (`Array.isArray`, `?.items`) in BE/lib/api.js | No TypeScript types and no single narrowing boundary. No keepiq file records the decision to stay in JS rather than TS. Blocked rows are handled in BE/background/vault-handlers.js:187-195. |
| 8 | Logged-out account never hits the network | 67 | CHANGED (unrecorded) | Keepiq has no logged-out state: an account either holds its app password or is removed (BE/background/router.js:377 `doUnpair`) | The obligation has nothing to apply to. Nothing records that keepiq dropped the "logged out, identity kept" state. |

### openspec/changes/ext-accounts-and-unlock/specs/vault-unlock/spec.md
| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| 1 | Unlock screen | 3 | PARTIAL | BE/popup/popup.html:38-47 `view-locked` (Master password, Unlock, Disconnect); the account bar above it shows `user@host` (popup.html:12-17); master password used only client-side: openspec/specs/browser-extension-autofill/spec.md "In-extension unlock preserves zero-knowledge" | No show/hide toggle and no autofocus on the field. |
| 2 | Unlock derives the key client-side | 15 | PARTIAL | BE/lib/vault.js:83-87 `unlock` → web-app `decryptPrivateKey` (BE/crypto/index.js re-exports src/crypto/aes.js) → non-extractable import; spec browser-extension-autofill "In-extension unlock preserves zero-knowledge"; tests/extension/crypto.spec.js "imports the private key NON-EXTRACTABLE" | Every unlock contacts the server: `fetchActiveSuite` (vault.js:84) and `refreshServerVersion` (router.js:441). The suite in the snapshot (vault-sync.js:160) is never used to unlock, so an offline unlock is impossible. Holding the key in memory rather than as PKCS#8 bytes is the recorded change in #6. |
| 3 | Invalid master password | 23 | PARTIAL | BE/popup/popup.js:462-469 clears the field and shows `res.error`; nothing is counted or reported to the server | The message is the raw WebCrypto `OperationError` text from src/crypto/aes.js:83, not "Invalid master password". The field is not refocused. |
| 4 | Unlock fetches the suite when nothing is cached | 30 | PARTIAL | BE/lib/vault.js:84 always fetches `GET /api/v1/suites` | It fetches on every unlock, not only when nothing is cached. There is no "You are offline and this vault has not been synced yet" message; an offline unlock shows a fetch error. |
| 5 | Cached suite row lifetime | 43 | PARTIAL | The suite lives in the snapshot (BE/background/vault-sync.js:158-169) and is dropped on removal (`forget`, :239). A different suite id discards the snapshot and locks the account (:141-151); spec extension-vault-sync "A changed suite discards the snapshot"; tests/extension/vaultSync.spec.js "discards the snapshot and locks when the suite changed" | Only the suite `id` is compared, not `unlockKeyEpoch`. UNKNOWN whether a master-password rotation in keepiq changes the suite id; I did not trace the server rotation path, so whether rotation locks the extension cannot be told from extension code. |
| 6 | Private key storage and lifetime | 51 | CHANGED (recorded) | BE/lib/vault.js:12-18,30-32: the key is held only in worker memory and never in `storage.*`; worker termination locks | Recorded: openspec/specs/browser-extension-autofill/spec.md "Auto-lock" (line 61, "service-worker termination") and "Key never persisted" scenario (line 31-34); browser-extension/README.md:44-45. Unlike the old design, a service-worker restart locks every account. |
| 7 | Manual lock | 63 | SPEC | browser-extension-autofill "Auto-lock" ("on manual lock"); BE/background/router.js:274 `lockAccount` / :284 `lockEverything` keep the account record and snapshot; BE/lib/vault.js:125 `lock` | The UI Lock button locks all accounts (popup.js:478). A per-account lock exists only as a message (`lock` with `accountId`). |
| 8 | Timeout options and defaults | 70 | CHANGED (recorded) | BE/lib/api.js:23-26 choices 1/5/15/30/60/240, default 15; action is always lock; admin maximum from `GET /api/v1/extension/policy` (router.js:427 `refreshPolicy`, :260 `effectiveIdleMinutes`) | Recorded in openspec/specs/browser-extension-autofill/spec.md "User-chosen idle lock period with an administrator maximum". Immediately, On system lock (always on), On browser restart, Never, Custom and the Log out action are not offered. |
| 9 | Idle timeout enforcement | 77 | SPEC | browser-extension-autofill "Auto-lock" + "User-chosen idle lock period..."; BE/lib/vault.js:201 `armIdleLock` (setTimeout), re-armed by router.js:268 `touchActivity`; tests/extension/accounts.spec.js "locks only the account whose timer expired; an OS lock locks both" | The mechanism differs: an in-memory timer re-armed by vault actions (list, item, fill, save), not by every popup message, and there is no `alarms` tick or `lastInteractionAt`. A sleeping worker cannot extend a session because termination drops the key. |
| 10 | Immediately and On system lock | 90 | PARTIAL | BE/background/router.js:997 `onIdleState('locked')` → `lockEverything`; spec browser-extension-autofill "Auto-lock" (browser/OS lock) | "Immediately" (lock when the popup closes) does not exist. The OS lock is always on, not an option. |
| 11 | Timeout action Log out | 103 | MISSING | Only lock exists (BE/lib/vault.js:207) | "the elapsed timeout SHALL purge the private key, the app password, the cached suite row ... leave the account in the 'Logged out' state". Keepiq has no such action and no logged-out state. |
| 12 | Never timeout | 111 | CHANGED (recorded) | "Never" is not offered (BE/lib/api.js:23) | Recorded: browser-extension-autofill "Key never persisted" (key MUST NOT be written to persistent storage); docs/browser-extension/privacy.md:25. |
| 13 | Lock and logout labelled distinctly | 124 | CODE-ONLY | BE/popup/popup.html:42,45,55,295: "Unlock", "Lock" and "Disconnect" / "Disconnect this account"; the words are never mixed | Keepiq uses "Disconnect" where the old spec says "Log out". No spec states the labelling rule. |
| 14 | Alternative unlock method hook | 131 | CHANGED (recorded) | Two entry points, `unlock` and `unlock-raw` (BE/background/router.js:439,456), both ending in BE/lib/vault.js:63 `hold`; the popup offers fingerprint or face unlock (popup.html:43) | Keepiq shipped a second method (passkey PRF) instead of a single method-parameterised entry point with no other option shown. Recorded: openspec/specs/extension-biometric-unlock/spec.md. |

### openspec/changes/ext-vault-edit/specs/folder-management/spec.md
Keepiq spec referenced as "Manage folders" = openspec/changes/clients-extension-complete/specs/extension-vault/spec.md "Requirement: Manage folders".

| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| 1 | Folder manager location and tree | 3 | SPEC | "Manage folders" (tree sorted by name); BE/lib/folder-rules.js:29 `folderTree`; BE/popup/folder-view.js:257 `render`; tests/extension/folders.spec.js "builds a tree, siblings by name", "shows the tree and the not-encrypted notice once" | The manager lives in the Vault tab, not Settings → Vault → Folders (a minor location change). There is no "No folders yet" empty state. |
| 2 | Create folder | 16 | SPEC | "Manage folders" (blank or slash refused before sending; picker offers "New folder…"); BE/lib/folder-rules.js:15 `folderNameProblem` ("Name is required", "Folder names cannot contain slashes"); BE/background/vault-handlers.js:335 `folder-create` then `afterWrite` sync; BE/popup/vault-view.js:417-433 picker "New folder…"; tests/extension/folders.spec.js "adds a folder inside another, and refuses a slash" | A 409 shows the server's `message` (item-form.js:262), not the fixed "A folder with this name already exists here". The picker's new folder is always created at the root (vault-view.js:424). |
| 3 | Rename folder | 40 | SPEC | "Manage folders" ("only the name is sent"); BE/lib/api.js:477 `renameFolder`; tests/extension/folders.spec.js "renames a folder, sending only its name" | None. |
| 4 | Delete empty folder | 48 | SPEC | "Manage folders" ("an empty folder after 'Delete <name>? This cannot be undone.'"); BE/popup/folder-view.js:225-227; BE/lib/folder-rules.js:105 (no query, no body); tests/extension/folders.spec.js "deletes an empty folder plainly, and a leaf with items with the chosen cascade" | None. |
| 5 | Delete a non-empty leaf folder | 56 | SPEC | "Manage folders" (move to parent or delete); BE/lib/folder-rules.js:87-89 `?cascade=move|delete`; BE/popup/folder-view.js:228-230 | The prompt wording differs. |
| 6 | Delete a folder with subfolders | 70 | SPEC | "Manage folders" (choice for own items and keep / move items / delete per direct subfolder); BE/lib/api.js:491 `folderChildren`; BE/lib/folder-rules.js:90-103 `{directSecrets, subfolders}` defaulting to keep and move; tests/extension/folders.spec.js "deletes a folder with subfolders with a plan for every subfolder" | A 400 shows the server message, but there is no Retry that re-fetches children (folder-view.js:327). |
| 7 | Folder picker | 89 | SPEC | "Manage folders" ("The item form's folder picker MUST offer 'New folder…'"); BE/popup/vault-view.js:356-363 `fillEditFolders` ("No folder" first, path labels from BE/lib/vault-index.js:99 `folderChoices`, "New folder…" last) | The Move picker (popup.html:132-136) has no "New folder…" entry. Folders are listed as sorted path labels rather than an indented tree. |
| 8 | Folder names are plaintext | 102 | SPEC | "Manage folders" ("MUST say once that folder names are not encrypted"); BE/popup/popup.html:92-95; BE/popup/folder-view.js:123,288-291 flag `folders-notice-seen` in `storage.local` | The flag is global and is not cleared when an account is removed. |
| 9 | Folder write errors and offline | 110 | PARTIAL | BE/background/vault-handlers.js:346,368,401 map errors through `writeErrorMessage` (423/403/400/409/network); the typed name stays on error (folder-view.js:195-196); offline disables `folder-add-save` and `folder-delete-confirm` (vault-view.js:74-83) with the offline text (popup.html:84) | The per-row Rename and Delete buttons are created dynamically (folder-view.js:278-282), are not in `WRITE_CONTROLS`, and stay enabled offline. A network failure offers no Retry. No keepiq spec maps folder write errors; extension-vault-sync "Work offline" disables "the controls that change the vault" in general. |

### openspec/changes/ext-vault-edit/specs/item-editing/spec.md
Keepiq specs referenced: "Edit every kind" / "Clone and move" / "Detail sections" = openspec/changes/clients-extension-complete/specs/extension-vault/spec.md; "Add, edit and delete items" = openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md; "Pick mode" = openspec/changes/clients-extension-complete/specs/extension-generator/spec.md; "Work offline" = clients-extension-complete/specs/extension-vault-sync/spec.md.

| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| 1 | Add item form | 3 | PARTIAL | "Edit every kind" (type and per-type fields); "Pick mode" (generator returns to the form intact); BE/popup/popup.html:78 "New item", :140-164 form; BE/popup/vault-view.js:301 `openEdit`, :495-500 generate → `pickGenerated`; name required (BE/lib/item-form.js:444) | The default type is always `login` (vault-view.js:306), never the user's `default_secret_type`. Website is not prefilled from the current tab. The name message is "Give the item a name", not "Name is required". |
| 2 | Additional fields editor | 29 | SPEC | "Edit every kind" (add / show / remove; blank, duplicate and reserved `key`/`login`/`url`/`notes` refused); BE/lib/item-form.js:451-462; BE/popup/vault-view.js:225 `addFieldRow` (per-row Show toggle, view state only); tests/extension/itemForm.spec.js "refuses a blank name, reserved, blank and duplicate field names" | Keepiq also reserves `notes`. Save is refused on submit rather than disabled while typing. |
| 3 | Notes storage convention | 47 | SPEC | "Edit every kind" ("A note's text is stored in the key; other items keep notes in the additional field `notes`"); BE/lib/item-form.js:326-341,380-391; tests/extension/itemForm.spec.js "keeps a login's notes in the notes field...", "stores a note's text in the key"; tests/extension/popupItemDetail.spec.js "creates a note whose text is stored encrypted in the key" | None. |
| 4 | Composite types | 61 | PARTIAL | Card and identity as JSON in the key using the web app's serialisers: "Edit every kind"; BE/lib/item-form.js:254-260,383-384; tests/extension/itemForm.spec.js "stores a card as JSON in the key" | TOTP: no "Not a valid authenticator secret" check on save and no algorithm/digits/period fields; the secret is a free text box (vault-view.js:276). **Passkey: not restricted.** `draftFromItem` puts the decrypted credential JSON, including `privateKey`, into the `edit-secret` input (item-form.js:361-364; vault-view.js:267-272,330). Edit is not disabled for passkeys (item-detail.js:183-185), and a changed value is re-encrypted and sent. No notice "Passkey credentials can only be changed in the Keepiq web app". |
| 5 | Create sends ciphertext and opens the new item | 85 | SPEC | "Add, edit and delete items" ("Values MUST be encrypted in the extension before they are sent"); BE/background/vault-handlers.js:238 `vault-save` encrypts in the worker, syncs (`afterWrite`); BE/popup/vault-view.js:544-545 opens the new item; tests/extension/popupVaultSendGenerator.spec.js "browses, reveals, edits and creates items, sending only ciphertext" | Empty optional fields differ: `login` is sent as `''` (encryptField returns '' for empty, BE/lib/vault.js:189), not omitted, and `additionalFields` is left out rather than set to an encrypted `{}`. |
| 6 | Edit re-fetches before showing the form and saves sparsely | 99 | SPEC | "Edit every kind" ("Edit MUST start from the item fetched fresh ... MUST send only the parts that changed"); BE/popup/vault-view.js:439-446 re-fetches on Edit; BE/lib/item-form.js:411 `changedParts`; tests/extension/vaultSendGenerator.spec.js "updates only the parts that changed"; blocked items refused: "Detail sections" | A cleared username is sent as `login: ''`, not `null`. A 404 on the fresh fetch shows the raw error; it does not show "This item no longer exists", sync and return to the list. |
| 7 | Delete with confirmation | 128 | CHANGED (recorded) | BE/popup/vault-view.js:470-484 confirm → `vault-trash` → `DELETE /api/v1/secrets/{id}` moves the item to the trash | Keepiq has a trash; old said "There is no trash". Recorded in "Add, edit and delete items" ("move an item to the trash after confirming"; scenario "can be restored in the web app"). |
| 8 | Clone | 141 | SPEC | "Clone and move" (" - Clone", new item, original untouched); BE/popup/vault-view.js:447-450,324; tests/extension/popupItemDetail.spec.js "clones an item as a new one, leaving the original alone" | Clone uses the item fetched when the detail opened; it does not fetch again at clone time. **Passkeys can be cloned**: no "Passkeys cannot be cloned" guard, so the private key is copied into a second item. |
| 9 | Move to folder | 154 | SPEC | "Clone and move" ("change only the item's folder"); BE/background/vault-handlers.js:317 `vault-move` sends only `folderId` (null for no folder), then syncs; tests/extension/popupItemDetail.spec.js "moves an item by changing only its folder" | None. |
| 10 | Input limits | 167 | SPEC | "Edit every kind" (4096 chars; 65536 bytes for the key and additional fields); BE/lib/item-form.js:263-266,442-472; tests/extension/itemForm.spec.js "refuses values over the limits" | The worker caps names at 255 (BE/background/vault-handlers.js:27,247), against 4096 in the form and the spec. The messages are not per-field "Too long (max 65536 bytes)". |
| 11 | Write errors keep the user's input | 175 | SPEC | "Edit every kind" ("A failed save MUST keep the form as typed and say what went wrong"); BE/lib/item-form.js:501 `writeErrorMessage` (the four messages match the old text); BE/popup/vault-view.js:538-539; tests/extension/vaultSendGenerator.spec.js "says what went wrong on a refused write" | A network failure offers no Retry. |
| 12 | Offline disables Save | 188 | SPEC | "Work offline" ("controls that change the vault are disabled with 'You are offline. Changes need a connection to Keepiq.'"); BE/popup/vault-view.js:74-102 `renderSync`; tests/extension/vaultSync.spec.js "offers logins from the snapshot while offline, and the Vault tab reads it" | Controls re-enable only when the Vault tab reloads (`load`), not on any later successful request. |
| 13 | Unsaved changes warning | 197 | SPEC | "Edit every kind" ("Leaving a form with changes MUST ask 'You have unsaved changes. Discard them?'"); BE/popup/vault-view.js:25,205-217 `canLeave`; popup.js:393-395; tests/extension/popupItemDetail.spec.js "asks before leaving a form with changes" | Uses `window.confirm` (OK/Cancel), not Discard / Keep editing buttons. |

### Totals
| Old spec file | Reqs | SPEC | CODE-ONLY | PARTIAL | CHANGED (rec / unrec) | MISSING | UNKNOWN |
|---|---|---|---|---|---|---|---|
| account-management | 13 | 2 | 1 | 5 | 2 (2 / 0) | 3 | 0 |
| api-client | 8 | 2 | 1 | 3 | 2 (0 / 2) | 0 | 0 |
| vault-unlock | 14 | 2 | 1 | 6 | 4 (4 / 0) | 1 | 0 |
| folder-management | 9 | 8 | 0 | 1 | 0 | 0 | 0 |
| item-editing | 13 | 10 | 0 | 2 | 1 (1 / 0) | 0 | 0 |
| **All** | **57** | **24** | **3** | **17** | **9 (7 / 2)** | **4** | **0** |

Account-management #7 counts as SPEC (the limit); its duplicate refusal is code-only. Vault-unlock #5 is PARTIAL with one UNKNOWN sub-point (whether a master-password rotation changes the suite id).

### Gaps worth a keepiq spec
Security or privacy weight first.
- 🔴 **Passkey private key reaches the edit form, and a passkey can be edited and cloned**: item-editing.md:61 (Composite types, passkey clause) and :141 (Clone, "Passkeys cannot be cloned"). Keepiq: BE/lib/item-form.js:361-364, BE/popup/vault-view.js:267-272,330,447-450. Detail hides the key; edit and clone do not.
- 🔴 **No URL normalisation and no http refusal; the app password can go over plain http**: account-management.md:16.
- 🔴 **A 401 is not treated as revocation**: the app password is kept, there is no logged-out state, and only the sync path locks: api-client.md:21, plus account-management.md:145 (Re-login after revocation, MISSING) and vault-unlock.md:103 (Timeout action Log out, MISSING).
- 🟡 **Credentials stored without checking for an active suite; the uid comes from the typed username, not the server**: account-management.md:42 (PARTIAL).
- 🟡 **Disconnect (local purge plus revoking the app password in Nextcloud) is CODE-ONLY**: account-management.md:121. No spec states the local purge.
- 🟡 **Background-only HTTP client and page-sender refusal are CODE-ONLY**: api-client.md:3.
- 🟡 **`credentials: 'omit'` not set on server requests**: api-client.md:10 (PARTIAL).
- 🟡 **Only the suite id is compared, not `unlockKeyEpoch`; UNKNOWN whether a master-password rotation locks the extension**: vault-unlock.md:43 (PARTIAL).
- 🟡 **Unlock always needs the network; no offline unlock from the cached suite**: vault-unlock.md:15 and :30 (PARTIAL).
- Wrong master password shows the raw WebCrypto error, not "Invalid master password": vault-unlock.md:23 (PARTIAL).
- Unlock screen has no show/hide toggle and no autofocus: vault-unlock.md:3 (PARTIAL).
- "Immediately" timeout missing; OS lock is always on: vault-unlock.md:90 (PARTIAL).
- No avatar or initials: account-management.md:89 (MISSING).
- No distinct pairing errors (raw "failed (status)"): account-management.md:50 (PARTIAL); api-client.md:49 (no `KeepiqNotInstalled`, no server message outside writes) (PARTIAL).
- First-run screen has no security-settings link and no disabled button: account-management.md:3 (PARTIAL).
- Switcher lacks per-account Lock/Log out, Lock all, Log out all and a Logged-out status: account-management.md:100 and :133 (PARTIAL).
- Duplicate account refusal is code-only and untested: account-management.md:76.
- Plain JS, no typed response shapes, not recorded: api-client.md:60 (CHANGED, unrecorded).
- No logged-out state, so "logged-out never hits network" has nothing to apply to; not recorded: api-client.md:67 (CHANGED, unrecorded).
- Folder Rename and Delete row buttons stay enabled offline; no Retry: folder-management.md:110 (PARTIAL).
- Add form ignores `default_secret_type` and does not prefill the tab URL: item-editing.md:3 (PARTIAL).
- TOTP secret not validated on save; no advanced fields: item-editing.md:61 (PARTIAL, TOTP clause).
- Worker caps item names at 255 while the form and spec allow 4096: item-editing.md:167 (spec-vs-code conflict inside keepiq).

## Vault browse and settings

### openspec/changes/ext-vault-browse/specs/item-detail/spec.md
| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| 1 | Detail view opens from a card and returns to the list | 3 | PARTIAL | ext/popup/vault-view.js renderList (button -> openItem), `detail-back` -> showView('vault-browse') which does not reset search/folder/type inputs nor re-render; no keepiq spec states the return behaviour | Scroll position: no save/restore code (UNKNOWN whether the browser keeps it when the panel toggles `hidden`). Filter retention is code-only, no spec. |
| 2 | Detail header | 11 | SPEC | CEC/extension-vault/spec.md:3 "Detail sections for every kind of item" (name, type, folder path "Work / Clients" or "No folder"); ext/popup/item-detail.js renderDetail + ext/lib/item-form.js folderPath; t/popupItemDetail.spec.js "shows a login with its folder path..." | Header is not rendered before decryption: `vault-item` decrypts in the same call (ext/background/vault-handlers.js 'vault-item'). Type shown by name, not label. |
| 3 | Login credentials section | 19 | SPEC | CEC/extension-vault/spec.md:3 (username, password masked with Show and Copy); GVS/extension-vault/spec.md:12; item-detail.js renderDetail (kind login/generic, fieldRow masked) | Monospace reveal not verified. |
| 4 | TOTP code with countdown | 32 | SPEC | CEC/extension-vault/spec.md:3 (code computed in popup, two halves, countdown, Copy, invalid text); item-detail.js totpSection; src/totp/totp.js parseOtpauth (bare base32, SHA1/256/512, 6/8 digits, crypto.subtle); t/popupItemDetail.spec.js "shows an authenticator code with a countdown" | Message is "This is not a valid authenticator secret." Discard on lock not done (see #13). |
| 5 | Websites section | 45 | SPEC | CEC/extension-vault/spec.md:3 "the website with Open and Copy"; item-detail.js renderDetail (Website section, Open adds https:// when schemeless) | none |
| 6 | Additional fields section | 53 | SPEC | CEC/extension-vault/spec.md:3 ("each masked with Show and Copy, or 'Could not read additional fields'"); vault-handlers.js 'vault-item' JSON-object check; item-detail.js notesKey case-insensitive | none |
| 7 | Notes section | 61 | SPEC | CEC/extension-vault/spec.md:3 "notes"; item-detail.js (note kind -> secret, else `notes` field) | none |
| 8 | Card and identity sections | 69 | SPEC | CEC/extension-vault/spec.md:3 (brand + last four, cardholder/expiry plain, number/CVV/PIN masked; identity BSN masked); item-detail.js + item-form.js MASKED_COMPOSITE; t/popupItemDetail.spec.js "shows a card with brand and last four..." | none |
| 9 | Passkey section | 82 | SPEC | CEC/extension-vault/spec.md:3 "site, the account and when it was created, never its private key"; item-detail.js passkey block (copy:false) | Old "not yet supported" note is moot: keepiq signs in with vault passkeys (openspec/specs/extension-passkey-provider). |
| 10 | Blocked item detail | 91 | SPEC | CEC/extension-vault/spec.md:3 ("show why, offer to open Keepiq in Nextcloud, MUST NOT be decrypted or editable"); vault-handlers.js 'vault-item' returns blockedReason/migrationError without decrypting; t/popupItemDetail.spec.js "shows why a blocked item cannot open..." | Delete stays enabled on a blocked item (see #12). |
| 11 | Metadata section | 99 | SPEC | CEC/extension-vault/spec.md:3 "when the item was created, updated and expires"; item-detail.js "About this item" | none |
| 12 | Edit and Delete placeholders | 107 | CHANGED | Real Edit/Clone/Move/Delete built: CEC/extension-vault/spec.md:18, :33; GVS/extension-vault/spec.md:21 (recorded). | Unrecorded: old said Edit and Delete stay disabled for blocked items; keepiq disables Edit/Clone/Move/Send only (item-detail.js:183), so Delete (move to trash) of a blocked item is allowed. No spec states either way. |
| 13 | Decrypted values are dropped on close | 115 | PARTIAL | CEC/extension-vault/spec.md:3 "Decrypted values MUST be dropped when the view closes"; GVS/extension-vault/spec.md:12; item-detail.js clearDetail; vault-view.js showView('vault-browse') nulls `current` | Lock is not handled: popup.js has no runtime.onMessage listener and the Lock button (popup.js `lock-btn`) only calls refresh(), so `current`, `#detail-sections` DOM, the edit form inputs and the TOTP setInterval survive a lock (manual or idle) until the Vault tab reopens or the popup closes. |

### openspec/changes/ext-vault-browse/specs/popup-shell/spec.md
| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| 1 | Popup layout with bottom tab bar | 3 | CHANGED | Recorded at CEC/extension-popup-shell/spec.md:4: tabs This site, Vault, Generator, Send, Settings; text labels only; "This site" kept as its own tab. ext/popup/popup.html `nav.tabs`; t/popupShell.spec.js "opens Settings from the tab bar" | Default tab is This site, not Vault. Header shows the active host and Pop out/Lock, not a view title; account control is a select in `#account-bar`. |
| 2 | Placeholder tabs until their changes land | 17 | CHANGED | Generator and Send are real tabs (CEC/extension-generator, GVS/extension-send). Settings tab opens the small per-account settings view (popup.js `tab-settings` -> renderSettings). | Not recorded as a decision; the Settings tab is neither a placeholder nor the old six-section settings (see settings spec). |
| 3 | Last tab is remembered for the browser session | 25 | PARTIAL | CEC/extension-popup-shell/spec.md:12 "Reopen on the last tab"; popup.js lastTab/rememberTab (`popup:lastTab` in storage.session, guarded); router.js vault.onLock removes it (logout = unpair also locks); t/popupShell.spec.js "reopens on the last tab, and on This site after a lock" | No background-memory fallback: without storage.session the popup opens on This site. |
| 4 | Locked and logged-out gating | 38 | CODE-ONLY | popup.js refresh(): view-pair / view-locked / view-update hide `view-unlocked` (which holds the tab bar); "Generate a password" on the lock view (popup.js openLockedGenerator) is specced at CEC/extension-generator/spec.md:57 | No keepiq spec states that the tab bar is hidden while locked or unpaired. |
| 5 | Popup dimensions | 51 | SPEC | CEC/extension-popup-shell/spec.md:4 "380 pixels wide and at most 600 pixels high", only the middle scrolls; popup.css (width 380px, max-height 600px) | none |
| 6 | Pop out to a standalone window | 59 | SPEC | CEC/extension-popup-shell/spec.md:21; popup.js `popout-btn` (windows.create popup 380x630, `?popout=1&tabId=`), PINNED tab on fill/generator-context; t/popupShell.spec.js "pops out into a window pinned to the current tab", "fills the pinned tab when popped out...", "refuses to fill a pinned tab that moved to another site" | none |
| 7 | Active tab context in the header | 74 | PARTIAL | No-website part: CEC/extension-popup-shell/spec.md:36 "Say when there is no website"; t/popupShell.spec.js "says to open a website when the tab is not one". Host in header: popup.js activeHost/renderUnlocked (`#active-host`), code-only. | Host display has no spec. |
| 8 | Theme tokens follow the system | 87 | SPEC | CEC/extension-popup-shell/spec.md:45; popup.css :root tokens, `@media (prefers-color-scheme: dark)` with `:root:not([data-theme='light'])`, `:root[data-theme='dark']` | none (no theme switch exists; see settings #13) |
| 9 | Popup is stateless across opens | 100 | CODE-ONLY | Filters, detail and form live in the popup DOM/closures (vault-view.js); only `popup:lastTab` goes to storage.session; worker owns vault, sync and lock state (router.js) | No keepiq spec states it. |

### openspec/changes/ext-vault-browse/specs/vault-list/spec.md
| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| 1 | Vault tab layout | 3 | CHANGED | GVS/extension-vault/spec.md:3 (list from index fields only); popup.html `#vault-browse` (search, folder select, type select, New item, Folders, sync line, list). Suggestions moved to the This site tab, recorded at CEC/extension-popup-shell/spec.md:4 | none beyond the recorded move |
| 2 | Search over name and URL | 12 | SPEC | GVS/extension-vault/spec.md:3 "searchable by name and address"; ext/lib/vault-index.js filterIndex (case-insensitive, composes with folder and type); t/vaultSendGenerator.spec.js "searches name and address, and filters by folder and type" | none |
| 3 | Folder selector | 25 | PARTIAL | GVS/extension-vault/spec.md:3 "filterable by folder"; vault-index.js folderChoices (full paths "Work / Clients", sorted by path) and filterIndex (exact folderId, no descendants) | No "No folder" entry: items with folderId null cannot be filtered on (filterIndex treats null as "all"). First entry reads "All folders". |
| 4 | Type filter chips | 38 | CHANGED | GVS/extension-vault/spec.md:3 "filterable by ... type"; popup.html `#vault-type` select filled by vault-index.js presentTypes (type names present in the index) | Dropdown of present type names instead of chips + More menu; not recorded. |
| 5 | Autofill suggestions section | 51 | CHANGED | Recorded at CEC/extension-popup-shell/spec.md:4 (This site is its own tab, not suggestions in Vault); popup.js renderUnlocked ('match' -> candidates; "No matching secrets for this site.") | Text differs from "No items for <host>"; suggestions fill directly rather than opening the detail. |
| 6 | Item card | 69 | PARTIAL | vault-view.js renderList: one button per entry with `name (url)` | Missing: type icon, subtitle (decrypted login or type label), blocked badge. Index carries `blocked` (vault-index.js toIndexEntry) but the list ignores it. Website icons not fetched (holds). |
| 7 | Launch action | 82 | PARTIAL | Launch exists only in the detail (item-detail.js Website "Open", https:// for schemeless) | No Launch on the list card. |
| 8 | Copy menu | 90 | MISSING | none on the list; copy exists in the detail only (item-detail.js copyText, no clear) | "Each card SHALL offer a Copy menu ... sends `clipboard.copied` to the background so the clipboard-clear alarm starts when a clear delay is configured." No clipboard clear for detail copies either. |
| 9 | More menu | 109 | CHANGED | Edit, Clone, Move, Delete live on the detail view, enabled (popup.html `.row.actions` in `#vault-detail`; CEC/extension-vault/spec.md:18, :33) | No per-card menu; not recorded. |
| 10 | Alphabetical sorting | 117 | PARTIAL | GVS/extension-vault/spec.md:3 "alphabetically"; vault-index.js byName (localeCompare, sensitivity base); t/vaultSendGenerator.spec.js "leaves out trashed items, sorts by name and drops the blobs" | No `id` tie-break; This site candidates are ranked by matchSecrets, not alphabetically. |
| 11 | Empty, loading and blocked states | 125 | PARTIAL | vault-view.js load/renderList: "Loading…", "Nothing matches.", "<n> items" | Missing: "No items in your vault" with web-app link, "Clear filters" action, "All items are blocked" state, first-sync spinner. |
| 12 | Decrypted values live in popup memory only | 144 | SPEC | GVS/extension-vault/spec.md:3 "no encrypted or decrypted value reaches the popup until the user opens an item"; vault-handlers.js 'vault-list' returns buildIndex entries only; t/vaultSendGenerator.spec.js "lists index fields only, and refuses a locked vault" | Stricter than old (list decrypts nothing). Detail-side lock gap: see item-detail #13. |

### openspec/changes/ext-vault-browse/specs/vault-sync/spec.md
| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| 1 | Sync runs on defined triggers | 3 | SPEC | CEC/extension-vault-sync/spec.md:12; ext/background/vault-sync.js SYNC_INTERVAL_MINUTES = 15; router.js startSync (force sync + alarm on unlock), onAlarm (only if unlocked), vault.onLock clears alarm; vault-handlers.js afterWrite (force), 'vault-sync-now', 'vault-list' (sync when stale); t/vaultSync.spec.js "syncs on unlock, schedules a sync while unlocked and stops on lock" | "Popup open" trigger fires when the Vault tab loads ('vault-list'), not on every popup open. |
| 2 | Unchanged vault costs one cheap request | 22 | PARTIAL | CEC/extension-vault-sync/spec.md:12; api.js latestSecret (`sort=updated_at&direction=desc&limit=1`); vault-sync.js run (compares top updatedAt + total); t/vaultSync.spec.js "costs one cheap request when nothing changed" | The "folders and types refreshed within the interval" condition uses snapshot `syncedAt`, which the cheap path itself refreshes, so folder/type changes alone may go unseen until a secret changes or a forced sync. |
| 3 | Manifest first, paginated fallback | 35 | SPEC | CEC/extension-vault-sync/spec.md:3 ("from the offline manifest, or page by page when an administrator switched offline caching off"); vault-sync.js fetchVault (403/404 -> listSecrets/listFolders/listTypes/fetchActiveSuite); t/vaultSync.spec.js "falls back to the paged lists when offline caching is off" | api.js listSecrets stops after 100 pages (10,000 secrets) without error: a larger vault is silently truncated. |
| 4 | Snapshot is stored atomically per account | 54 | SPEC | CEC/extension-vault-sync/spec.md:3 (one write, discarded on removal, nothing decrypted); vault-sync.js run (single local.set of `vault-snapshot:<id>`), forget(); router.js doUnpair -> forget; t/vaultSync.spec.js "stores the snapshot in one write...", "forgets an account" | none |
| 5 | Suite change purges cache and key | 68 | PARTIAL | CEC/extension-vault-sync/spec.md:36; vault-sync.js run (vault.suite.id vs activeSuiteId -> remove snapshot + lock); t/vaultSync.spec.js "discards the snapshot and locks when the suite changed" | Only the suite `id` is compared; `unlockKeyEpoch` is never read anywhere in ext/ (grep), so an epoch change on the same suite keeps the cache and the unlocked key. |
| 6 | Blocked rows are kept, marked and never decrypted | 82 | PARTIAL | Snapshot keeps rows as served (vault-sync.js); 'vault-item' never decrypts a blocked row; offline doMatch filters `!r.blocked` (router.js:496) | Not marked in the Vault list (vault-view.js renderList ignores `blocked`). Online match/fill has no client-side blocked filter (UNKNOWN whether the server's match endpoint excludes blocked rows). |
| 7 | Offline reads come from the cache | 91 | SPEC | CEC/extension-vault-sync/spec.md:27; vault-sync.js isOffline (no status or >=500); vault-view.js renderSync ("Last synced <relative>", offline note, WRITE_CONTROLS disabled); t/vaultSync.spec.js "offers logins from the snapshot while offline, and the Vault tab reads it" | none |
| 8 | Sync error handling | 110 | CHANGED | Recorded at CEC/extension-vault-sync/spec.md:37: 401 locks the account (old: purge and return to logged out); 423 keeps the snapshot. vault-sync.js run catch; t/vaultSync.spec.js "locks on an authentication failure and keeps going on a migration lock" | `lastError` recording is code-only. |
| 9 | One sync at a time | 124 | SPEC | CEC/extension-vault-sync/spec.md:12 "At most one sync per account"; vault-sync.js sync() inFlight map; status returned in 'vault-list' `sync`; t/vaultSync.spec.js "runs one sync at a time per account" | Status exposure to the popup is code-only. |
| 10 | Snapshot exposure is metadata only | 133 | PARTIAL | Popup side: GVS/extension-vault/spec.md:3 (index fields only); vault-handlers.js 'vault-list'. Content-script side: router.js handleMessage refuses non-PAGE_MESSAGES from tabs; t/accounts.spec.js:323, t/popupShell.spec.js "answers the popped-out window, as the top frame of its own tab only" (CODE-ONLY, no spec) | The content-script refusal (security boundary) has no spec. Index omits createdAt/updatedAt/login-present and matching ids (matching is a separate 'match' call). |
| 11 | Decryption is on demand and in the background | 146 | SPEC | GVS/extension-vault/spec.md:12; CEC/extension-vault-sync/spec.md:3 "MUST hold nothing decrypted"; BEA:36 "URL-matched listing, decrypt-on-demand"; vault-handlers.js 'vault-item' (vault.decryptSecret/decryptField in worker for one id); t/vaultSendGenerator.spec.js "fetches one item fresh and decrypts it..." | none |

### openspec/changes/ext-settings/specs/settings/spec.md
| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| 1 | Settings tab with six sections | 3 | PARTIAL | Settings tab: CEC/extension-popup-shell/spec.md:4; popup.html `#view-settings` ("Settings for this account": idle choices, biometric enrol, Disconnect), popup.js renderSettings | No six sections (Account security, Autofill, Notifications, Vault, Appearance, About), no per-section views, no `settings.get`. |
| 2 | Settings schema, storage and live effect | 18 | PARTIAL | Idle period stored per account in the accounts record in storage.local (ext/lib/api.js updateAccount), invalid value read as default and re-read on use (router.js effectiveIdleMinutes, touchActivity) | No settings schema, no global (Appearance) record; only the idle period exists as a setting. |
| 3 | Vault timeout and timeout action | 43 | CHANGED | BEA:97 "User-chosen idle lock period with an administrator maximum": 1, 5, 15, 30, 60, 240 min, default 15, lower of choice and `maxIdleMinutes`; api.js IDLE_CHOICES; views.js renderIdleChoices (over-max shown disabled with a note) | Not offered: Immediately, On browser restart, Never (with ADR-002 warning), Custom, and the Log out timeout action. Over-max options are shown disabled, not hidden. The keepiq spec records its own set but not why the others were dropped. |
| 4 | Lock now and Log out | 65 | PARTIAL | Manual lock: BEA:60 "Auto-lock" (manual lock clears the key); popup.html `lock-btn` "Lock", `settings-unpair` "Disconnect this account" (router.js doUnpair also revokes the app password) | Labels differ; Lock (popup.js sends 'lock' without accountId -> router.js lockEverything) locks every account, not the active one; Disconnect has no confirmation and no "app password needed again" text. |
| 5 | Unlock with PIN | 78 | MISSING | grep for a PIN unlock in ext/ finds none | "SHALL offer an 'Unlock with PIN' toggle ... The PIN-wrapped private key MUST be stored in `storage.session` ... cleared ... by five consecutive wrong PINs, and by a suite change on sync." |
| 6 | Biometrics and master password entries | 115 | CHANGED | Biometric unlock is built, recorded in openspec/specs/extension-biometric-unlock/spec.md:13 ff.; popup.html `biometric-enrol` | "Change master password" link to the web app is MISSING. |
| 7 | Autofill settings entries | 127 | MISSING | no such settings in ext/ (manifest.json has no contextMenus permission) | "store per account ... Autofill on page load (default off ...), Default autofill setting for login items, Default URI match detection ..., Show autofill suggestions on form fields (default on) and Enable context menu options (default on)." |
| 8 | Autofill keyboard shortcut display | 144 | MISSING | manifest.json declares no `commands`; no getAll call in ext/ | "SHALL show the current autofill shortcut read from `browser.commands.getAll()` with a Change control." |
| 9 | Clear clipboard | 156 | MISSING | Only a fixed 30 s clear for an auto-copied TOTP code (popup.js copyWithAutoClear; docs/browser-extension/permissions.md:10). Detail copies (item-detail.js copyText) never clear. | "SHALL offer the clipboard clear delay options Never (default), 10 seconds, ... 5 minutes, stored per account." |
| 10 | Notification prompts and excluded domains | 164 | MISSING | Save prompt has no toggles or exclusion list (openspec/specs/clients-save-prompt/spec.md:13; router.js doCapture) | "toggles Ask to add login (default on) and Ask to update existing login (default on) and an Excluded domains list." |
| 11 | Keepiq server notifications | 182 | MISSING | api.js has no `/api/settings/user` call; the web app has it (openspec/specs/user-settings/spec.md:56 Notification Toggles) | "show a 'Keepiq server notifications' subsection with the toggles Shares, Requests, Group shares and Security ... cached per account." |
| 12 | Vault section entries | 205 | PARTIAL | Sync now + "Last synced": CEC/extension-vault-sync/spec.md:12, :27 (in the Vault tab, vault-view.js); Folders: CEC/extension-vault/spec.md:48 (Vault tab, folder-view.js); no icon fetching | Missing: Import items / Export vault links, Default item type (server `default_secret_type`); new items default to `login` (vault-view.js openEdit). "Never" before first sync not shown. |
| 13 | Appearance settings | 222 | MISSING | Only the CSS hook exists (popup.css `:root[data-theme]`); nothing sets `data-theme` | "store in the global `storage.local` record the settings Theme ..., Compact mode, Show animations and Show quick copy actions on vault, and SHALL show Language read-only." |
| 14 | About panel | 234 | MISSING | no `runtime.getManifest` call in ext/ | "show the extension version ..., the active account's server origin, and the links Help, Report a bug, Privacy policy, Keepiq web app and Rate the extension." |

### Totals
| Old spec file | SPEC | CODE-ONLY | PARTIAL | CHANGED | MISSING | UNKNOWN | Total |
|---|---|---|---|---|---|---|---|
| item-detail | 10 | 0 | 2 | 1 | 0 | 0 | 13 |
| popup-shell | 3 | 2 | 2 | 2 | 0 | 0 | 9 |
| vault-list | 2 | 0 | 5 | 4 | 1 | 0 | 12 |
| vault-sync | 6 | 0 | 4 | 1 | 0 | 0 | 11 |
| settings | 0 | 0 | 4 | 2 | 8 | 0 | 14 |
| **All** | 21 | 2 | 17 | 10 | 9 | 0 | 59 |

### Gaps worth a keepiq spec
- PARTIAL (security): decrypted detail values, edit-form inputs and the TOTP timer survive a lock; the popup has no lock listener and Lock only calls refresh(). item-detail/spec.md:115
- PARTIAL (security): suite change compares `id` only, `unlockKeyEpoch` never read. vault-sync/spec.md:68
- CODE-ONLY (security): content scripts cannot list or open the vault (router.js PAGE_MESSAGES gate), tested but in no spec. vault-sync/spec.md:133
- PARTIAL (silent loss): paged fallback stops at 100 pages, larger vaults truncated without error. vault-sync/spec.md:35
- PARTIAL: cheap check can skip folder/type changes. vault-sync/spec.md:22
- PARTIAL: blocked rows not marked in the list; online fill has no client-side blocked filter (UNKNOWN server side). vault-sync/spec.md:82
- CHANGED unrecorded: Delete allowed on a blocked item. item-detail/spec.md:107
- PARTIAL: return from detail keeps filters (code-only), scroll unhandled. item-detail/spec.md:3
- PARTIAL: no background-memory fallback for the last tab. popup-shell/spec.md:25
- CODE-ONLY: tab bar hidden while locked or unpaired. popup-shell/spec.md:38
- PARTIAL: host in header unspecced. popup-shell/spec.md:74
- CODE-ONLY: popup holds no state across opens. popup-shell/spec.md:100
- CHANGED unrecorded: no placeholder tabs; Settings is a small view. popup-shell/spec.md:17
- PARTIAL: no "No folder" filter. vault-list/spec.md:25
- CHANGED unrecorded: type dropdown instead of chips + More. vault-list/spec.md:38
- PARTIAL: list card lacks icon, subtitle, blocked badge. vault-list/spec.md:69
- PARTIAL: no Launch on the card. vault-list/spec.md:82
- MISSING (privacy): card Copy menu and clipboard-clear alarm. vault-list/spec.md:90
- CHANGED unrecorded: actions on the detail, no card More menu. vault-list/spec.md:109
- PARTIAL: no id tie-break in sorting. vault-list/spec.md:117
- PARTIAL: empty-vault, clear-filters, all-blocked and first-sync states missing. vault-list/spec.md:125
- PARTIAL: Settings is one view, not six sections. settings/spec.md:3
- PARTIAL: no settings schema or global record. settings/spec.md:18
- CHANGED, recorded set but not the reason: no Immediately/On restart/Never/Custom, no Log out action. settings/spec.md:43
- PARTIAL (security): Lock locks all accounts; Disconnect has no confirmation. settings/spec.md:65
- MISSING (security): Unlock with PIN. settings/spec.md:78
- MISSING: Change master password link (biometrics CHANGED, recorded). settings/spec.md:115
- MISSING: autofill settings entries. settings/spec.md:127
- MISSING: keyboard shortcut. settings/spec.md:144
- MISSING (privacy): configurable clipboard clear; detail copies never clear. settings/spec.md:156
- MISSING (privacy): save-prompt toggles and excluded domains. settings/spec.md:164
- MISSING: Keepiq server notification toggles in the extension. settings/spec.md:182
- PARTIAL: no Import/Export links or Default item type. settings/spec.md:205
- MISSING: Appearance settings. settings/spec.md:222
- MISSING: About panel. settings/spec.md:234

## Autofill, login capture, generator and Send

### ext-autofill/specs/autofill/spec.md
| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| A1 | URL matching runs in the background on the plaintext url | 3 | PARTIAL | Matching runs in the worker: router.js:478 doMatch -> match.js:116 matchSecrets over plaintext `url`/`name`. The host comes from the popup's `tab.url`, http/https only (popup.js:85 activeHost). Spec: specs/browser-extension-autofill/spec.md "URL-matched listing, decrypt-on-demand". | An empty `url` CAN match through the name fallback (match.js:99-103). Online, `blocked` rows are not filtered: server ExtensionController.php:215 match -> SecretMapper.php:524 searchByNameOrUrl has no blocked filter; only the offline snapshot path filters `!r.blocked` (router.js:495). No `login`-type filter anywhere. |
| A2 | Match rule is the global default from settings | 21 | CHANGED | One fixed rule: exact host 100, same registrable domain 80, name 40/20 (match.js:88 matchScore). The public suffix check is a 16-entry approximation (match.js:17-34). Recorded in archive/2026-07-20-browser-extension-autofill/design.md:43-45 (match on url and name) and :101 ("match strictly on registrable domain/origin"); the PSL approximation in archive/2026-09-30-clients-extension-save-prompt-and-passkey-origin/design.md:40-42 (D5). | No settings and no Host/Starts with/Exact/Regex/Never rules. "Strictly" (design:101) contradicts the name fallback that ships. |
| A3 | Fill from the popup suggestions section | 46 | PARTIAL | Card click -> router.js:522 doFill decrypts that one row and sends it; popup closes (popup.js:123-133). Locked shows the unlock view. Spec: browser-extension-autofill "URL-matched listing, decrypt-on-demand". Tests: tests/extension/accounts.spec.js "fills a login from the active account match". | No Fill button on the item detail view. When no form is found (`filled:false`) the popup still closes, with no "Unable to autofill" message (popup.js:124-133 closes unless `res.error`). Plaintext is not explicitly dropped, it just goes out of scope. |
| A4 | Fill covers the top frame and matching subframes only | 66 | PARTIAL | Each frame fills only when `location.hostname` equals the matched host exactly (fillScope.js:141 frameMayFill; content-script.js:225). Tests: tests/extension/fillScope.spec.js "stays silent and fills nothing in a frame of another site". | `chrome.tabs.sendMessage` has no `frameId` (router.js:545-550), so EVERY frame, including a cross-origin ad frame, gets the plaintext and throws it away itself. The old rule was that a non-matching frame "receives no message containing the credential". The frame's identity is its own `location.hostname`, not `sender.url`/`frameId`. The exact-host rule also refuses a same-site iframe (login.example.test under example.test). No keepiq spec states the restriction (only the #740 comment in fillScope.js:1-11). |
| A5 | Insecure page warning | 79 | MISSING | None. archive/2026-07-20-browser-extension-autofill/design.md:101 lists "warn on http (non-TLS) origins" as a mitigation, but no code implements it. | "SHALL warn before filling an item whose `url` is `https:` into a page served over `http:`... Declining cancels the fill and nothing is sent." |
| A6 | Field detection in the content script | 92 | PARTIAL | content-script.js:23-35 selectors (autocomplete username/email/current-password, type, name/id), :44 visible() (zero size, display:none, visibility:hidden), :62 detectLoginFields with a preceding-text-input fallback. Partial fill works (:112-121) and the extension never submits. Spec: browser-extension-autofill "Autofill including iframes" ("detected username/password fields"). | Open shadow roots are not traversed (document.querySelectorAll only). No placeholder or label heuristics. `aria-hidden="true"` is not ignored. |
| A7 | Context menu on editable fields | 110 | MISSING | manifest.json:5-13 has no `contextMenus` permission; no `contextMenus` use anywhere under browser-extension/. | "SHALL add a 'Keepiq' context menu on editable fields with the entries Autofill..., Copy username, Copy password, and Generate password and copy." |
| A8 | Copy from the context menu clears after the configured delay | 128 | MISSING | No context menu and no "Clear clipboard" setting. The only clipboard clear is the fixed 30 s TOTP clear (popup.js:213 copyWithAutoClear; docs/browser-extension/permissions.md:10). No offscreen document. | "MUST clear the clipboard after the 'Clear clipboard' delay from settings when one is set." |
| A9 | Keyboard shortcut fills the last used login | 142 | MISSING | manifest.json has no `commands`; no `onCommand` listener. | "manifest command bound to `Ctrl+Shift+L`... fills the active tab with the matching item most recently used on that site." |
| A10 | Last used is recorded locally | 155 | CHANGED | Last used goes to the SERVER after a fill (usage.js:182 reportFill -> router.js:555 api.markUsed), for the vault list's Last used sort. Suggestions are ordered by match score (match.js:116-121), not by last used. Server side is recorded in openspec/changes/vault-favourites-tags-and-last-used/design.md:31 (D3). Tests: tests/extension/usage.spec.js. | Nothing local and no last-used ordering of suggestions. Nothing records why suggestions stay score-ordered. |
| A11 | Fill on page load is opt-in and unambiguous | 163 | CHANGED | Not built. Recorded: archive/2026-07-20-browser-extension-autofill/design.md:96 ("require explicit user selection before any fill"); also content-script.js:8-9 and match.js:10-11. | None (a recorded decision). |
| A12 | Content script holds no vault state | 176 | PARTIAL | `fill` is not in PAGE_MESSAGES, so a tab cannot request a fill (router.js:41-51, :982). The content script stores nothing (content-script.js:101-123). Tests: tests/extension/accounts.spec.js "still accepts the content-script messages from a page", "refuses a message from another extension". | The plaintext goes to all frames (see A4). The content script never clears it explicitly. PAGE_MESSAGES senders (`capture-decision`, `generate-for-field`) are not checked against the tab being served. |

### ext-autofill/specs/login-capture/spec.md
| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| L1 | Submitted login forms are reported once | 3 | PARTIAL | content-script.js:148 attachSubmitCapture (submit + Enter), :174 captureCurrent skips an empty password and de-duplicates by username+password stamp. Spec: specs/browser-extension-autofill "Save/update capture on submit"; specs/clients-save-prompt "Save or update prompt". | The background takes host/url from the content script's payload (`location.hostname/origin`, content-script.js:186-187; router.js:757-759), not from `sender.url`. `capture-credential` is answered before any sender check (router.js:973). Password-change forms are not recognised. Locked: the capture is KEPT for the popup instead of discarded (recorded: archive/2026-09-30-clients-extension-save-prompt-and-passkey-origin/design.md:44-46, D6). |
| L2 | Captured credentials live in background memory only | 21 | PARTIAL | One `pendingCapture` in worker memory (router.js:214), never in storage. Dropped on save/dismiss (:722, :806) and on lock (:278-280). The page bar gets only `{action,name}` (:792). | It is one global, not keyed by tab id. It is not dropped when the tab closes. No 5-minute expiry: the bar closes after 30 s (save-prompt.js:12), but a capture held for the popup fallback (locked case) stays until the worker dies. |
| L3 | Save bar for a new login | 34 | PARTIAL | A closed shadow-root bar in the top frame at submit time (save-prompt.js:51; content-script.js:196-204). No bar when username and password are already saved (capture.js:158). Shadow root instead of an extension iframe is recorded in archive/2026-09-30-.../design.md:24-26 (D1). Spec: specs/clients-save-prompt "Save or update prompt". Tests: tests/extension/saveCapture.spec.js. | No folder choice, no "Never for this site" and no "Ask to add login" setting. It shows at submit, not after the next navigation finishes. |
| L4 | Save creates the item | 52 | PARTIAL | router.js:690 doSaveCapture encrypts login and key client-side and POSTs `name`=host, `url`=origin (:705-711). It refuses a password the org policy rejects (:698-702). | No `typeId` (system login type) and no `folderId` in the body. No sync after save. The bar shows no "Login saved" confirmation and no server error: content-script.js:205-212 ignores the decision's reply. |
| L5 | Never for this site adds an excluded domain | 66 | MISSING | No excluded-domains list anywhere in browser-extension/ or openspec. | "on 'Never for this site', append the page's base domain to the excluded domains list... Excluded domains suppress save and update bars only." |
| L6 | Update bar for a changed password | 75 | PARTIAL | capture.js:145 classifyCapture: the first exact/same-site row (score>=80) whose login equals the captured username gives `update`. Spec: specs/clients-save-prompt "Save or update prompt". Tests: saveCapture.spec.js "offers an update with the id when the saved password changed". | When several items share the username, the FIRST one is offered for update, where the old rule said no bar. No "Ask to update existing login" setting. Password-change forms are not handled. |
| L7 | Update writes only the key | 88 | CHANGED | router.js:712-718 PUTs `key`, `login` and `encryptionSuiteId`, with no re-fetch first. Only a code comment records it (router.js:713, "An update changes the credential only"); no spec. | No `GET /secrets/{id}` re-fetch and no 404 fallback ("This login no longer exists", offer Save). |
| L8 | Bar messages are trusted only from the extension page | 102 | CHANGED | `capture-decision` is a PAGE_MESSAGE (router.js:44), taken from any content script. The defence is a closed shadow root plus `event.isTrusted` (save-prompt.js:55, :111-116). Recorded: archive/2026-09-30-.../design.md:24-26 (D1). Tests: saveCapture.spec.js "ignores a click the page dispatched". | Not recorded anywhere: `doCaptureDecision` (router.js:801) acts on the single global capture whichever tab sent it, so a content script in tab B can confirm tab A's pending capture. |
| L9 | Bar follows the tab and dismisses on leaving the site | 110 | MISSING | The bar lives in the submitting document only and times out after 30 s (save-prompt.js:12). There is no re-injection across redirects and no dismissal on leaving the site. Only the popup fallback survives a navigation (popup.js:141-155). | "re-inject it when that tab navigates within the same base domain while the capture is pending, and dismiss it when the tab navigates to another base domain." |

### ext-generator/specs/credential-generator/spec.md
| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| G1 | Generator tab with three sub-tabs | 3 | SPEC | change spec clients-extension-complete/specs/extension-generator/spec.md "Generator sub-tabs" (opens on the last sub-tab per account, generates on open and on change). Code: generator-view.js:312 open -> generate; :233 changed. Tests: tests/extension/popupVaultSendGenerator.spec.js "remembers the options and the sub-tab for the account". | |
| G2 | Password options and defaults | 22 | CHANGED | Spec "Password options" (same change) states length 8 to 128, default 14, the same toggles and minimums, avoid-ambiguous on. Code: generator-state.js:28-39, :93 sanitizeOptions (forces lowercase back on if every class is off, :119-126). The 8-character floor is recorded at generator-state.js:6-8 and in that spec. | Not recorded: the special set is `!@#$%^&*()-_=+[]{}|;:,.<>?/` (src/generator/generator.js:33), not `!@#$%^&*`. The minimum length is 8, not 5. |
| G3 | Password generation guarantees | 42 | PARTIAL | Rejection-sampled `crypto.getRandomValues` (generator.js:58 randomInt), the length grows to fit the minimums (:272), minimums at random positions (:316 ensureMinimums), no server call. Spec: client-side-key-generator/specs/key-generator "Default Generation". Tests: tests/vitest/generator-options.spec.js "holds at least the minimum digits and symbols", "uses only the classes that are on". | "At least one character of every enabled class" (with minimum 0) is only forced under an org policy (generator.js:301-302 -> :213 forceRequiredClasses). Without a policy an enabled class can be absent. |
| G4 | Passphrase options and generation | 60 | CHANGED | 4 to 12 words, default 5, separator `-`, capitalise and include-number off, one digit on one random word (generator.js:579 generatePassphrase). An empty separator is kept (generator-state.js:136-139). Recorded: client-side-key-generator/specs/passphrase-generator "Generate a passphrase" and generator-state.js:6-8. | None beyond the recorded range and default (old: 3 to 20, default 3). |
| G5 | Username types and defaults | 80 | SPEC | change spec clients-extension-complete/specs/extension-generator "Username generator". Code: src/generator/username.js:60/79/95; email from Nextcloud (generator-handlers.js:276 emailFor); "Enter an email address" (username.js:82). Tests: tests/vitest/generator-options.spec.js plus-addressed and catch-all cases. | |
| G6 | Website name source | 104 | SPEC | Same spec, "Username generator" ("disabled with 'No website detected'"). Code: router.js:89-99 activeHost (target tab, http/https only), generator-view.js:177-188 (disables Website and selects Random). | |
| G7 | Output box, Regenerate and Copy | 113 | PARTIAL | Spec "Generator sub-tabs" (monospace, own colours for digits and specials, Regenerate, Copy the whole value). Code: generator-view.js:72 renderColoured, :287-290. | Copy never schedules a clipboard clear (generator-view.js:26 copyText). There is no clipboard-clear setting at all. |
| G8 | Pick mode from the item form | 127 | SPEC | Spec "Pick mode from the item form". Code: generator-view.js:291-294, :318-326. Tests: popupVaultSendGenerator.spec.js (pick mode in "browses, reveals, edits and creates items"). | |
| G9 | Generator history | 141 | SPEC | Spec "Generator history" (50 per account, session storage only, cleared on lock, removal and browser restart, Copy per entry and Clear). Code: generator-handlers.js:291-381, router.js:167-171, generator-state.js:165. Tests: tests/extension/generatorState.spec.js "keeps the newest 50, newest first", "clears the history when the vault locks". | Minor: Clear does not ask for confirmation (generator-view.js:300-303). |
| G10 | Options remembered per account | 159 | SPEC | Spec "Options remembered per account". Code: generator-handlers.js:340 (saved per account), :303 forget on removal; sanitized against ranges and policy. Tests: generatorState.spec.js "clamps stored values to the supported ranges and the policy". | |
| G11 | Organisation policy clamp | 172 | PARTIAL | Length floor and required classes are applied (generator-state.js:112-118; generator.js:259-264). The policy is cached per account and the cached copy is kept when the fetch fails (generator-handlers.js:257-268). Specs: change spec clients-extension-complete "Password options" ("the org policy MUST be applied"); client-side-key-generator/specs/org-password-policies "Generator Locked to Policy". Tests: generatorState.spec.js "uses the last policy it saw when the server does not answer". | Clamped toggles are not disabled. No per-control "Set by your organisation": one hint line naming only the length (generator-view.js:90). "Minimum special" is not raised to at least 1. The policy is fetched when the tab opens, not on unlock and every sync. |
| G12 | Works while locked and offline | 197 | SPEC | Spec "Works while locked and offline". Code: popup.html:44 `locked-generate`, popup.js:399; generator handlers need no key. Tests: popupVaultSendGenerator.spec.js "generates while the vault is locked". | |
| G13 | Bundled wordlist | 211 | CHANGED | The EFF list is a JS module imported statically (src/generator/eff-large-wordlist.js:11), so it never fails to load. Recorded in client-side-key-generator/proposal.md:35 (module plus LICENSES/CC-BY-3.0-US.txt, REUSE.toml). | No "Wordlist unavailable" state, which is moot. The CC BY attribution sits in the repo root LICENSES/ and a source header comment, but browser-extension/build.mjs copies no licence file into the package (no match for "licen" in build.mjs), so it is UNKNOWN whether the shipped extension carries the attribution. |

### ext-send/specs/send/spec.md
| # | Requirement (old title) | old line | Class | Keepiq evidence | Gap (if any) |
|---|---|---|---|---|---|
| S1 | My sends list | 3 | PARTIAL | `GET /api/v1/sends` on every tab open and after create/end (send-view.js:154 loadSends, :231, :175; api.js:550 listSends). Held in popup memory only. Spec: change spec clients-extension-generator-vault-send/specs/extension-send "List and end my sends" (label plus how often opened). Tests: tests/extension/vaultSendGenerator.spec.js "lists and ends sends". | Rows show no type icon, no expiry or "Never expires", and no Password badge (send-view.js:167). The counter reads "N of M opened", not views left. The empty state "You have no sends." (popup.html:269) has no New send button. A load error shows the error text but no Retry. |
| S2 | Row label derived from type and creation time | 23 | SPEC | Same spec, "List and end my sends". Code: send-form.js:84 sendRowLabel. Tests: vaultSendGenerator.spec.js "labels a row by kind and creation time". | |
| S3 | Copy link only for sends created in this popup session | 31 | SPEC | change spec clients-extension-complete/specs/extension-send "Password-protected sends" ("Copy link only for sends made while the popup is open"). Code: send-view.js:140 `sessionLinks` Map, :178-190. | Minor: other rows show nothing, not "Link no longer available". |
| S4 | Remove a send | 50 | PARTIAL | `DELETE /api/v1/sends/{id}` (api.js:563; vault-handlers.js:519), then the list reloads. Spec: "List and end my sends" (End). Tests: vaultSendGenerator.spec.js "lists and ends sends". | No confirmation (send-view.js:172-176). A 404 (already burned) surfaces as an error (api.js:221-227 throws on any non-2xx). |
| S5 | New send form | 64 | CHANGED | Text or "Username and password", Can be opened default 1, Expires default 1 day, optional password, Create link (popup.html:243-259; send-view.js:136). Fields are cleared after a create (send-view.js:223-230). Nothing records why the default is 1 day. | The default expiry is 1 day, not 7. Create is not disabled while the content is empty: the worker refuses afterwards with "There is nothing to send" (vault-handlers.js:458-460). No show/hide toggle on the password. |
| S6 | Credential payload serialisation | 78 | SPEC | Spec "Create a send from the popup" (body exactly `Username: <username>` newline `Password: <password>`). Code: send-form.js:72 credentialPayload. Tests: vaultSendGenerator.spec.js "serialises a credential as exactly two lines". | |
| S7 | Max views bounds | 86 | PARTIAL | Spec "Create a send from the popup" (1 to 100). Code: send-form.js:56 maxViewsFrom. Tests: vaultSendGenerator.spec.js "refuses an empty send and bad bounds before any request". | The server's own `message` is not shown: api.js:223 builds a generic "Keepiq POST /api/v1/sends failed (400)". The bound is checked on submit, so Create is not disabled while out of range. |
| S8 | Expiry presets | 99 | SPEC | Spec "Create a send from the popup" (1 h, 1/2/3/7/30 days, Custom 1 to 720 h; `ttlSeconds`). Code: send-form.js:15-48. Tests: vaultSendGenerator.spec.js "maps the expiry presets and bounds Custom to 720 hours". | |
| S9 | Client-side encryption and optional password | 112 | PARTIAL | Encrypted in the worker with a fresh key (vault-handlers.js:443 send-create, sealPayload). The key rides only the fragment; with a password, Argon2id wraps it and only `wrappedKey`/`argon2idSalt` are sent. Specs: "Create a send from the popup"; clients-extension-complete "Password-protected sends"; specs/ephemeral-send "Create a standalone ephemeral send". Tests: vaultSendGenerator.spec.js "sends only ciphertext, and the link alone decrypts it", "wraps the key under a password...". | No "Protecting with password..." state. No handling when WebAssembly/Argon2id is unavailable ("Password protection is not available in this browser"). |
| S10 | Link shown once after creation | 133 | SPEC | Spec "Create a send from the popup" ("the link MUST be shown once"). Code: popup.html:262-266, send-view.js:220-222, :233-236 ("Copied"). Tests: popupVaultSendGenerator.spec.js "sends an item's login as a credential, and shows the link once". | Minor wording: the note does not say how many views burn it. |
| S11 | Send from item | 142 | PARTIAL | Item detail "Send" opens the Send tab on Credential, prefilled from the decrypted item (vault-view.js:451; popup.js:389; send-view.js:243-249). Spec "Create a send from the popup" ("also prefilled from an open item"). Tests: popupVaultSendGenerator.spec.js "sends an item's login as a credential". | Offered for EVERY item type, not only `login`. A blocked item gets a disabled button, not a hidden one (item-detail.js:183-186). |
| S12 | Unlocked and online only | 155 | PARTIAL | Every send handler requires an unlocked account (vault-handlers.js:67 unlockedAccount). The item's Send button is disabled offline (item-detail.js:185). | Create in the Send tab is not disabled offline and there is no "Sends need a connection" text. A network failure shows the raw error, not "nothing was sent". A 401 on a send call does not trigger the logged-out state (401 is only handled in vault-sync.js:179). |
| S13 | No persistence of send secrets | 173 | CODE-ONLY | The send path writes no storage: vault-handlers.js:443-491 (plaintext, key and password stay in local variables), send-view.js:140 (link in a popup Map), list mapped to metadata (:498-512). No keepiq spec states the extension-side rule; specs/ephemeral-send covers the server only. No test inspects extension storage after a create. | Security weight: unspecified and untested. |

### Totals
| Old spec file | SPEC | CODE-ONLY | PARTIAL | CHANGED | MISSING | UNKNOWN | Total |
|---|---|---|---|---|---|---|---|
| ext-autofill/specs/autofill/spec.md | 0 | 0 | 5 | 3 | 4 | 0 | 12 |
| ext-autofill/specs/login-capture/spec.md | 0 | 0 | 5 | 2 | 2 | 0 | 9 |
| ext-generator/specs/credential-generator/spec.md | 7 | 0 | 3 | 3 | 0 | 0 | 13 |
| ext-send/specs/send/spec.md | 5 | 1 | 6 | 1 | 0 | 0 | 13 |
| All | 12 | 1 | 19 | 9 | 6 | 0 | 47 |

G13 holds one UNKNOWN sub-point (whether the CC BY attribution ships in the package) but is classed CHANGED.

### Gaps worth a keepiq spec
Security or privacy weight first.
- ext-autofill/specs/autofill/spec.md:66 (A4) PARTIAL: the fill message carries the plaintext to EVERY frame (router.js:545 has no frameId). Non-matching frames discard it themselves. No spec states the frame restriction.
- ext-autofill/specs/autofill/spec.md:3 (A1) PARTIAL: `blocked` rows are offered online (no filter in SecretMapper::searchByNameOrUrl). There is no login-type filter, and a url-less item matches through its name.
- ext-autofill/specs/autofill/spec.md:79 (A5) MISSING: no warning before filling an https item into an http page. archive design.md:101 promised one.
- ext-autofill/specs/autofill/spec.md:176 (A12) PARTIAL: the content script never clears the plaintext. PAGE_MESSAGES senders are not tied to the tab being served.
- ext-autofill/specs/login-capture/spec.md:102 (L8) CHANGED, part unrecorded: `capture-decision` from any tab acts on the one global pending capture.
- ext-autofill/specs/login-capture/spec.md:3 (L1) PARTIAL: the capture host comes from the content script payload, not `sender.url`, and is accepted before any sender check.
- ext-autofill/specs/login-capture/spec.md:21 (L2) PARTIAL: the capture is not keyed by tab, has no 5-minute expiry and is not dropped on tab close. A locked-state capture can sit in worker memory indefinitely.
- ext-autofill/specs/login-capture/spec.md:75 (L6) PARTIAL: with duplicate usernames, the first match is offered for update instead of none.
- ext-autofill/specs/login-capture/spec.md:88 (L7) CHANGED, unrecorded: the update also rewrites `login`, with no re-fetch and no 404 handling.
- ext-send/specs/send/spec.md:173 (S13) CODE-ONLY: no spec or test says send plaintext, keys and links never reach extension storage.
- ext-generator/specs/credential-generator/spec.md:113 (G7) PARTIAL, plus ext-autofill/specs/autofill/spec.md:128 (A8) MISSING: copied passwords and generated values are never cleared from the clipboard, and there is no clear-clipboard setting.
- ext-generator/specs/credential-generator/spec.md:172 (G11) PARTIAL: policy-clamped controls are not disabled or labelled, minimum special is not raised, and the policy is not refreshed on unlock/sync.
- ext-generator/specs/credential-generator/spec.md:42 (G3) PARTIAL: an enabled class with minimum 0 is not guaranteed to appear without a policy.
- ext-send/specs/send/spec.md:155 (S12) PARTIAL: no offline gating of Create, and a 401 on send calls does not log out.
- ext-send/specs/send/spec.md:112 (S9) PARTIAL: no Argon2id-unavailable state and no progress state.
- ext-send/specs/send/spec.md:142 (S11) PARTIAL: Send is offered for every item type, not only logins.
- ext-autofill/specs/autofill/spec.md:110 (A7) MISSING: the context menu.
- ext-autofill/specs/autofill/spec.md:142 (A9) MISSING: the keyboard shortcut.
- ext-autofill/specs/login-capture/spec.md:66 (L5) MISSING: "Never for this site" excluded domains.
- ext-autofill/specs/login-capture/spec.md:110 (L9) MISSING: the bar does not follow redirects and is not dismissed on leaving the site.
- ext-autofill/specs/login-capture/spec.md:34 (L3) PARTIAL: no folder choice and no ask-to-add setting.
- ext-autofill/specs/login-capture/spec.md:52 (L4) PARTIAL: no typeId/folderId on save, no sync, and no saved or error feedback in the bar.
- ext-autofill/specs/autofill/spec.md:92 (A6) PARTIAL: no shadow-root traversal, no label/placeholder heuristics, aria-hidden not ignored.
- ext-autofill/specs/autofill/spec.md:46 (A3) PARTIAL: no "Unable to autofill" message (the popup closes anyway) and no Fill button on item detail.
- ext-autofill/specs/autofill/spec.md:21 (A2) CHANGED: no selectable match rules. The recorded "strictly registrable domain" (design:101) conflicts with the name fallback that ships.
- ext-autofill/specs/autofill/spec.md:155 (A10) CHANGED, ordering unrecorded: suggestions are ordered by match score, not last used.
- ext-generator/specs/credential-generator/spec.md:22 (G2) CHANGED, unrecorded: the special-character set is `!@#$%^&*()-_=+[]{}|;:,.<>?/`.
- ext-send/specs/send/spec.md:64 (S5) CHANGED, unrecorded: the default expiry is 1 day, and Create is not disabled while empty.
- ext-send/specs/send/spec.md:3 (S1) PARTIAL: no expiry, Password badge, type icon or Retry in the list.
- ext-send/specs/send/spec.md:50 (S4) PARTIAL: no confirmation before End, and a 404 shows as an error.
- ext-send/specs/send/spec.md:86 (S7) PARTIAL: the server's error message is not shown.
- ext-generator/specs/credential-generator/spec.md:211 (G13) CHANGED: whether the CC BY 3.0 attribution ships in the extension package is UNKNOWN (build.mjs copies no licence file).

## Closed by clients-extension-gaps (October 2026)

The rows above describe Keepiq at `61329cb0`. The change `clients-extension-gaps` then closed the gaps Ruben chose: all of group A, group B recorded, and four groups of C. Each old requirement below now has a Keepiq spec under `openspec/changes/clients-extension-gaps/specs/`, with tests named in its scenarios.

| Old requirement (spec:line) | Was | Keepiq spec now |
| --- | --- | --- |
| account-management:16 server address | MISSING | `extension-pairing` |
| api-client:10 no cookies | PARTIAL | `extension-pairing` |
| api-client:21, account-management:145 revocation and re-login | PARTIAL, MISSING | `extension-pairing` |
| item-detail:115 forget on lock | PARTIAL | `extension-lock` |
| vault-sync:68, vault-unlock:43 key epoch | PARTIAL | `extension-lock` |
| settings:65 lock one account, confirm disconnect | PARTIAL | `extension-lock` |
| autofill:3, vault-sync:82 blocked rows | PARTIAL | `extension-fill-and-capture` |
| autofill:66, autofill:176 frames | PARTIAL | `extension-fill-and-capture` |
| autofill:79 https to http | MISSING | `extension-fill-and-capture` |
| login-capture:3, login-capture:21 capture from the sender, per tab | PARTIAL | `extension-fill-and-capture` |
| vault-list:90, settings:156, credential-generator:113 clipboard | MISSING, PARTIAL | `extension-clipboard` |
| vault-sync:35 large vault | PARTIAL | `extension-clipboard` |
| api-client:3, vault-sync:133 worker only, pages kept out | CODE-ONLY | `extension-baseline` |
| account-management:121 disconnect revokes | CODE-ONLY | `extension-baseline` |
| account-management:76 one account per user and server | CODE-ONLY | `extension-baseline` |
| send:173 nothing of a send stored | CODE-ONLY | `extension-baseline` |
| settings:43, vault-unlock:90, vault-unlock:103 idle choices, no logout on idle | CHANGED | `extension-baseline` (recorded) |
| autofill:21 one match rule | CHANGED | `extension-baseline` (recorded) |
| item-editing:167 name limit | CHANGED | `item-name-limit` (255) |
| vault-unlock:15, vault-unlock:30 offline unlock | PARTIAL | `extension-unlock-and-accounts` |
| vault-unlock:3, vault-unlock:23 unlock screen, message | PARTIAL | `extension-unlock-and-accounts` |
| account-management:50, api-client:49 pairing errors | PARTIAL | `extension-unlock-and-accounts` |
| account-management:89, :100, :133 initials, log out, log out all | MISSING, PARTIAL | `extension-unlock-and-accounts` |
| settings:78 PIN | MISSING | `extension-pin-unlock` |
| autofill:92 field detection | PARTIAL | `extension-autofill-extras` |
| autofill:46 no form found | PARTIAL | `extension-autofill-extras` |
| autofill:110, autofill:142 context menu, shortcut | MISSING | `extension-autofill-extras` |
| login-capture:66 never for this site | MISSING | `extension-autofill-extras` |
| login-capture:34 folder on save | PARTIAL | `extension-autofill-extras` |
| vault-list:25, :69, :82, :117, :125 list | PARTIAL | `extension-list-and-settings` |
| settings:3, :127, :164, :205, :222, :234 settings groups | PARTIAL, MISSING | `extension-list-and-settings` |
| settings:115, settings:182 master password, notifications | MISSING | `extension-list-and-settings` (a link to Keepiq on the web) |
| credential-generator:211 word list credit | CHANGED | `extension-release` |

Also added without an old requirement: extension icons, Firefox's data collection declaration (`extension-release`), and a passkey's private key kept in the worker (`clients-extension-complete`, `extension-vault`).

### Still open

Not chosen in October 2026, so still as the rows above describe them:

- The Send items: Create off while offline (send:155), a progress state during Argon2id (send:112), Send for logins only (send:142), badges in the list (send:3), confirm before ending a send (send:50), the server's message (send:86).
- The finer save-prompt behaviour: several logins with the same username (login-capture:75), an update that rewrites the username (login-capture:88), the bar after redirects (login-capture:110), a confirmation in the bar (login-capture:52).
- The generator under a policy: clamped controls labelled (credential-generator:172), a class with minimum 0 guaranteed (credential-generator:42).
- Smaller list and form details: suggestions by last use (autofill:155), the tab URL prefilled for a new item (item-editing:3), TOTP validation on save (item-editing:61), folder buttons offline (folder-management:110), deleting a blocked item (item-detail:107), the scroll position (item-detail:3), a fallback for the last tab without session storage (popup-shell:25).
