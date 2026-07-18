# Design: Team folder sharing

## Context

Doriath shares **individual** secrets today: a share is a per-recipient RSA re-encrypted copy of a `Secret`, linked by a `SecretShare` and kept current by `ShareService::syncUpdate` (`lib/Service/ShareService.php:384`). Group sharing already fans a single share out to every eligible group member with a `group_share_id` provenance link (`GroupShareService::createGroupShare`, `lib/Service/GroupShareService.php:97`; `openspec/specs/user-sharing/spec.md:31`), and group membership changes already drive fan-out via `UserAddedToGroupListener` / `UserRemovedFromGroupListener` → `handleNewGroupMember` (`:251`) / `handleMemberLeave` (`:359`).

Folders, by contrast, are strictly per-owner: the `Folder` entity has one `ownerId` (`lib/Db/Folder.php:135`) and each user's tree is independent (`openspec/specs/secrets/spec.md:205`). There is no shared-folder object. This design adds one — a **TeamFolder** whose membership drives per-secret fan-out — without introducing any new cryptography. The immovable constraint is ADR-003: no shared symmetric key, always per-recipient RSA copies, server never sees plaintext (`openspec/architecture/adr-003-rsa-aes-encryption-architecture.md:82`).

## Goals / Non-Goals

**Goals:**
- Share a whole folder with Nextcloud users **and** groups; secrets inside inherit access automatically.
- Add-to-folder → auto-share to all recipients; remove/delete → auto-revoke folder-derived copies.
- Membership propagation reuses the existing group-share join-approval / leave-revoke pattern.
- Nested subfolders inherit the nearest ancestor team folder's membership.
- One-action admin offboarding: revoke a leaver's inherited access + transfer their owned team secrets via existing delegation mechanics.
- Zero new crypto; zero server-side plaintext exposure (ADR-003 preserved).

**Non-Goals:**
- A shared symmetric folder key (would break write-without-read + per-recipient revocation — ADR-003).
- Co-ownership of the TeamFolder object itself (owner-only management in v1).
- Per-secret read/write permission grades within a folder (a share is a full copy; grade differentiation is Enterprise-tier, out of scope).
- Narrowing a subfolder's membership **below** its ancestor's set (subfolders may only additively widen in v1).
- Machine/`doriath://` consumption of folders (team folders are a browser-user feature).

## Decisions

### Decision: TeamFolder is a membership set over an existing Folder, not a new secret container

A `TeamFolder` row **attaches** shared membership to an existing per-user `Folder` (the owner's folder), rather than being a separate storage location. Secrets keep living in the owner's `Folder` tree exactly as today; the TeamFolder only answers "who does this folder's contents get shared to". Fan-out then reuses the per-secret share path unchanged.

*Rejected alternative:* a distinct "shared folder" storage area that secrets are moved into. Rejected because it would fork the `Folder` model, duplicate CRUD, and complicate the existing `folder_id` on `Secret` (`openspec/specs/secrets/spec.md:63`). Attaching membership to the existing folder keeps one folder tree.

### Decision: Own tables (ADR-001), membership fan-out reuses SecretShare (ADR-003)

New Doctrine entities + `ISchemaWrapper` migrations (ADR-001 — no OpenRegister):

**`doriath_team_folders`**

| Column | Type | Notes |
|--------|------|-------|
| `id` | string (UUID) | PK |
| `folder_id` | string FK | The owner's `Folder` this shares |
| `owner_id` | string | NC user id — sole manager of the folder's sharing |
| `created_at` | datetime | |
| `updated_at` | datetime | |

**`doriath_team_folder_members`**

| Column | Type | Notes |
|--------|------|-------|
| `id` | string (UUID) | PK |
| `team_folder_id` | string FK | → `doriath_team_folders.id` |
| `member_type` | enum `user`\|`group` | |
| `member_id` | string | NC user id or group id |
| `added_by` | string | owner who added the member |
| `created_at` | datetime | |

**Extend the existing share record** with a nullable `team_folder_id` provenance column (parallel to the existing `group_share_id`, `openspec/specs/user-sharing/spec.md:31`). This is the only change to an existing table; a folder-derived `SecretShare` is otherwise identical to any other per-recipient copy, so `ShareService::syncUpdate` and `revokeShare` already handle it.

**No key material is stored on any of these tables.** The wrapped ciphertext lives only in the recipient's `Secret` copy, produced client-side, exactly as for a normal share (ADR-003 `:82`).

### Decision: Fan-out is membership × secrets, driven client-side with idempotent server writes

Sharing a folder, or adding a member, expands to the cartesian set (secrets in folder subtree) × (new recipients). For each pair the owner's browser decrypts the secret with its private `CryptoKey`, re-encrypts under the recipient's public certificate (WebCrypto, ADR-003 `:82`), and POSTs the ciphertext — the **same** operation `ShareService::createShare` already backs. The server upserts on `(source_secret_id, target_user_id, team_folder_id)` so a retried/duplicated request is a no-op, never a double share.

Group members expand statically to individual user shares at fan-out time (same rule as `GroupShareService`, `openspec/specs/user-sharing/spec.md:89`); members without an active EncryptionSuite are skipped silently.

*Rejected alternative:* server-side fan-out. Impossible under ADR-003 — the server cannot decrypt to re-encrypt.

### Decision: Membership propagation mirrors the group-share flow exactly

- **Group member joins** a group that is a TeamFolder member → owner gets an approval notification per affected secret (reuse the pattern at `openspec/specs/user-sharing/spec.md:100`); on approval the fan-out share is created with `team_folder_id` set. New team-folder branches are added to `UserAddedToGroupListener`.
- **Group member leaves** → all their `team_folder_id`-derived shares auto-revoke (pattern at `:119`), added to `UserRemovedFromGroupListener`. Independent direct shares (both `group_share_id` and `team_folder_id` null) stay intact.
- **Owner adds a direct user member** → immediate fan-out, no approval (the owner is the actor).

### Decision: Nested subfolders inherit the nearest ancestor TeamFolder

A secret in a subfolder of a shared folder is shared to the nearest ancestor TeamFolder's recipient set (walk `parent_id` up the `Folder` tree, `lib/Db/Folder.php:133`). A subfolder MAY carry its own TeamFolder that **adds** recipients; the effective recipient set of a secret is the union along its ancestor chain. Narrowing below an ancestor is rejected in v1 (keeps the union monotonic and revocation unambiguous).

### Decision: Offboarding reuses delegation, does not invent transfer

The admin offboarding action, given a leaving user U and a successor S:
1. Revoke every `team_folder_id`-derived `SecretShare` where U is the recipient (reuse `ShareService::revokeShare`, `lib/Service/ShareService.php:310`).
2. For every team secret **owned** by U, create a delegation to S and make it permanent using `DelegationService::createDelegation` + `makePermanent` (`lib/Service/DelegationService.php:132`, `:356`) — the exact permanent-transfer path already specced for suite deletion (`openspec/specs/user-sharing/spec.md:260`).

No bespoke ownership-transfer code; the delegation invariant "delegatee must already hold a share" is satisfied by ensuring S is a member of the relevant team folder first.

### Declarative-vs-imperative decision

Doriath has no OpenRegister — everything is imperative PHP by ADR-001. TeamFolder membership, fan-out, and offboarding are implemented as `TeamFolderService` methods over own Doctrine entities; there is no declarative schema/register layer.

## API endpoints

All `#[NoAdminRequired]`, owner/member-scoped per method (guards satisfy `hydra-gate-no-admin-idor`):

- `GET  /api/v1/team-folders` — folders I own (with membership) + folders shared **to** me.
- `POST /api/v1/team-folders` — share an existing owned folder `{ folderId }` → creates TeamFolder.
- `GET  /api/v1/team-folders/{id}/members` — list members (owner only sees full list, mirroring share-visibility `openspec/specs/user-sharing/spec.md:180`).
- `POST /api/v1/team-folders/{id}/members` — add `{ memberType, memberId }` → triggers fan-out; client streams ciphertext copies.
- `DELETE /api/v1/team-folders/{id}/members/{memberId}` — remove member → cascade-revoke their derived shares.
- `DELETE /api/v1/team-folders/{id}` — unshare folder → cascade-revoke all derived shares; folder itself remains as a private folder.
- `POST /api/v1/team-folders/offboard` — admin action `{ leavingUserId, successorUserId }`.
- Client fan-out uses the existing batch share endpoint backing `ShareService::createBatchShares` (`lib/Service/ShareService.php:227`) to POST re-encrypted copies.

## Frontend surfaces (Vue 2 + WebCrypto)

- **Share-folder dialog** (own `.vue` under `src/modals/`, per `hydra-gate-modal-isolation`): pick users/groups (`NcSelect` with `inputLabel`), confirm, run client fan-out with a progress bar (per-secret × per-recipient WebCrypto, ADR-003 `:89`).
- **Team-folder membership panel** on the folder view: current members, add/remove, "needs re-share" indicators.
- **Fan-out progress UI**: chunked, cancellable, resumable (idempotent server upsert makes resume safe).
- **Offboarding admin action** in Doriath admin settings (`CnSettingsSection` + `CnVersionInfoCard`): pick leaving user + successor, confirm, show revoke+transfer summary.
- Routing via Vue Router hash mode; lock-screen route guard applies (no `CryptoKey` → `/lock`).

## Risks / Trade-offs

- **Large-folder fan-out cost** → O(secrets × recipients) browser RSA ops. Mitigation: chunked client operation with progress + cancel; idempotent upsert so partial runs resume; document expected timing (ADR-003 quotes ~1–2 s for 200 secrets single-recipient — a large team multiplies this).
- **Stale copies after a member's suite rotates** → same limitation as ordinary shares; mitigated by the existing sync/`possibly_compromised_at` machinery (`openspec/specs/user-sharing/spec.md:203`), surfaced as "needs re-share".
- **Union-only subfolder membership** → cannot express "this subfolder is more restricted than its parent". Accepted for v1; recorded as a decision under uncertainty.
- **Owner is a single point of management** → if the owner is unavailable, membership can't change until offboarding/delegation transfers ownership. Mitigated by the offboarding action itself.
- **Partial fan-out on crash** → some recipients shared, some not. Mitigated by idempotent upsert + a reconciliation pass on next folder open that re-derives the expected share set.

## Decisions made under uncertainty

1. **TeamFolder attaches to an existing Folder** rather than being a new container — chosen to avoid forking the folder model; assumes the owner already has the secrets organised in a folder.
2. **Two tables** (`doriath_team_folders` + `doriath_team_folder_members`) rather than a JSON member blob — chosen for queryability (revoke-on-leave needs to find members by `member_id`) and to match the existing normalized `GroupShare` shape.
3. **`team_folder_id` added to the existing share table** (parallel to `group_share_id`) rather than a separate join table — chosen so `syncUpdate`/`revokeShare` need no change and provenance isolation is a single WHERE clause.
4. **Subfolders may only widen membership (union), never narrow** — chosen to keep revocation deterministic; narrowing deferred to a future change.
5. **Owner-only folder management in v1** (no folder co-ownership) — chosen to avoid a second authorization axis; offboarding covers the "owner gone" case via delegation.
6. **Group members expand statically at fan-out time** (not a live group-key) — forced by ADR-003 and consistent with `GroupShareService`.
7. **Offboarding requires a named successor** who is (or is made) a folder member before transfer — chosen because delegation requires the delegatee to already hold a share (`openspec/specs/user-sharing/spec.md:245`).
8. **Offboarding is an admin action** (not owner-only) — assumed HR/admin drives offboarding when the owner may be the leaver; guarded by an admin authorization check in the method body.
