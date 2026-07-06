## 0. Scope Note (read first)

Two new public methods on `lib/Service/SecretService.php` plus unit tests — the full in-process application-vault seam (delete + read-by-name). NO route in `appinfo/routes.php`, NO controller change, NO `AuditEventTypes` change (delete reuses `SECRET_DELETED`, read reuses `APPLICATION_SECRET_RETRIEVED`, both via `AuditEvent::forApplication` — design D3/D7). The cross-repo consumer (OpenRegister `credential-doriath-leaf`: `DoriathCredentialStore::get()` / `delete()`) is out of scope — it lives in the OpenRegister repo and only consumes the method contracts. Verify all conventions against HEAD before coding — mirror `createByApplication` / `updateByApplication` and the `ApplicationSecretsController::byName` resolution.

## 1. Backend — SecretService

- [ ] 1.1 Add `SecretService::deleteByApplication(string $secretId, string $applicationId): void` — load via `SecretMapper::findById`; on `DoesNotExistException | MultipleObjectsReturnedException` return silently; on `ownerType !== 'application'` or `ownerId !== $applicationId` return silently (cross-vault indistinguishable from nonexistent, no existence oracle)
- [ ] 1.2 On a real match, run the same sharing-graph cascade block as the user-scoped `delete()` (linkShareService + optional secretRequestService / shareService / groupShareMapper / secretDelegationMapper) before `mapper->delete($secret)` (design D4)
- [ ] 1.3 After actual deletion only: `logger->info` line (id + applicationId) and `dispatchAudit(AuditEvent::forApplication(actorId: $applicationId, eventType: AuditEventTypes::SECRET_DELETED, objectType: 'secret', objectId: $secretId, objectName: $secret->getName()))` — exactly once, never on a no-op
- [ ] 1.4 Add `SecretService::getByNameForApplication(string $name, string $applicationId): ?Secret` — resolve via `SecretMapper::findByName(ownerType: 'application', ownerId: $applicationId, name: $name)` (vault-wide, no folder param); 0 matches → `null`; >1 matches → `logger->warning` + `null` (never a guess, design D6); 1 match → return the entity, ciphertext fields untouched, no decryption anywhere in the path
- [ ] 1.5 On a single-match read only: `dispatchAudit(AuditEvent::forApplication(actorId: $applicationId, eventType: AuditEventTypes::APPLICATION_SECRET_RETRIEVED, objectType: 'secret', objectId: ..., objectName: ...))` — exactly once, mirroring the HTTP `envelopeResponse` full-read dispatch; nothing on any `null` outcome (design D7)
- [ ] 1.6 Full PHPDoc on both methods mirroring `updateByApplication` (own-vault scoping, idempotency / null-not-throw + never-guess ambiguity documented, `@spec openspec/changes/application-secret-delete/specs/secrets/spec.md`) so gate-16 spec-coverage passes

## 2. Unit Tests (tests/Unit/Service/)

- [ ] 2.1 Own-vault delete: mapper `delete()` called with the loaded entity; one `dispatchTyped` with `secret.deleted`, actor type application, actor id = applicationId
- [ ] 2.2 Cross-vault id owned by another application: mapper `delete()` never called, no exception, zero audit dispatches
- [ ] 2.3 Cross-vault id owned by a user (`ownerType = 'user'`): same silent no-op, zero audit dispatches
- [ ] 2.4 Nonexistent id (`findById` throws `DoesNotExistException`): silent return — and calling twice (double delete) stays a silent no-op both times (idempotency)
- [ ] 2.5 Exactly-once delete audit: a single real deletion produces exactly one dispatch; assert cascade helpers invoked on the real-delete path
- [ ] 2.6 Read hit: `findByName` returns one entity → same instance returned with ciphertext fields (`key`, `login`, `additionalFields`) untouched; exactly one `dispatchTyped` with `application.secret_retrieved`, actor type application, actor id = applicationId
- [ ] 2.7 Read miss: `findByName` returns `[]` → `null`, no exception, zero audit dispatches (one case covers nonexistent and cross-vault — assert the mapper call is owner-keyed with `ownerType: 'application'` + `$applicationId`)
- [ ] 2.8 Read ambiguity: `findByName` returns two entities → `null`, exactly one `logger->warning`, zero audit dispatches, neither candidate returned

## 3. Quality Gates & Non-Goal Guards

- [ ] 3.1 `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan) — fix any pre-existing issues encountered in touched files in the same batch
- [ ] 3.2 Confirm `appinfo/routes.php`, all controllers, and `AuditEventTypes.php` have zero diff; `ApplicationSecretsControllerTest::testNoDeleteHandlerExists` still passes (machine no-DELETE stance unchanged)
- [ ] 3.3 Run hydra gates (spec-coverage, route-reachability, forbidden-patterns) on the diff

## Acceptance Criteria

- `deleteByApplication` removes a secret only from the vault matching `ownerType='application' && ownerId=$applicationId`.
- Nonexistent id and cross-vault id are byte-for-byte indistinguishable silent no-ops: void return, no exception, no log/audit difference.
- Double delete of the same id succeeds silently (idempotent) — matches OpenRegister `CredentialStore::delete`.
- Exactly one `secret.deleted` audit event with the application as actor per actual deletion; zero events on any no-op.
- No orphan sharing-graph rows can survive an application-vault secret deletion.
- `getByNameForApplication` resolves only within the vault matching `ownerType='application' && ownerId=$applicationId` and returns the entity with ciphertext intact — nothing is ever decrypted server-side.
- Nonexistent, cross-vault, and ambiguous names all return `null` indistinguishably to the caller; ambiguity additionally logs a warning and is never resolved by guessing.
- Exactly one `application.secret_retrieved` audit event with the application as actor per successful read — identical in form to the machine HTTP full-read dispatch; zero events on any `null` outcome.
- The machine HTTP surface has no delete route before or after this change; no controller or routes diff exists.
- All new/changed methods carry `@spec` tags; `composer check:strict` and the hydra gates pass.
