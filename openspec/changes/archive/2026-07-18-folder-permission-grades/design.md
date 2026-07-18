# Design: Folder permission grades

## Context

`team-folder-sharing` shares a folder by fanning out per-recipient RSA copies of each contained secret (one `ShareTarget` per (secret, recipient) with a `team_folder_id` provenance link) and keeps them current with `ShareService::syncUpdate` (`lib/Service/ShareService.php:384`). That sync path — the only code that re-encrypts a new value for every recipient — gates on `assertOwnerOrDelegate` (`:486`), so **only the owner/delegate can write to the team**. Every folder member is read-only: they hold a copy (`SecretService::update` refuses non-owners via `loadOwned`, `lib/Service/SecretService.php:1086`) and can view it, but cannot rotate the shared value for everyone. `team-folder-sharing` deliberately deferred read/write grades (`openspec/changes/team-folder-sharing/design.md:22`).

This design adds a **grade** to team-folder membership. The immovable constraint is ADR-003: no shared symmetric key, always per-recipient RSA copies, server never sees plaintext (`openspec/architecture/adr-003-rsa-aes-encryption-architecture.md:82`). A `write` grade therefore cannot be "hand the member a folder key" — it must be **authorization to run the existing client-side re-encrypt-for-all fan-out** that owners already run.

## Goals / Non-Goals

**Goals:**
- A folder member (user or group) carries a `read` (default) or `write` grade.
- A `write`-grade member can update a folder secret's value; the update propagates to every recipient as per-recipient RSA copies, re-encrypted **in the writer's browser**.
- Server enforces that only owner/delegate **or** a `write`-grade holder may run the fan-out.
- Owner alone manages membership and grades; grade changes re-encrypt nothing.
- Non-owner writes and grade changes are audited, attributed to the actor.
- Zero new crypto; zero server-side plaintext (ADR-003 preserved).

**Non-Goals:**
- A `manage`/co-owner grade — membership management stays owner-only (offboarding/delegation from `team-folder-sharing` covers "owner gone").
- Per-field or per-secret grade overrides inside a folder — grade is per membership.
- Narrowing a subfolder's grade below an ancestor's — grade is monotone-max along the ancestor chain (mirrors `team-folder-sharing`'s union-only membership).
- Changing a `read` member's behavior — it is byte-for-byte what `team-folder-sharing` ships today.

## Decisions

### Decision: `write` is authorization to run the existing fan-out, not a shared key

When a `write`-grade member rotates a folder secret, their browser: (1) decrypts **their own** recipient copy with their in-memory `CryptoKey`; (2) fetches every recipient's public certificate (server-visible, ADR-003 `:82`); (3) re-encrypts the new value under each; (4) PUTs the per-recipient blobs to the **existing** `share#sync` endpoint (`appinfo/routes.php:104`). This is exactly the fan-out an owner already performs (`ShareService::syncUpdate`, ADR-003 `:89`). The only server change is the authorization gate.

*Rejected alternative:* a shared symmetric folder key that write-members hold. Rejected — breaks write-without-read, per-recipient revocation, and the whole zero-knowledge model (ADR-003), the same reason `team-folder-sharing` rejected it.

### Decision: extend `assertOwnerOrDelegate`, add no new write endpoint

`ShareService::syncUpdate` today calls `assertOwnerOrDelegate($source, $userId)` (`lib/Service/ShareService.php:486`). Extend it to `assertOwnerDelegateOrWriteGrant`: if the caller is neither owner nor delegate, resolve the secret's effective grade for the caller via `TeamFolderService::resolveGrade` and accept iff it is `write`; otherwise throw the existing `ForbiddenException`. Because the writer only submits ciphertext for recipients that already hold a copy (the fan-out targets the folder's current recipient set), `syncUpdate`'s per-copy loop is unchanged. No new route, controller, or crypto — the smallest possible authorization delta.

### Decision: own tables (ADR-001) — one column, no new table

Per ADR-001 (own Doctrine entities, no OpenRegister). Add one column to the table `team-folder-sharing` owns:

**`doriath_team_folder_members`** (extend)

| Column | Type | Notes |
|--------|------|-------|
| `grade` | enum `read`\|`write` | New. Default `read`. Set/changed by the folder owner only. |

Nullable-with-default `read` so the migration backfills every existing membership to today's read-only behavior — a pure additive, non-breaking migration. **No key material is stored** — the column is a pure authorization flag; ciphertext lives only in recipient `Secret` copies (ADR-003 `:82`).

### Decision: effective grade is the max along the ancestor chain

`team-folder-sharing` shares a subfolder secret to the **union** of all ancestor team-folder members (`openspec/changes/team-folder-sharing/design.md:77`). Grades extend this monotonically: a member's effective grade for a secret is the **highest** grade any ancestor membership grants them (`write` ⊃ `read`), and a group membership's grade applies to each expanded user. This keeps the union-only invariant: a subfolder may widen membership and/or raise a grade, never narrow or lower below an ancestor. `resolveGrade(secret, userId)` walks `parent_id` (`lib/Db/Folder.php:61`) and takes the max.

### Decision: grade change re-encrypts nothing

A `read`→`write` promotion only unlocks the sync path for that member; a `write`→`read` demotion re-locks it. Neither touches ciphertext — the member already holds their recipient copy either way. Demotion does **not** revoke the copy (that is `removeMember` from `team-folder-sharing`); it only removes the ability to push new values. This keeps grade changes O(1) and side-effect-free apart from the audit event.

### Declarative-vs-imperative decision

Doriath has no OpenRegister — everything is imperative PHP by ADR-001. The grade column, `resolveGrade`, and the extended authorization check are `TeamFolderService`/`ShareService` methods over own Doctrine entities; there is no declarative schema/register layer.

## API endpoints

- `PATCH /api/v1/team-folders/{id}/members/{memberId}` — `{ grade: "read"|"write" }`; owner-only (guard in method body, `hydra-gate-no-admin-idor`); dispatches the grade-changed audit event. **New.**
- `PUT /api/v1/secrets/{secretId}/sync` — **unchanged route** (`appinfo/routes.php:104`); now also accepted from a `write`-grade non-owner. The controller passes the caller uid to `syncUpdate`, which authorizes.
- `GET /api/v1/team-folders/{id}/members` — unchanged, now includes each member's `grade` (owner-only full visibility, as `team-folder-sharing`).

## Frontend surfaces (Vue 2 + WebCrypto)

- **Grade selector** in the team-folder membership panel: per-member `NcSelect` (`inputLabel`) `Read`/`Write`, owner-only, PATCHes the grade.
- **Editable secret form for write members**: the secret edit view, today read-only for a non-owner folder copy, becomes editable when `resolveGrade` returns `write`; on save the client runs the re-encrypt-for-all fan-out (same routine as the owner's sync, ADR-003 `:89`) with a progress indicator and PUTs to `share#sync`.
- **"Editable" badge** on secrets the current user may write, so a member knows a change will propagate to the whole team.
- Routing via Vue Router hash mode; lock-screen guard applies (no `CryptoKey` → `/lock`).

## Risks / Trade-offs

- **A write member's browser holds every recipient's plaintext momentarily** to re-encrypt — unavoidable under per-recipient RSA and identical to what an owner's browser already does (ADR-003 `:89`); the server still never sees it. Accepted; it is the write-without-read model working as designed.
- **Optimistic-lock contention** when a write member and the owner rotate concurrently — `syncUpdate`'s existing `expectedUpdatedAt` check (`lib/Service/ShareService.php:396`) already rejects a stale fan-out; the loser re-fetches and retries. Reused unchanged.
- **Stale copy for a member whose suite rotated** — same limitation as any share; surfaced by the existing `possibly_compromised_at` machinery. Unchanged.
- **Grade demotion does not rekey** — a demoted member still holds the last value they could read (they always could). Demotion stops future propagation, not past knowledge; revoking the copy is `removeMember`. Documented, not a defect.

## Decisions made under uncertainty

1. **`grade` is a column on `doriath_team_folder_members`, not a new table** — chosen for the smallest additive migration; assumes `team-folder-sharing`'s member table lands first (declared `depends_on`).
2. **Default `read`, backfilled on migration** — chosen so the change is non-breaking: every existing membership keeps today's read-only behavior until an owner opts a member up to `write`.
3. **Effective grade = max along the ancestor chain (write ⊃ read)** — chosen to preserve `team-folder-sharing`'s monotone union invariant and keep revocation/authorization deterministic; narrowing deferred.
4. **Reuse the `share#sync` endpoint rather than a new write route** — chosen so `syncUpdate`'s per-recipient loop, optimistic lock, and `possibly_compromised_at` handling need no change; only the authorization guard moves.
5. **Grade change re-encrypts nothing** — chosen because the member already holds their copy; a grade is pure authorization, so promotion/demotion is O(1) and side-effect-free apart from audit.
6. **Owner-only grade management (no `manage` grade in v1)** — chosen to avoid a second authorization axis; "owner unavailable" is already covered by `team-folder-sharing`'s offboarding/delegation.
7. **Non-owner write attributed to the writer in audit** (`AuditEvent::forUser` actor = writer) — chosen so a shared-credential rotation is traceable to the human who did it, not to the folder owner.
