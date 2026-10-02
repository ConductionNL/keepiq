## 0. Dependency Note (read first)

Depends on OpenRegister's `AttributeToolScanner`, `McpTool` attribute and `IMcpScannableServices` (origin/development; run in production by DocuDesk). References the sibling `leaf-integrations` change's `integration-boundary` capability (no vault data in OR ⇒ no derived dialect). Consumes `SecretService::list()`, `CertificateLifecycleService::inventory()`, `RotationFlagService::openFlags()`, `AuditService` as-is.

## 1. Allow-list serializer and facades

- [x] 1.1 Create `lib/Mcp/MetadataAllowList.php`: constant allow-list per result type (entry, certificate row, rotation flag) and a `project(array $row, string $type): array` that emits only allow-listed keys Done (`MetadataAllowList::project(row:, type:)`; types entry, certificate, expiringSecret, rotationFlag).
- [x] 1.2 Create `lib/Mcp/EntryMetadataTools.php` with `#[McpTool(name: 'listEntries', scope: 'read', readOnlyHint: true)]` calling `SecretService::list()`/`search()` for the session user and projecting through 1.1; optional `folderId`, `typeId`, `query`; no user parameter Done; no active suite answers an empty list.
- [x] 1.3 Create `lib/Mcp/ExpiryReportTools.php` with `#[McpTool(name: 'expiryReport', ...)]` over `CertificateLifecycleService::inventory(userId, isAdmin: false)` + secrets with `expiresAt` in window; validate `withinDays` 0..365; mark `expired: true` Done; `withinDays` outside 0..365 is an InvalidArgumentException before anything is read.
- [x] 1.4 Create `lib/Mcp/RotationStatusTools.php` with `#[McpTool(name: 'rotationStatus', ...)]` over `RotationFlagService::openFlags(userId)` + counts Done; counts open, overdue (`policy_expiry`) and compromised (`suite_compromise`).
- [x] 1.5 Create `lib/Mcp/KeepiqScannableServices.php` (SPDX + `@spec` docblock, DocuDesk pattern) returning exactly the three facade classes; register the `IMcpScannableServices::keepiq` alias in `Application::register()` guarded by the OR-present check Done; the alias is registered by `lib/AppInfo/McpRegistrar.php` only when `OpenRegisterAutoloader::register()` answers true.

## 2. Audit

- [x] 2.1 Each facade records an `AuditService` entry: tool name, principal, actor `mcp`, result count — no names/subjects/values Done through `McpToolContext::audit()`: new actor `mcp` (`AuditEvent::forMcp`) and event `mcp.tool_invoked` whitelisted to `tool` and `resultCount`.

## 3. Tests

- [x] 3.1 `tests/unit/Mcp/MetadataAllowListTest.php`: every projected result key ∈ allow-list; positive control — a fixture row with `key`/`login`/`encryptionSuiteId`/an unknown key is stripped and a poisoned allow-list makes the test fail Done in `tests/Unit/Mcp/McpSurfaceTest.php::testAllowListStripsSecretMaterial`.
- [x] 3.2 `tests/unit/Mcp/ScannableSurfaceTest.php`: reflection over `lib/` — `#[McpTool]` appears only on the three facade methods; `KeepiqScannableServices` lists exactly the three classes Done: `testScannableSurfaceIsExactlyThreeReadTools`, `testAliasOnlyWithOpenRegister`.
- [x] 3.3 Per-tool tests (`ExpiryReportToolTest` at 90/30/7 thresholds and out-of-range `withinDays`; `RotationStatusToolTest` asserting no flag state changes; `EntryMetadataToolsTest` asserting user-A cannot see user-B entries and locked/no-suite vault returns an empty list) Done: `testListEntriesIsMetadataOfTheSessionUserOnly`, `testNoSuiteIsAnEmptyList`, `testExpiryReportThresholds`, `testExpiryWindowBounds`, `testRotationStatusChangesNothing`.
- [ ] 3.4 (OWED: there is no `tests/integration/Mcp` probe in this repo; the catalog shape is asserted by `testScannableSurfaceIsExactlyThreeReadTools`, and the live OpenRegister scanner probe needs an instance with OpenRegister) Extend the MCP surface probe: catalog is exactly the three `keepiq.*` read tools, all `readOnlyHint: true`, `scope: read`; none ends in `.create/.update/.delete`
- [x] 3.5 Audit test: entry attributed `mcp`/principal, payload count-only Done: `testCallsAreAuditedAsAgentReads` (through the real AuditService).

## 4. Docs and gates

- [x] 4.1 `docs/FEATURES.md`: one line under the security model — MCP tools are metadata-only reads; secret values are never agent-reachable Done.
- [x] 4.2 `CHANGELOG.md` entry Done: `CHANGELOG.md` created (Keep a Changelog, Unreleased, Added).
- [ ] 4.3 (PARTIAL: phpcs and phpstan clean on lib/Mcp and McpRegistrar; check:strict and the hydra gates run by the coordinator) `composer check:strict` clean on new files; run hydra gates (spdx, route-auth n/a, semantic-auth, spec-coverage)
- [ ] 4.4 (LIVE CHECK OWED) Live: on the dev instance with Hermiq, ask "which certificates expire this month?" and confirm the answer comes from `expiryReport`, the audit entry exists, and no tool result contains a value or ciphertext

## Acceptance criteria

- Exactly three read-only metadata tools are discoverable; no write tool exists; no derived dialect exists.
- The allow-list contract and the no-attribute-outside-facades contract are asserted by tests that demonstrably fail on violation.
- No tool result, in any test or live probe, contains secret material as defined by the spec.
