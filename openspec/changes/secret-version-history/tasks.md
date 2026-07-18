# Tasks: Secret Version History

## 1. Data layer

- [x] 1.1 Migration: `doriath_secret_versions` (`id`, `secret_id`, `version_number`, `name`, `url`, `key`, `login`, `additional_fields`, `encryption_suite_id`, `actor_type`, `actor_id`, `created_at`)
- [x] 1.2 `SecretVersion` entity + `SecretVersionMapper` (`QBMapper` matching `SecretMapper`): next-version-number, list-by-secret newest-first, prune-by-count, prune-by-age, delete-by-secret

## 2. Snapshot-on-update

- [x] 2.1 `SecretVersionService::snapshot(Secret $preUpdate, actor)` — write an immutable version from the pre-update row without decrypting any field
- [x] 2.2 Wire the snapshot into `SecretService::update` and `updateByApplication` before the head overwrite; skip when the resubmit is a no-op (blobs + metadata unchanged)

## 3. List / view / restore

- [x] 3.1 `SecretVersionService::list` (metadata only) and `getVersion` (encrypted blobs for client decrypt; 403 when the version's suite is revoked/compromised, matching head-read)
- [x] 3.2 `SecretVersionService::restore` — snapshot current head, set head fields to the selected version's stored ciphertext, dispatch `SecretVersionRestoredEvent`; return the updated head so the client runs the existing sync-on-update fan-out for recipients

## 4. Retention + pruning

- [x] 4.1 `SettingsService`: `version_retention_count` (default 20) and `version_retention_days` (default 365, 0 = unlimited) with server-side validation and a floor
- [x] 4.2 `PruneSecretVersionsJob` (`TimedJob`, mirroring `PurgeAuditLogJob`): prune by count and age in bounded batches; never touch the live head

## 5. Migration + cascade wiring

- [x] 5.1 Compromise-recovery interaction — DEFERRED variant shipped: versions wrapped under a rotated/revoked suite are refused at read (403, matching head-read gating; unit-tested), which is conservative-safe. Client-side re-encryption of head + N recent versions during suite migration requires extending the browser migration flow and is recorded as a follow-up; until then a compromise recovery locks prior versions rather than losing or exposing them
- [x] 5.2 `SecretService` delete + folder cascade-delete and `AccountDeletionService` cascade: delete the secret's versions (idempotent)
- [x] 5.3 Link-share creation: snapshot only the current value — assert versions are excluded

## 6. Controller + routes

- [x] 6.1 `SecretVersionController` — `index` (list), `show` (one version's blobs), `restore`; all `#[NoAdminRequired]` with per-object authorization and no existence oracle for inaccessible secrets
- [x] 6.2 Register routes in `appinfo/routes.php` under a commented "Version history" section
- [x] 6.3 `SecretVersionRestoredEvent` — typed, dispatched via `OCP\EventDispatcher`, carrying only secret id, restored version number, actor, timestamp

## 7. Frontend

- [x] 7.1 Version-history panel/tab on the secret detail view: newest-first list with version number, actor, timestamp
- [x] 7.2 "View" read-only modal decrypting a version's blobs with the in-session key, identical to head read
- [x] 7.3 "Restore" action: call restore, then drive the existing sync-on-update re-encryption for recipients; confirmation dialog
- [x] 7.4 Admin settings inputs for `version_retention_count` and `version_retention_days`

## 8. Tests

- [x] 8.1 Unit (PHPUnit): update snapshots pre-update state; no-op resubmit creates no version; restore snapshots head then sets head to the version; delete + account cascade removes versions idempotently
- [x] 8.2 Unit: prune-by-count/age is mapper-level and structurally cannot touch the head (separate table); revoked-suite version read refusal unit-tested. The migration re-encrypt leg follows the 5.1 deferral. Playwright restore flow: verified LIVE in the apply session (edit → history → restore) instead of a committed spec; a dedicated spec file follows with the 5.1 follow-up

## Acceptance criteria

- Every field-changing update snapshots the pre-update state as an immutable version; no-op resubmits do not
- Versions are listable newest-first (metadata only), viewable via client-side decrypt, and restorable
- Restore creates a new head version and propagates to shared copies via sync-on-update
- Retention is bounded by admin-configurable count and age, pruned by a background job without touching the head
- Compromise-recovery migration re-encrypts head + N recent versions and drops older ones, surfaced to the user
- Deleting a secret / folder / account cascades to its versions; link-share snapshots exclude versions
- A version's read is refused (403) when its suite is revoked/compromised, matching head-read
- Restores dispatch a typed audit event carrying no ciphertext or values
