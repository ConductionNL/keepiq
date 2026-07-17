# Tasks: Short-lived credential leases for the machine secret-store API

## 1. Data layer

- [ ] 1.1 Migration: `doriath_machine_leases` (`id`, `application_id`, `secret_id`, `scope`, `granted_at`, `expires_at`, `renewed_count`, `last_renewed_at` nullable, `status` enum `active|expired|revoked`, `revoked_at` nullable, `revoked_by` nullable; indexes on `(application_id, status)`, `secret_id`, `expires_at`) and `doriath_application_lease_policies` (`application_id` PK, `default_ttl_seconds`, `max_ttl_seconds`, `renewable`)
- [ ] 1.2 `MachineLease` + `ApplicationLeasePolicy` entities and `MachineLeaseMapper` + `ApplicationLeasePolicyMapper` (standard `QBMapper` pattern)

## 2. Lease service + policy

- [ ] 2.1 `LeaseService::grantOrReuse(applicationId, secret, requestedTtl)` — reuse a live lease without extending; else create with `ttl = min(requested, policy.max)`; emit `lease.granted`
- [ ] 2.2 `LeaseService::renew(leaseId, applicationId)` — own-application; extend to `min(now+default, granted_at+max)`; refuse past max or non-renewable; emit `lease.renewed`
- [ ] 2.3 `LeaseService::revoke(leaseId, actor)` — admin/owner/self; mark `revoked`, emit `lease.revoked` + rotation trigger
- [ ] 2.4 Extend `SettingsService` with `lease_default_ttl_seconds` (900), `lease_max_ttl_seconds` (86400), `lease_renewable` (true), `lease_revocation_blocks_refetch` (false); resolve effective policy with per-application override

## 3. Fetch + discovery integration

- [ ] 3.1 `ApplicationSecretsController`: grant/reuse a lease on `by-name`/`by-id` fetch, add `Doriath-Lease-Id` / `Doriath-Lease-Expires` headers; leave the `doriath-machine-secret-v1` envelope body unchanged
- [ ] 3.2 When `lease_revocation_blocks_refetch` is on, refuse a fetch whose only lease is revoked until re-granted (403); default off keeps re-fetch available (new lease)
- [ ] 3.3 `DiscoveryController`: add additive `lease` object (`supported`, `defaultTtl`, `maxTtl`, `renewable`) to the discovery document — no envelope/addressing change

## 4. Controllers + routes

- [ ] 4.1 `MachineLeaseController` (bearer-authed, `#[PublicPage]` + `#[AnonRateLimit]`): `renew`, `list` (own application), self-`revoke` under `/api/v1/app/leases/*`; cross-application access returns 404
- [ ] 4.2 Session-authed admin/owner lease-management endpoints (`GET /api/v1/applications/{id}/leases`, `DELETE /api/v1/leases/{leaseId}`) with per-object owner/admin guard
- [ ] 4.3 Register routes under a commented "Machine leases" section in `appinfo/routes.php`; add the new `AnonRateLimit` rows to the `docs/ARCHITECTURE.md` rate-limit table

## 5. Background job + audit

- [ ] 5.1 `ExpireMachineLeasesJob` (`TimedJob`, `setInterval(3600)`, mirrors `ApproveElapsedEmergencyRequests`): transition past-expiry `active` leases to `expired`, emit `lease.expired` + rotation trigger; register in `appinfo/info.xml` `<background-jobs>`
- [ ] 5.2 Add `lease.granted|renewed|revoked|expired` to `AuditEventTypes` with non-sensitive-only whitelists (`leaseId`, `secretId`, `expiresAt`, `ttl`, `renewedCount`); inherit `FORBIDDEN_KEYS`

## 6. Frontend

- [ ] 6.1 Application-detail "active leases" panel (`CnDataTable`): lease id, secret, granted/expires, renewals, status, with a per-lease revoke action
- [ ] 6.2 Admin lease-policy fields in the settings section (default/max TTL, renewable, block-on-revoke)

## 7. Contract + tests

- [ ] 7.1 Extend `tests/integration/machine-secret-api.postman_collection.json` with lease grant (header assert), renew, renew-past-max, revoke + revoke-then-fetch (both policy modes), and discovery lease-field cases
- [ ] 7.2 Unit: grant caps TTL to policy; poll reuses without extending; renew refuses past max / non-renewable; cross-application renew/revoke returns 404
- [ ] 7.3 Unit: `ExpireMachineLeasesJob` transitions only past-expiry leases; `lease.*` audit events carry no secret material

## Acceptance criteria

- A machine fetch returns a policy-bounded lease id + expiry in headers with an unchanged `doriath-machine-secret-v1` envelope body
- Requested TTL is capped at the effective maximum; a repeat poll reuses the lease without extending it
- Renewal advances expiry up to `granted_at + max TTL`, increments the count, and is refused past max or when non-renewable
- Cross-application renew/revoke is indistinguishable from a nonexistent lease (404)
- Admin/owner/self revocation marks the lease revoked and emits `lease.revoked` + a rotation trigger; block-on-revoke refuses re-fetch only when enabled (default off keeps connectors available)
- Discovery advertises the lease policy additively without an envelope or addressing change
- `lease.granted|renewed|revoked|expired` audit events record grant lifetime and carry no key, login, value, or ciphertext
- The lease-expiry job ages out only past-expiry leases; the Newman collection covers grant/renew/revoke/expiry/discovery
- Scope is access-grant lifetime governance for static secrets — no dynamic credential minting, no K8s operator
