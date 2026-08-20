# Design: Secret Version History

## Context

`SecretService::update` overwrites a secret's ciphertext in place: `lib/Service/SecretService.php:766-791` sets the new blobs and calls `$this->mapper->update($secret)` at line 791, discarding the prior value. There is no history table. A bad edit, rotation, or sync is unrecoverable.

A prior version is nothing more exotic than the previous ciphertext under the same RSA key the secret already uses (ADR-003) — retaining it is a copy, not new cryptography. Doriath owns its own tables (ADR-001), so history is a first-class Doctrine entity, not an OpenRegister object. The existing sync-on-update fan-out (`openspec/specs/user-sharing/spec.md:76-89`) already knows how to propagate a value change to every recipient copy, so **restore** reuses it rather than inventing a rollback path.

## Goals / Non-Goals

**Goals:**
- Retain the pre-update state of a secret's fields on every change, as immutable versions.
- List / view / restore versions; restore becomes a new head and syncs to shared copies.
- Admin-configurable retention (count and/or age) with automated pruning.
- Define exactly what happens to versions during compromise-recovery suite migration.
- Cascade-delete versions with the secret; exclude versions from link-share snapshots.
- Add no new cryptographic primitive.

**Non-Goals:**
- Per-field diffing/merge or three-way conflict resolution — v1 is whole-secret, linear history.
- Named/tagged/pinned versions.
- Cross-copy reconciliation — a recipient's history is scoped to their own copy (see crypto note).

## Data Model

One own table (ADR-001). The live `Secret` row stays the head; versions are immutable snapshots of superseded state.

### `doriath_secret_versions`

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `secret_id` | FK | No | The `Secret` copy this version belongs to (owner's copy = canonical history) |
| `version_number` | int | No | Monotonic per `secret_id`; head is `max(version_number)+1` conceptually |
| `name` | string | No | Plaintext metadata snapshot (per secrets spec, name is not encrypted) |
| `url` | string | No | Plaintext metadata snapshot, nullable |
| `key` | text | Yes | The superseded encrypted key blob, exactly as it was stored (never decrypted server-side) |
| `login` | text | Yes | Superseded encrypted login blob, nullable |
| `additional_fields` | text | Yes | Superseded encrypted additional-fields blob, nullable |
| `encryption_suite_id` | FK | No | Which suite encrypted this version's blobs — critical for migration |
| `actor_type` | enum | No | `user` or `application` — who made the change that superseded this state |
| `actor_id` | string | No | Actor identifier (scrubbed by account-deletion anonymization) |
| `created_at` | datetime | No | When this state was superseded (the snapshot instant) |

A version row is written from the **pre-update** secret, immediately before the head is overwritten. The head itself is never a version row; "current" is always the live `Secret`.

## Endpoints

All authenticated, `#[NoAdminRequired]`, per-object authorization in the method body (a caller may only touch versions of a secret they own or hold a copy of) — no existence oracle for secrets they cannot access, matching the audit-trail per-secret view posture.

- `GET /api/v1/secrets/{id}/versions` — list version metadata (`version_number`, `name`, `actor`, `created_at`, `encryption_suite_id`), newest first. No blobs.
- `GET /api/v1/secrets/{id}/versions/{versionId}` — return one version's encrypted blobs for client-side decrypt/view, identical in shape to reading the head; refused if the version's suite is `revoked`/`compromised` (same 403 as the head read).
- `POST /api/v1/secrets/{id}/versions/{versionId}/restore` — snapshot the current head as a new version, set the head's fields to the selected version's stored ciphertext (valid because migrated versions carry current-suite ciphertext), then the client runs the existing sync-on-update re-encryption fan-out for recipients. Dispatches `SecretVersionRestoredEvent`.

## Frontend surfaces

- **Version history panel/tab** on the secret detail view (Vue 2 + Pinia): a newest-first list of versions with timestamp and actor; "view" opens a read-only modal that decrypts the version's blobs with the in-session `CryptoKey` exactly as the head is decrypted; "restore" calls the restore endpoint and then drives the standard sync-on-update fan-out.
- **Admin settings** (per `implement-dashboard-settings` conventions): numeric inputs for `version_retention_count` and `version_retention_days`, server as authoritative validator.

## Declarative-vs-imperative decision

Imperative PHP service + controller over Doriath's own `doriath_secret_versions` table (ADR-001) — no OpenRegister, no declarative object/register/schema seed, no seed data. Snapshot-on-update is a service step in `SecretService::update`; pruning is a `TimedJob`.

## Restore mechanics

Restore is intentionally not a bespoke rollback. The server:
1. snapshots the current head as a new version (so the restore is itself reversible), then
2. copies the selected version's stored blobs onto the head (works directly because versions are kept current-suite via the migration window), then
3. returns the updated head; the browser then performs the ordinary sync-on-update re-encryption for every recipient, unchanged from a normal edit.

This means restore inherits sync-on-update's semantics for free, including unsetting `possibly_compromised_at` on all copies when the value changes (`openspec/specs/user-sharing/spec.md:208`).

## Risks / Trade-offs

- **History grows unbounded without pruning.** → Mitigation: count- and age-based retention with a nightly bounded-batch prune job (mirrors `PurgeAuditLogJob`); defaults keep 20 versions / 365 days.
- **Migration cost vs. history completeness.** Re-encrypting every historical version in the browser during compromise recovery could be slow for large vaults. → Mitigation: migrate head + N most-recent versions (default 5), drop older — accepted as an honest limitation (old versions are unreadable under the compromised suite regardless).
- **Recipient history is partial.** A recipient sees only their own copy's versions, not the owner's full edit history. → Mitigation: documented crypto note; the canonical history lives with the owner's copy.
- **Link-share snapshot confusion.** A link share is already a frozen point-in-time snapshot; including versions would leak history to anonymous visitors. → Mitigation: versions are explicitly excluded from link-share snapshots.

## Decisions made under uncertainty

- **Whole-secret linear history, not per-field diff.** Simpler to reason about and to restore; matches Bitwarden's item-level history. *Uncertainty:* teams may later want per-field history or a diff view — deferred, and the whole-secret snapshot is a superset so it can be derived later.
- **Migration window N = head + 5 most recent versions; older versions dropped on migration.** Bounds browser re-encryption cost during compromise recovery. *Uncertainty:* the right N is a cost/retention trade-off; made configurable-adjacent (a constant now, promotable to a setting) and documented as lossy for deep history.
- **Restore = new head via existing sync-on-update, not an in-place pointer swap.** Keeps a single propagation path and makes restore itself reversible. *Uncertainty:* a pointer-swap would avoid re-encryption but would fork the sync model; rejected to avoid a second write path.
- **Default retention 20 versions / 365 days.** Picked to cover realistic rotation cadence without unbounded growth; admin-configurable with a floor. Revisit after usage.
- **Snapshot captures plaintext `name`/`url` too, not only encrypted fields.** Cheap and makes the history list meaningful (a rename is visible) without any decryption. *Uncertainty:* stores a little redundant plaintext metadata per version — judged worthwhile for a usable list.
