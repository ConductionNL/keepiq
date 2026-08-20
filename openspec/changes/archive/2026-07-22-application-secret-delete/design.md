# Design — application-secret-delete

## Context

OpenRegister (`credential-doriath-leaf`, cross-repo) needs the full in-process application-vault seam: a read-by-name for `DoriathCredentialStore::get()` and an idempotent per-secret delete for `CredentialStore::delete(uuid)`. Doriath's existing paths verified against HEAD (`origin/development`, e3073313):

| Path | Scope | Behavior on missing/foreign id |
|------|-------|-------------------------------|
| `SecretService::delete($id, $userId)` | `ownerType === 'user'` via `loadOwned()` | `NotFoundException` / `ForbiddenException` |
| `SecretService::get($id, $userId)` | `ownerType === 'user'` via `loadOwned()` | `NotFoundException` / `ForbiddenException` |
| `ApplicationService` admin cascade | whole application | n/a (deletes everything) |
| Machine HTTP `byName` / `show` | bearer token, own vault | 404 (no oracle); `byName` 409 on ambiguity |
| Machine HTTP DELETE | — | **no DELETE route exists, by design** |

`createByApplication` / `updateByApplication` (lib/Service/SecretService.php) establish the application-vault service conventions both new methods mirror: own-vault scoping on `ownerType === 'application' && ownerId === $applicationId`, cross-vault indistinguishable from nonexistent (`updateByApplication` comments this explicitly: "no existence oracle"), audit via `AuditEvent::forApplication`.

## Decisions

### D1 — In-process only; the HTTP no-DELETE stance is untouched

The `ApplicationSecretsController` docblock reasons that a compromised 5-minute bearer token must not be able to *destroy* credentials (overwrites are recoverable-by-visibility via `updatedAt` + audit; destruction is not). That reasoning binds the **bearer-token surface**, not same-instance PHP: an in-process caller that can resolve `SecretService` from DI could already call `SecretMapper::delete` directly. Providing a governed service method (scoping + cascade + audit) is strictly better than forcing consumers to improvise against the mapper. Therefore: service method only; no route, no controller change, `testNoDeleteHandlerExists` stays green.

### D2 — Idempotent silent no-op (deliberate divergence from `updateByApplication`)

`updateByApplication` throws `NotFoundException` on missing/cross-vault ids because a failed write-back must be loud. Delete inverts this: OpenRegister's `CredentialStore::delete` contract is "make it gone" — called during credential-object cleanup, possibly retried, possibly after the secret is already gone. So:

- nonexistent id → return, nothing happens;
- id owned by another vault (another application **or a user**) → return, nothing happens;
- the two cases are byte-for-byte indistinguishable to the caller (void return, no exception, no log/audit difference) — **no existence oracle** across vaults, same property the update path enforces, expressed as silence instead of a thrown 404-equivalent.

Signature: `deleteByApplication(string $secretId, string $applicationId): void`. An empty `$applicationId` can match no vault and therefore falls out as a no-op naturally; no special-case throw is required (unlike `createByApplication`, which must reject it because it *writes*).

### D3 — Audit event: reuse `SECRET_DELETED` with the application actor (no new event type)

The task brief suggested an `APPLICATION_SECRET_DELETED` counterpart to `APPLICATION_SECRET_RETRIEVED`. Verified against HEAD, the consistent choice is the existing generic type with an application actor:

- `createByApplication` dispatches `AuditEventTypes::SECRET_CREATED` and `updateByApplication` dispatches `SECRET_UPDATED` — both via `AuditEvent::forApplication`. The write path already decided that machine/app-vault mutations reuse the generic `secret.*` lifecycle types and let the **actor** (user vs application, a first-class column per the audit-trail spec) carry the distinction.
- `APPLICATION_SECRET_RETRIEVED` is the outlier only because `SECRET_READ` has deliberately narrow semantics ("individual encrypted-blob fetch" by a user, audit-trail §3.1) that machine envelope retrieval doesn't share. `SECRET_DELETED` has no such narrowing — it is generic deletion.
- `secret.deleted` is already in the `AuditEventTypes::whitelist()` map (empty metadata) and already enumerated in the secret-audit-trail spec's normative operation list. A new type would require touching `AuditEventTypes`, the whitelist, AND a secret-audit-trail spec delta — pure cost, no information gain (the admin audit view already filters by actor).

So: `dispatchAudit(AuditEvent::forApplication(actorId: $applicationId, eventType: AuditEventTypes::SECRET_DELETED, objectType: 'secret', objectId: $secretId, objectName: $secret->getName()))` — dispatched **exactly once, after the row is actually removed**, never on a no-op (a fabricated deletion entry would corrupt the trail and could itself become an existence side-channel through the admin audit view).

### D4 — Cascade parity with the user-scoped delete

`delete($id, $userId)` cascades to link shares, secret requests, user shares, group shares, and delegations before removing the row. Today an application-vault secret cannot acquire any of those rows (every sharing creation path is `ownerType === 'user'` scoped — verified `ShareService` / `loadOwned`). `deleteByApplication` reuses the same optional-dependency cascade block anyway: it is a structural no-op now, it keeps the "a secret delete leaves no orphan sharing-graph rows" invariant true unconditionally, and it costs nothing.

### D5 — Trust model

The `applicationId` argument is an *assertion by a trusted same-instance caller*, not an authenticated principal. OpenRegister passes the application id of the vault backing the credential object being read or deleted; Doriath scopes the operation to that vault and cannot be tricked into acting outside it. This is identical in kind to how `createByApplication` trusts the controller-supplied `$applicationId` after JwtAuthMiddleware — here the "middleware" is the instance boundary itself. No new authentication mechanism is introduced or needed.

### D6 — In-process read: `findByName` semantics, null-not-throw, ambiguity never guessed

`getByNameForApplication(string $name, string $applicationId): ?Secret` mirrors the machine HTTP `byName` resolution, verified at HEAD: `ApplicationSecretsController::byName` calls `SecretMapper::findByName(ownerType: 'application', ownerId: ..., name: ..., folderId: ...)` — an exact, case-sensitive, **owner-keyed** query ("keyed by owner so it can never reach another vault", mapper docblock) whose `folderId = null` form matches the whole vault. The in-process method takes no folder parameter (vault-wide, `folderId = null`): OpenRegister names secrets by credential UUID, so the name *is* the address and folder scoping adds nothing.

Outcome mapping — HTTP status → in-process value:

- **0 matches → `null`** (HTTP: 404). Because the query is owner-keyed, a name that exists only in another vault produces the same empty result set — cross-vault and nonexistent are *structurally* indistinguishable, no existence oracle, the same convention D2 gives the delete.
- **1 match → the `Secret` entity**, ciphertext fields intact — no decryption anywhere in the path; the server-side zero-knowledge stance (ADR-003) is untouched. Returning the entity (not an envelope) is correct in-process: the envelope exists to make HTTP responses self-describing for external consumers; a DI caller gets the domain object, as every other service method returns.
- **>1 matches → `null` + `logger->warning`** (HTTP: 409 with candidates, "never silently picks one" — `SecretMapper::findByName` docblock: "the caller decides the ambiguity policy"). The in-process policy is the same *never-guess* rule; `null` rather than an exception because the OR `get()` contract treats "not resolvable" uniformly, and the warning gives operators the signal the 409 body gives machine consumers. To the caller, ambiguity is indistinguishable from absence — deliberately, so the return value leaks nothing about vault contents.

Nullable return (not `NotFoundException` like `updateByApplication`): reads are resolution queries, not mutations — "absent" is a normal answer for `get()`, not a failure, and `?Secret` matches the OR-side `CredentialStore::get()` shape.

### D7 — Read audit: parity with the machine read path (`APPLICATION_SECRET_RETRIEVED`)

The machine HTTP read path (`envelopeResponse`, verified at HEAD) dispatches `AuditEventTypes::APPLICATION_SECRET_RETRIEVED` via `AuditEvent::forApplication` **on a full read only** — a 304 dispatches nothing, and the 404/409 branches never reach it. In-process reads of an application vault are the same auditable fact (an application's secret left the vault store for a consumer), so they audit the same way:

- On a single-match hit: exactly one `dispatchAudit(AuditEvent::forApplication(actorId: $applicationId, eventType: AuditEventTypes::APPLICATION_SECRET_RETRIEVED, objectType: 'secret', objectId: ..., objectName: ...))`.
- On any `null` outcome (no match, ambiguity): no event — parity with the HTTP path's non-2xx branches, and a fabricated retrieval entry would pollute the trail.

`SECRET_READ` is wrong here for the same reason it is wrong on the HTTP path: it has deliberately narrow user-blob-fetch semantics (audit-trail §3.1). No `AuditEventTypes` change: the constant and its (empty) whitelist entry already exist — symmetric with D3's no-new-constant outcome for the delete.

## Testing

Unit tests (`tests/Unit/Service/`, alongside `SecretServiceMachineWriteTest`), mock `SecretMapper` + `IEventDispatcher`:

1. Own-vault delete: mapper `delete()` called with the loaded entity; audit event dispatched once with application actor and `secret.deleted`.
2. Cross-vault id (application-owned by another app AND user-owned): mapper `delete()` never called, no exception, zero audit dispatches.
3. Nonexistent id / double delete: `findById` throws `DoesNotExistException` → silent return, zero audit dispatches (idempotency).
4. Exactly-once audit: a single real deletion produces exactly one `dispatchTyped` call.
5. Read hit: `findByName` returns one entity → same instance returned with ciphertext fields untouched; exactly one `APPLICATION_SECRET_RETRIEVED` dispatch with application actor.
6. Read miss: `findByName` returns `[]` → `null`, zero dispatches (covers nonexistent and cross-vault alike — the owner-keyed query makes them the same case).
7. Read ambiguity: `findByName` returns two entities → `null`, one `logger->warning`, zero audit dispatches, neither candidate returned.

No integration/Newman changes: the machine API is untouched (asserted by the existing `testNoDeleteHandlerExists`). No UI, hence `@e2e exclude` on all scenarios (gate-19).

## Risks

- **Contract drift with OpenRegister**: the OR-side `credential-doriath-leaf` change must consume exactly these signatures and semantics. Mitigation: both contracts (read: `?Secret`, no oracle, never-guess ambiguity; delete: void, idempotent, no oracle; both own-vault) are stated in the spec delta requirement text, which OR's change references; any future variant (loud-failure delete, folder-scoped read) would be a new method, not a mutation of these.
- **Temptation to expose over HTTP later**: the spec delta states the non-goal normatively ("MUST NOT be exposed on any HTTP route") for both methods, so a future route addition requires a deliberate spec change against the recorded rationale.
- **Name-collision blind spot**: the read treats ambiguity as absence. If a consumer ever writes same-named secrets into its vault, `get()` silently degrades to `null`. The warning log is the detection mechanism; OpenRegister's UUID-as-name convention makes the case theoretical.
