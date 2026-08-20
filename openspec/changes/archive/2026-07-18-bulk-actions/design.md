# Design: Bulk actions

## Context

Doriath's vault list renders owned + received secrets (`openspec/specs/secrets/spec.md`
List and Pagination) one row at a time, and every write is single-item: move is
`secret.updateSecret({ folderId })` (`openspec/specs/secrets-write-ui/spec.md:50`), delete
is `SecretService::delete` — an irreversible hard delete with cascade
(`lib/Service/SecretService.php:826`, `:852`; no trash column exists on `Secret`), share is
a per-recipient RSA re-encryption via `ShareService::createShare` /
`createBatchShares` (`lib/Service/ShareService.php:125`, `:227`), and add-to-team-folder is
the membership fan-out from the team-folder-sharing change. That change already established
the chunked, progress-reported, idempotent-upsert client fan-out pattern for
(secrets × recipients) crypto work (team-folder-sharing `design.md`, "Fan-out is
membership × secrets, driven client-side with idempotent server writes"). This change adds
the select-and-report UX and a shared chunked runner around all four actions — no new
table, no new crypto.

## Goals / Non-Goals

**Goals:**
- Multi-select in the vault list: per-row, select-all (current view), shift-range, count +
  clear.
- Four bulk actions — move, delete, share-to-user/group, add-to-team-folder — each chunked,
  progress-reported, with a per-item success/failure report and no silent drops.
- Reuse the team-folder-sharing fan-out + idempotent-upsert pattern for the crypto-heavy
  actions; reuse existing single-item paths for move/delete.
- Treat bulk delete as the irreversible hard delete it is, with an explicit confirmation.

**Non-Goals:**
- A trash / undo for bulk delete (no soft-delete exists; a separate change would add it).
- Bulk editing secret **values** (each is a distinct ciphertext — no meaningful batch).
- Bulk actions from the machine/`doriath://` seam (browser-user feature only).
- Server-side fan-out for shares (impossible under ADR-003 — server can't decrypt).
- New per-secret permission grades (a share is a full copy, same as today).

## Decisions

### Decision: Selection state is client-only, never persisted, cleared on lock

Selection lives in the vault list component / store as a `Set<secretId>`. It is not sent to
the server, not written to `localStorage`/`sessionStorage`, and is cleared when the vault
locks (matching the password-health / TOTP no-persistence discipline). Select-all applies to
the **current filtered/paginated view**, not the entire vault, so the user always acts on a
set they can see; a "select all N matching" escape hatch is out of scope for v1.

### Decision: One shared chunked runner drives all four actions

A single `useBulkRunner` composable processes the selection in fixed-size chunks (default
25), yielding progress `(done, total, currentLabel)` after each item and collecting a
per-item result `{ secretId, status: 'ok' | 'failed' | 'skipped', reason? }`. It is
cancellable (stop after the current chunk) and resumable, and exposes "retry failed" which
re-runs only the `failed` subset. The runner is action-agnostic — each action supplies a
per-item async function:

- **move** → `secret.updateSecret({ folderId })` (metadata only, no crypto).
- **delete** → `SecretService::delete` via its controller (hard delete + cascade).
- **share** → decrypt with the owner's `CryptoKey`, re-encrypt under each recipient's
  certificate (WebCrypto, ADR-003), POST via `createBatchShares`
  (`lib/Service/ShareService.php:227`).
- **add-to-team-folder** → the team-folder-sharing member fan-out for the secrets' folder.

### Decision: Server writes are idempotent; the runner is safe to resume/retry

Move and delete are naturally idempotent (re-moving to the same folder is a no-op; deleting
an already-deleted secret is a not-found the runner records as `skipped`, not a failure).
Share and add-to-team-folder reuse team-folder-sharing's upsert on
`(source_secret_id, target_user_id, team_folder_id)` (team-folder-sharing `design.md`), so a
resumed or retried run never double-shares. This is what makes "no silent partial-failure
drops" achievable: a crash mid-run leaves a well-defined set, and re-running reconciles it.

### Decision: Bulk delete is an explicit, irreversible hard delete

Because there is no trash (`lib/Service/SecretService.php:852` is a hard
`mapper->delete`), the bulk delete action MUST show a confirmation that names the exact
count and states it cannot be undone (typed confirmation for large sets), and MUST surface
the per-item report so the user sees precisely what was removed vs what failed. Each delete
reuses the existing cascade to shares/requests/delegations
(`lib/Service/SecretService.php:830-850`) — no orphan sharing-graph rows.

### Decision: Reuse existing single-item endpoints; add batch move/delete only as an optimisation

The runner works entirely over existing endpoints (`updateSecret`, `delete`,
`createBatchShares`, team-folder member add). A thin, **additive** batch move/delete
endpoint MAY be added to cut round-trips for large selections; if added it MUST be
idempotent and return a per-item result array so the client report is server-truthful.
Shares are **not** collapsed into a single request — each recipient's copy is distinct
ciphertext produced client-side.

### Decision: Only act on secrets the user may act on

Bulk actions are scoped to the user's own secrets (and, for move, received shares only
move within the recipient's own folder tree per `openspec/specs/secrets/spec.md:205`).
Delete and share are owner-only; a selection mixing owned and not-permitted secrets marks
the not-permitted ones `skipped` with a reason rather than failing the whole run. Every
underlying endpoint keeps its existing per-object authorization guard (no new IDOR surface).

### Declarative-vs-imperative decision

Doriath has no OpenRegister; everything is imperative PHP by ADR-001. The bulk runner is a
Vue composable; any batch endpoint is an imperative controller method over the existing
services. No declarative/register layer.

## API / surfaces

- Reuses: `secret.updateSecret`, secret delete controller method, `ShareService::createBatchShares`
  (`lib/Service/ShareService.php:227`), team-folder member-add endpoint (team-folder-sharing).
- Optional additive: `POST /api/v1/secrets/batch-move` `{ secretIds[], folderId }` and
  `POST /api/v1/secrets/batch-delete` `{ secretIds[] }`, both `#[NoAdminRequired]`,
  owner-scoped per item, idempotent, returning `[{ secretId, status, reason? }]`.

## Frontend surfaces (Vue 2 + WebCrypto)

- **Vault list selection**: per-row checkbox, header select-all (current view), shift-click
  range, selection count + clear; state in the list store, cleared on lock.
- **Bulk action bar** (appears with an active selection): Move, Delete, Share, Add to team
  folder.
- **Action dialogs** (each its own `.vue` under `src/dialogs/`, ADR-004 modal isolation):
  folder picker for move (`NcSelect` with `inputLabel`), typed confirmation for delete,
  user/group picker for share, team-folder picker for add-to-team-folder.
- **Progress + report**: a shared progress bar with cancel; a final per-item report table
  (ok / failed+reason / skipped) with a "retry failed" button that re-runs only failures.

## Risks / Trade-offs

- **Irreversible bulk delete** → biggest footgun. Mitigation: named-count typed
  confirmation + per-item report; trash/undo explicitly deferred to a future change.
- **Large share fan-out cost** → O(secrets × recipients) browser RSA ops, same ceiling as
  team-folder-sharing. Mitigation: chunked + cancellable + resumable via idempotent upsert;
  document expected timing.
- **Partial run on crash/close** → some items done, some not. Mitigation: idempotent server
  writes + resume/retry-failed; the report is the source of truth, nothing dropped silently.
- **Select-all-current-view vs whole-vault** → a user may expect select-all to span all
  pages. Mitigation: label it as the current view; whole-vault selection deferred.
- **Mixed-permission selection** → not-permitted items are `skipped` with a reason, not a
  hard failure, so one bad item doesn't abort a legitimate bulk run.

## Decisions made under uncertainty

1. **One shared chunked runner** over per-action bespoke loops — chosen to give uniform
   progress/report/retry semantics; assumes 25-item chunks balance responsiveness and
   round-trips.
2. **Client-orchestrated over existing endpoints, batch move/delete only as an additive
   optimisation** — chosen so the feature ships without new server contracts; a batch
   endpoint can follow if profiling shows round-trip cost matters.
3. **Bulk delete is hard delete with a typed confirmation** — forced by the absence of a
   trash column today; a trash/undo is a separate change, not smuggled in here.
4. **Select-all = current filtered view**, not the whole vault — chosen so users act on a
   visible, comprehensible set; whole-vault selection deferred.
5. **Not-permitted items in a mixed selection are `skipped`, not fatal** — chosen so one
   received-share or foreign secret can't abort a legitimate owned-secret bulk run.
6. **Share + add-to-team-folder reuse team-folder-sharing's idempotent upsert** — chosen so
   resume/retry can never double-share; this is why `depends_on: [team-folder-sharing]`.
7. **No bulk value-edit** — each secret value is distinct ciphertext; a batch value edit has
   no coherent meaning, so it is out of scope.
