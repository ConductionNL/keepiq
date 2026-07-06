---
kind: code
---

## Why

OpenRegister is being refactored to use Doriath as its credential-custody leaf (OpenRegister openspec change `credential-doriath-leaf`, cross-repo). OpenRegister's `CredentialStore` contract needs the **full in-process application-vault seam** — both halves of `DoriathCredentialStore`:

- `get()` must resolve the backing secret **by name** from the application's vault (OpenRegister names secrets by credential UUID, so the name is the stable cross-repo reference).
- `delete(uuid)` must be **idempotent**: when the owning credential object is deleted in OpenRegister, the backing secret must disappear from the application's Doriath vault, and a retry of that cleanup (or a delete of an already-gone credential) must succeed silently.

Doriath cannot serve either half in-process today:

- The machine HTTP surface (`/api/v1/app/secrets*`) **deliberately has no DELETE** — the `ApplicationSecretsController` class docblock states the stance: deletion stays a human/administrative operation, because a compromised 5-minute bearer token being able to destroy credentials is a worse failure mode than being able to overwrite them. That stance is correct and **unchanged by this proposal**.
- `SecretService::delete(string $id, string $userId)` is strictly user-owner scoped (`loadOwned` requires `ownerType === 'user'`) — it can never remove an application-vault secret.
- The only application-vault deletion path is the admin cascade in `ApplicationService` (delete the whole application), which is far too coarse for per-credential lifecycle.
- The only application-vault **read** paths are the machine HTTP API (bearer-token principal, envelope serialization — pointless indirection for a same-instance PHP caller) and the user-scoped `SecretService::get()`, which can never reach an application vault. There is no in-process read-by-name.

What is missing is small and precise: **in-process-only service methods** so a same-instance trusted consumer (OpenRegister, resolving `SecretService` via DI inside the same Nextcloud instance) can read and delete secrets in its own application vault. In-process callers are not bearer-token principals — the threat model that shapes the machine HTTP surface (and forbids machine-API DELETE) does not apply to PHP code already running inside the instance with full DI access.

## What Changes

- Add `SecretService::deleteByApplication(string $secretId, string $applicationId): void`:
  - Scoped strictly to the application's own vault (`ownerType === 'application'` AND `ownerId === $applicationId`), mirroring the own-vault scoping conventions of `createByApplication` / `updateByApplication`.
  - **Idempotent silent no-op** on a nonexistent id or a cross-vault id — the two cases are indistinguishable (no existence oracle), matching OpenRegister's `CredentialStore::delete` contract. Note this deliberately differs from `updateByApplication`, which throws `NotFoundException`: a write-back must fail loudly, a cleanup must be retry-safe.
  - Cascades sharing-graph cleanup exactly as the user-scoped `delete()` does, so no orphan rows can survive (structurally a no-op today — all sharing creation paths are user-owner scoped — but the invariant is kept defensively).
- Dispatch **exactly one audit event on actual deletion**: `AuditEventTypes::SECRET_DELETED` (`secret.deleted`) via `AuditEvent::forApplication`, mirroring how `createByApplication` / `updateByApplication` dispatch `SECRET_CREATED` / `SECRET_UPDATED` with the application as actor. No event on a no-op. No new event type and no whitelist change are needed (see design D3 for why not a new `APPLICATION_SECRET_DELETED` type).
- Add `SecretService::getByNameForApplication(string $name, string $applicationId): ?Secret`:
  - Own-vault-scoped read by exact plaintext name, resolved via `SecretMapper::findByName(ownerType: 'application', ownerId: $applicationId, name: $name)` — the same owner-keyed query the machine HTTP `by-name` path uses (vault-wide; the query is structurally incapable of reaching another vault).
  - Returns the `Secret` **entity with ciphertext fields intact** — no decryption, the zero-knowledge stance (ADR-003) is unchanged; the caller decrypts with its own private key exactly as a machine-API consumer would.
  - Returns **`null`** when the name matches nothing — and a name existing only in another vault is structurally identical to nothing (no existence oracle, same convention as the delete).
  - **Ambiguity is never guessed**: more than one match returns `null` and logs a warning — the in-process equivalent of the machine API's 409-never-pick-one rule. (OpenRegister names secrets by credential UUID, so collisions are not expected in practice.)
- Dispatch **audit parity with the machine read path** on a successful read: `AuditEventTypes::APPLICATION_SECRET_RETRIEVED` via `AuditEvent::forApplication`, exactly as the HTTP `envelopeResponse` does on a full read; no event on a `null` outcome (mirroring the HTTP path, which dispatches nothing on 304/404/409).
- Unit tests: own-vault delete works; cross-vault id no-ops without leaking existence; double-delete is idempotent; delete audit dispatched exactly once on real deletion and never on a no-op; own-vault read returns the entity (ciphertext intact) with one retrieval audit event; missing/foreign name returns `null` with zero events; ambiguous name returns `null` plus a warning, never a guess.

### Non-Goals

- **No HTTP surface.** No route is added to `appinfo/routes.php` and no controller changes. The machine secret-store API keeps its no-DELETE stance (secret-store-api spec, "no delete operation on the machine surface" — untouched); `testNoDeleteHandlerExists` must keep passing.
- No change to the user-scoped `SecretService::delete()` path.
- No change to the application-mgmt admin cascade.
- No change to `AuditEventTypes` (no new constant, no whitelist entry).

## Capabilities

### Modified Capabilities

- `secrets`: adds the "In-Process Application Vault Secret Deletion" and "In-Process Application Vault Secret Read" requirements. The `secrets` spec is the canonical home because it owns the `SecretService` secret lifecycle (Create/Read/Update/Delete Secret requirements); `secret-store-api` owns only the machine **HTTP** contract, and neither operation is part of that surface — the store-api spec's requirements (including no-DELETE) remain true and untouched.

## Impact

- **Database**: none — no schema change, no new queries beyond the existing `SecretMapper::findById` / `findByName` + `delete` patterns.
- **Backend**: `lib/Service/SecretService.php` only (two new public methods).
- **Frontend**: none.
- **API**: none — explicitly no route, no controller change.
- **Cross-repo consumer**: OpenRegister's `credential-doriath-leaf` change (OpenRegister repo openspec) wires its `DoriathCredentialStore::get()` to `getByNameForApplication` and `CredentialStore::delete(uuid)` to `deleteByApplication` when the Doriath backend is selected. The contract it relies on: read returns the entity (ciphertext intact) or `null` with no existence oracle; delete is void, idempotent, no existence oracle; both own-vault scoped by the `applicationId` OpenRegister passes for the credential's owning application. That change lives and is verified in the OpenRegister repo; this change only has to keep the methods' contracts stable.
- **Security**: the in-process trust boundary is DI access to the same Nextcloud instance — a caller that can resolve `SecretService` can already reach the mappers directly, so this adds no new capability to an attacker; it adds *governed* paths (own-vault scoping + audit trail) where ungoverned ones would otherwise be improvised. The read returns ciphertext only — the server-side zero-knowledge stance is unchanged. The audited actor is the application whose vault is read or mutated. No secret material ever enters an audit entry (existing whitelist: `secret.deleted` and `application.secret_retrieved` both carry no metadata).
