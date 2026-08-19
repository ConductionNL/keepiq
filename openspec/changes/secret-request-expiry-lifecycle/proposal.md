---
kind: code
---

## Why

A secret request's expiry is checked but never acted on, and in practice almost never set.

**It is never set.** `expires_at` has exactly one source: the requester typing into an optional `datetime-local` input that defaults to empty (`src/dialogs/SecretRequestCreateDialog.vue`). The service never defaults it, the machine surface leaves it null when omitted, and the spec only says the requester MAY set one. An optional datetime field with no default is rarely filled, so nearly every request is effectively perpetual.

**It is never acted on.** Expiry is enforced only when someone opens the link: `SecretRequestPolicy::requireOpenByToken()` calls `isExpired()` and answers 408. Nothing sweeps. A request whose expiry passed months ago still sits `pending`, still holds its token row, and — when it created one — still holds an unfilled placeholder Secret that can never be filled. Since the machine surface began auto-creating those placeholders (#120), abandoned requests accumulate empty Secrets in application vaults with nothing to clean them up.

**The status vocabulary cannot express it.** Statuses are `pending`, `fulfilled`, `declined`, `locked`. Expiry is derived at read time and never stored, so there is no way to record that a request lapsed — or to distinguish a lapse from a requester cancelling.

## What Changes

- **A suggested expiry is pre-filled** on the create surface, changeable and clearable by the requester. This is a client-side default, not a policy: no server semantics change, no admin setting, no migration. It stays spec-legal because the requester is still the one setting it — an unexpiring request remains available to anyone who wants one. Without this the sweeper below has almost nothing to act on.
- **A terminal `expired` status** is added. On expiry the token is invalidated and a fresh request's placeholder Secret is deleted, while the request row survives as the record. A re-request's existing Secret and its values are preserved.
- **A background job sweeps lapsed requests** — a `TimedJob` in the shape of `ExpireMachineLeasesJob`. Requests with NO `expires_at` are never touched: they remain open until fulfilled or manually revoked, exactly as Optional Expiry requires.
- **Automatic expiry is attributed to the system** in the audit trail, not to the requester, who took no action.
- **The access check evaluates expiry independently of stored status.** `requireOpenByToken()` already calls `isExpired()`, but nested inside `case SecretRequest::STATUS_PENDING`, making expiry a property of one branch. It is hoisted so it applies to any status. **This is load-bearing, not tidying:** the switch ends in `default: throw ... 'Request is in an unknown state', code: 500`, so adding `expired` without its own arm would answer every expired link with a server error instead of saying it expired. The job runs hourly, so a request that lapsed a minute ago still reads `pending` — the gate must refuse on `expires_at` alone. The job is cleanup; it is never the enforcement mechanism.

## Capabilities

### New Capabilities

None. Expiry is already a documented behaviour of `secret-requests`; this change makes it act and gives it a terminal state.

### Modified Capabilities

- `secret-requests`: two requirements modified.
  - MODIFIED `Optional Expiry` — a suggested expiry MAY be pre-filled provided it can be cleared; a lapsed request MUST be swept to a terminal `expired` rather than only refused on access; a request with no expiry MUST be left alone; automatic expiry MUST be attributed to the system; and the access check MUST evaluate `expires_at` independently of stored status and MUST handle the terminal status explicitly instead of falling through to an unknown-state error.
  - MODIFIED `Revoke Request` — the system MAY revoke on expiry, and an automatic expiry MUST remain distinguishable from a requester's own cancellation so a vanished vault row can always be explained.

## Impact

**Backend**
- `lib/Db/SecretRequest.php` — adds `STATUS_EXPIRED`. `status` is already a string column, so no migration.
- `lib/Service/SecretRequestService.php` — the expire transition (token invalidation, placeholder deletion for a fresh request, preservation for a re-request, system-actor audit event).
- `lib/Service/SecretRequestPolicy.php` — expiry evaluated above the status switch; explicit `expired` arm in the 410 family.
- `lib/BackgroundJob/` — a new `TimedJob`, registered in `appinfo/info.xml` alongside the existing jobs.

**Frontend**
- `src/dialogs/SecretRequestCreateDialog.vue` — the pre-filled, clearable expiry.
- `src/views/SecretRequestFill.vue` — renders the expired state as expiry rather than an unexpected error.

**Not affected**
- No change to the encryption boundary (ADR-003). Expiry acts on server-visible metadata only; nothing is decrypted.
- The machine create surface (`/api/v1/app/secret-requests`) keeps its optional `expiresAt` and needs no change — it benefits from the sweeper automatically.

**Priority** — explicitly BELOW `request-first-secret-requests` for the beta. Getting the concept of a secret request right is what a beta needs; sweeping lapsed ones is hygiene that can follow. This change is therefore safe to defer, and nothing in `request-first-secret-requests` waits on it.

**Relationship to `request-first-secret-requests`** — siblings, not a chain. Neither depends on the other: this change operates on requests as they exist today, and placeholder deletion is already exercised by application-created requests from #120. They do both edit `SecretRequestCreateDialog.vue`, so whichever lands second absorbs a small conflict there. Landing `request-first-secret-requests` first is marginally easier, since it restructures that dialog more heavily and this change then adds one field to it.

**Pre-existing requests** — no backfill. Requests already pending with no expiry stay manual-only, which is what Optional Expiry says. Requests already pending with a lapsed expiry are swept on the job's first run; that is the intended cleanup, and it is worth stating because the first run may transition a batch of long-abandoned requests at once.
