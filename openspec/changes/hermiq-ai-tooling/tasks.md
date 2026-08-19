## 0. Dependency Note (read first)

Depends on OpenRegister's `AttributeToolScanner`, `McpTool` attribute and `IMcpScannableServices` (origin/development; run in production by DocuDesk). References the sibling `leaf-integrations` change's `integration-boundary` capability (no vault data in OR ⇒ no derived dialect). Consumes `SecretService::list()`, `CertificateLifecycleService::inventory()`, `RotationFlagService::openFlags()`, `AuditService` as-is.

## 1. Allow-list serializer and facades

- [ ] 1.1 Create `lib/Mcp/MetadataAllowList.php`: constant allow-list per result type (entry, certificate row, rotation flag) and a `project(array $row, string $type): array` that emits only allow-listed keys
- [ ] 1.2 Create `lib/Mcp/EntryMetadataTools.php` with `#[McpTool(name: 'listEntries', scope: 'read', readOnlyHint: true)]` calling `SecretService::list()`/`search()` for the session user and projecting through 1.1; optional `folderId`, `typeId`, `query`; no user parameter
- [ ] 1.3 Create `lib/Mcp/ExpiryReportTools.php` with `#[McpTool(name: 'expiryReport', ...)]` over `CertificateLifecycleService::inventory(userId, isAdmin: false)` + secrets with `expiresAt` in window; validate `withinDays` 0..365; mark `expired: true`
- [ ] 1.4 Create `lib/Mcp/RotationStatusTools.php` with `#[McpTool(name: 'rotationStatus', ...)]` over `RotationFlagService::openFlags(userId)` + counts
- [ ] 1.5 Create `lib/Mcp/DoriathScannableServices.php` (SPDX + `@spec` docblock, DocuDesk pattern) returning exactly the three facade classes; register the `IMcpScannableServices::doriath` alias in `Application::register()` guarded by the OR-present check

## 2. Audit

- [ ] 2.1 Each facade records an `AuditService` entry: tool name, principal, actor `mcp`, result count — no names/subjects/values

## 3. Tests

- [ ] 3.1 `tests/unit/Mcp/MetadataAllowListTest.php`: every projected result key ∈ allow-list; positive control — a fixture row with `key`/`login`/`encryptionSuiteId`/an unknown key is stripped and a poisoned allow-list makes the test fail
- [ ] 3.2 `tests/unit/Mcp/ScannableSurfaceTest.php`: reflection over `lib/` — `#[McpTool]` appears only on the three facade methods; `DoriathScannableServices` lists exactly the three classes
- [ ] 3.3 Per-tool tests (`ExpiryReportToolTest` at 90/30/7 thresholds and out-of-range `withinDays`; `RotationStatusToolTest` asserting no flag state changes; `EntryMetadataToolsTest` asserting user-A cannot see user-B entries and locked/no-suite vault returns an empty list)
- [ ] 3.4 Extend the MCP surface probe: catalog is exactly the three `doriath.*` read tools, all `readOnlyHint: true`, `scope: read`; none ends in `.create/.update/.delete`
- [ ] 3.5 Audit test: entry attributed `mcp`/principal, payload count-only

## 4. Docs and gates

- [ ] 4.1 `docs/FEATURES.md`: one line under the security model — MCP tools are metadata-only reads; secret values are never agent-reachable
- [ ] 4.2 `CHANGELOG.md` entry
- [ ] 4.3 `composer check:strict` clean on new files; run hydra gates (spdx, route-auth n/a, semantic-auth, spec-coverage)
- [ ] 4.4 Live: on the dev instance with Hermiq, ask "which certificates expire this month?" and confirm the answer comes from `expiryReport`, the audit entry exists, and no tool result contains a value or ciphertext

## Acceptance criteria

- Exactly three read-only metadata tools are discoverable; no write tool exists; no derived dialect exists.
- The allow-list contract and the no-attribute-outside-facades contract are asserted by tests that demonstrably fail on violation.
- No tool result, in any test or live probe, contains secret material as defined by the spec.
