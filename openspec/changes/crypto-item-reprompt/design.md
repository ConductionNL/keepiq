# Design: ask for the master password again before a sensitive item is shown or filled

## Context

At development `4c214a9d`:

- `src/crypto/reauth.js:117` `verifyMasterPassword(encryptedPrivateKey, masterPassword)` decrypts the stored private-key envelope with the entered password and returns true or false, never replacing the session key; its header says the control is advisory against a tampered client.
- `src/dialogs/ExportDialog.vue:130` uses it before a plaintext export.
- `src/components/SecretDetailSidebar.vue:205-208` reveals the value through `PasswordField` with `:resolve="resolveKey"` (`:1487`); copy buttons sit at `:187`, `:311`, `:367`, `:409`, `:529`; edit opens at `:1498`.
- `src/components/SecretListItem.vue:93` has a copy button on each list row.
- `browser-extension/src/lib/vault.js:49` `unlock()` derives the key with `decryptPrivateKey(suite.privateKey, masterPassword)`; `service-worker.js:113-125` `doFill()` decrypts and fills.
- Share copies are separate rows created by the sharing services; `lib/Service/ShareSyncService.php:317-345` syncs only the encrypted blobs.

## Goals / Non-Goals

**Goals**
- A person sitting at an unlocked, unattended session cannot show, copy or fill a flagged item without the master password.

**Non-Goals**
- A remember-for-a-while window. See D3.
- Server-side enforcement. The server cannot check a master password it never sees (ADR-003).
- Reprompting on passkey use from the extension's WebAuthn provider in this change; the extension passkey flow keeps its own user-verification step.

## Decisions

**D1. The flag is plain metadata on the holder's row.** `reprompt` boolean, default false. It says nothing about the value. A new share copy takes the owner's value; afterwards each holder controls their own row. Alternative: inside the encrypted additional fields. Rejected: the list and the extension must know an item is flagged before decrypting it.

**D2. One guard for every reveal path.** A composable `useReprompt(secret)` returns a function that resolves when the item is not flagged, or after `RepromptDialog.vue` got a password that `verifyMasterPassword()` accepts. `resolveKey`, every `CopyButton` on a flagged item, edit, clone, print and QR call it. A unit test enumerates the reveal paths so a new one cannot skip the guard unnoticed.

**D3. Every action asks.** No grace period, the same as Bitwarden's per-item re-prompt. A user who wants fewer prompts leaves the flag off. Alternative: a window of a few minutes. Rejected: it turns "ask before this item" into "ask once", which is what unlocking already does.

**D4. The extension checks inside the popup.** The popup asks for the master password and verifies it against the suite envelope the extension already fetched at unlock, then fills. Nothing new is fetched.

## Security and zero-knowledge

The master password is typed into the web app or the extension popup, checked locally against the encrypted private-key envelope and discarded. It never leaves the client, as ADR-003 requires. The flag is not sensitive and is stored in plain text. The dialog's help text states that this protects an unlocked screen, not a modified client.

## Risks / Trade-offs

- Frequent prompts on a flagged item used daily. The user chooses which items to flag.
- A missed reveal path leaves a hole. The enumeration test in D2 is the guard.

## Seed data

Keepiq owns its tables (keepiq ADR-001) and has no OpenRegister register. The dev fixture vault gets one flagged login, so the prompt appears in the e2e flows.

## Migration

One migration after `Version001000Date20260908000000`: `reprompt` (boolean, default false) on `keepiq_secrets`. `<version>` bumps.
