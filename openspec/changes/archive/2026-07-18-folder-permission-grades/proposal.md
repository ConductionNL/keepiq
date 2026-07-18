---
kind: code
depends_on: [team-folder-sharing]
---

# Proposal: Folder permission grades (read vs write)

## Why

`team-folder-sharing` (this change's declared v1 predecessor) explicitly out-scoped **per-secret permission grades within a folder (read vs write)** — see its proposal's "Explicitly out of scope for v1" line (`openspec/changes/team-folder-sharing/proposal.md:33`) and design non-goal (`openspec/changes/team-folder-sharing/design.md:22`). Every folder member therefore receives a **read-only** per-recipient copy: a member holds the ciphertext and can view it, but only the folder owner (or a delegate) can push a new value to the whole team. The authorization is hard-wired — `ShareService::syncUpdate` (the one code path that re-encrypts a new value for every recipient) gates on `assertOwnerOrDelegate` (`lib/Service/ShareService.php:384`, guard at `:486`), and `SecretService::update` refuses any non-owner via `loadOwned` (`lib/Service/SecretService.php:739`, `ForbiddenException` at `:1086`). A recipient can edit only their own divergent copy; there is no sanctioned non-owner write that reaches the team.

Real teams need **shared editable credentials** — rotating a shared service password, updating a CI token — without funnelling every rotation through the folder owner (a single point of contention, and the exact problem `team-folder-sharing`'s offboarding action already acknowledges). This is the read/write split every serious team competitor ships:

- **Passbolt** — RBAC with fine-grained permissions (read / update / owner) is a headline feature (`docs/FEATURES.md:311`).
- **Bitwarden** — organization **collections** carry per-collection can-edit / can-view / manage permissions (`docs/FEATURES.md:308`).
- **1Password** — per-vault permissions are the enterprise-tier backbone (`docs/FEATURES.md:36`).

**Crypto honesty (why this needs no new envelope crypto).** A `write` grant does not hand the writer a shared key. When a non-owner with a `write` grade updates a folder secret, the **writer's browser** decrypts their own copy, re-encrypts the new value under **every recipient's public certificate** (public certs are server-visible by design — ADR-003 `openspec/architecture/adr-003-rsa-aes-encryption-architecture.md:82`, `:89`), and POSTs the resulting per-recipient ciphertext through the **existing** sync path (`ShareService::syncUpdate`). The only change is server-enforced **authorization** on that path: accept the fan-out from a non-owner **iff** they hold a `write` grade on a team folder that contains the secret. Envelope crypto is unchanged; the server still holds zero plaintext (ADR-003). Ownership/membership management stays owner-only exactly as `team-folder-sharing` defined it.

## What Changes

- Add a **permission grade** (`read` | `write`, default `read`) to each `team-folder` membership row (the `doriath_team_folder_members` table introduced by `team-folder-sharing`, `openspec/changes/team-folder-sharing/design.md:48`). `read` is today's behavior (hold a per-recipient copy, view only); `write` additionally permits value updates that propagate to all recipients.
- **Server-enforced non-owner write**: extend the sync authorization seam (`ShareService::syncUpdate` / `assertOwnerOrDelegate`, `lib/Service/ShareService.php:486`) so a caller who is not the owner/delegate is accepted **iff** they hold a `write` grade on an ancestor team folder of the secret's folder (walk the `Folder` `parent_id` chain, `lib/Db/Folder.php:61`). No new endpoint — the existing `PUT /api/v1/secrets/{secretId}/sync` (`appinfo/routes.php:104`, `ShareController::sync` `lib/Controller/ShareController.php:227`) accepts the writer's re-encrypted per-recipient blobs.
- **Group grades**: a group member added to a folder inherits the group membership's grade; the union rule from `team-folder-sharing` (nested subfolders widen membership) extends so a secret's effective grade is the **highest** grade any ancestor membership grants (write ⊃ read).
- **Owner-only grade management**: only the folder owner may set or change a member's grade (mirrors `team-folder-sharing`'s owner-only membership rule). Grade changes never re-encrypt anything — a `read`→`write` promotion just unlocks the sync path; a `write`→`read` demotion re-locks it. Owner retains all membership management exclusively.
- **Typed audit events**: a new grade-changed event and attribution of every **non-owner write** to the writer (reuse `AuditEvent::forUser` with the writer as actor, `lib/Event/Audit/AuditEvent.php:82`; new grade-change type alongside `SHARE_GRANTED` at `lib/Event/Audit/AuditEventTypes.php:44`), carrying identifiers only — never key material or plaintext.
- **Explicitly out of scope for v1**: a `manage`/co-owner grade (owner-only management stays), per-field grades, narrowing a subfolder's grade below an ancestor's (grade is monotone-max along the ancestor chain, mirroring the union-only membership rule), and any change to the read member's guarantee (a `read` member behaves exactly as under `team-folder-sharing` today).

## Capabilities

### New Capabilities
- `folder-permission-grades`: Read vs write permission grades on team-folder membership. Each member/group holds a `read` (default) or `write` grade; a `write` grade authorizes a non-owner to update a folder secret's value for all recipients by re-encrypting under each recipient's public certificate through the existing sync path, server-authorized on the write grant. Owner keeps membership management; grade changes and non-owner writes are audited.

### Modified Capabilities
<!-- No existing capability's REQUIREMENTS change. team-folder-sharing's owner-only membership management and per-recipient fan-out are unchanged; user-sharing's sync-on-update requirement is reused unchanged — this change only ADDS a grade dimension and an additive authorization branch on the existing sync path. A read member's behavior is byte-for-byte what team-folder-sharing already specifies. -->

## Impact

- **DB**: one new nullable-with-default column `grade` (enum `read`|`write`, default `read`) on `doriath_team_folder_members` (owned by `team-folder-sharing`); no new table.
- **Services**: `TeamFolderService` gains `setMemberGrade` + a `resolveGrade(secret, userId)` helper (max grade along the ancestor chain); `ShareService::syncUpdate` authorization extended to consult it.
- **Controller/routes**: new `PATCH /api/v1/team-folders/{id}/members/{memberId}` (set grade); no change to the sync route.
- **Frontend**: grade selector in the team-folder membership panel (Vue 2, `NcSelect` with `inputLabel`); the secret edit form becomes editable for a `write`-grade member.
- **OpenConnector**: unaffected — grades govern browser-user collaboration; the machine `doriath://` path does not consume folders.
- **Security**: no new crypto; all writes remain per-recipient RSA re-encryption performed client-side. Zero server-side plaintext preserved (ADR-003). The only new authorization surface is the write-grade check on the sync path, guarded per-object in the method body (`hydra-gate-no-admin-idor`).
