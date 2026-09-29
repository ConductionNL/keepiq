# Design: automatic confirmation of new team folder members

## Context

Read at development `4c214a9d`.

- `lib/Service/TeamFolderService.php:451` `handleGroupMemberJoin()` notifies the folder owner with `team_folder_join_request` when a user joins a member group. Nothing else happens.
- `lib/Service/TeamFolderService.php:496` `approveJoin()` returns the new member's certificate and the subtree secrets, owner only. Its store action `src/store/modules/teamFolder.js:202` has no caller in `src`.
- `lib/Service/TeamFolderService.php:386` `reconcile()` computes the missing (secret, user) pairs; `:423` `registerFanOutShares()` stores browser-encrypted rows. Both call `loadOwnedTeamFolder()`, so only the owner can run them.
- `src/store/modules/teamFolder.js:278` `runFanOut()` reconciles, decrypts each source secret with the session key, encrypts per recipient certificate and posts in chunks. It runs when the owner opens the dialog (`src/modals/TeamFolderDialog.vue:469` shows the pending count).
- `lib/Service/TeamFolderShareService.php:93` stores rows inside one transaction and sends `team_folder_shared` once per new recipient; `:263` `createFanOutShare()` skips rows outside the subtree, for the owner, already existing, or for a user without an active suite.
- `lib/Service/TeamFolderService.php:620` `resolveGrade()` returns a member's effective grade. `lib/Service/ShareSyncService.php:230` `assertSyncSourceUnchanged()` refuses a write based on a stale view of the source.
- `src/store/modules/session.js:97` `unlockFromBlob()` is the single place every unlock path ends.

## Goals / Non-Goals

**Goals:**

- A new member receives their copies without anyone clicking, as soon as one authorised member has an unlocked vault.
- The server never decrypts, never holds a key, and never picks the value it hands out.
- An administrator decides whether the behaviour is on.

**Non-Goals:**

- Server-side fan-out. The server holds no usable private key (ADR-003), so it cannot produce a recipient copy.
- A per-folder override. The switch is instance-wide; a per-folder opt-out needs a column and can follow.
- Confirming users who are not covered by a membership row. Coverage stays with the owner and Nextcloud groups.

## Decisions

### D1: An admin policy switch, off by default

`team_folder_auto_confirm` is a boolean app config key in the Policies area, written through `PUT /api/settings/admin` and audited like other policy changes. `getPolicy()` exposes it to the browser.

Alternative considered: always on. Rejected: some organisations want the owner to see each new member before access lands, which is today's behaviour.

### D2: Authorised confirmers are the owner and write-grade members

A `write` grade already lets a member push a new value to every member (`folder-permission-grades` spec). Handing the current value to one more covered member adds no power they lack. A `read` member could hand a new colleague a wrong value that no one else sees, so `read` members are not confirmers.

Alternative considered: any member confirms. Rejected for that reason.

### D3: A confirmer re-encrypts their own copy, if it is current

The owner decrypts the source secret, as `runFanOut()` does today. A `write` member decrypts their own recipient copy. The server accepts a member's row only when the member holds a live derived copy of that source secret and that copy's `updated_at` is not older than the source's `key_updated_at`, the same staleness rule `ShareSyncService` applies to writes. A stale copy is skipped and the pair stays pending for the next confirmer.

### D4: One pending-confirmations endpoint for the confirmer

`GET /api/v1/team-folders/pending-confirmations` returns, for the session user, each team folder where they are a confirmer and pairs are missing: the folder id, the missing pairs, each recipient's certificate, and for a member the id of their own copy per source secret. It reuses the reconcile queries without the owner check, and it excludes disabled accounts and users without an active suite. It returns nothing when the switch is off.

### D5: The browser confirms after unlock, without a click

After `unlockFromBlob()` succeeds and the switch is on, the session store starts `teamFolder.autoConfirm()` in the background: fetch pending, decrypt each needed copy once, encrypt per recipient, post in chunks to `POST /api/v1/team-folders/{id}/shares`. It repeats every 15 minutes while unlocked and stops on lock. It shows one quiet notice, for example "Gave 2 new members access to Finance". A failure is logged and retried on the next run; it never blocks the vault.

Alternative considered: a service worker that runs while the tab is closed. Rejected: the non-extractable key lives in the page's memory and is cleared on close (ADR-003); a worker would need the key outside that boundary.

### D6: Everyone learns what happened

The new member receives the existing `team_folder_shared` notification from `registerFanOutShares()`. The owner receives a new `team_folder_member_confirmed` notification naming the confirmer and the new member, routed through the existing `notify_group_shares` setting. The audit event for the fan-out records the confirmer as actor.

## Security and zero-knowledge

- The server never sees plaintext. A confirmer's browser decrypts with its own non-extractable key and posts only ciphertext encrypted for the recipient.
- Stored encrypted: the new recipient copies (RSA ciphertext, as today). Stored plain: the policy switch and the share rows' identifiers.
- The certificate trust is unchanged: the confirmer encrypts to the certificate the server returns for a covered user, which is exactly what the owner's manual fan-out does today.
- A confirmer cannot add a user: the server accepts a row only for a pair that is missing and covered by a membership row.

## Risks / Trade-offs

- If no confirmer unlocks, the new member keeps waiting. The team folder dialog shows the pending count with "Waiting for a member with write access to open Keepiq".
- A run in several confirmers' browsers at once can race. Row creation is idempotent (`createFanOutShare()` skips an existing share), so the loser creates nothing.
- Decrypting many secrets in the background costs CPU after unlock. Runs are chunked and yield between chunks, as `runFanOut()` already does.

## Seed data

None. PHPUnit tests build folders, grades and copies with mocks; the Playwright test adds a member to a folder where a `write` member unlocks.

## Migration

None. No table or column; one app config key. `<version>` in `appinfo/info.xml` does not need a bump for schema reasons.
