# mcp-metadata-surface Specification

## Purpose
Keepiq offers AI agents three read-only tools over vault metadata through OpenRegister's MCP endpoint: list entries, report what expires, and show rotation status. No tool can reach a secret value, and every call is audited as an agent read.

## Requirements

### Requirement: No Tool Ever Returns Secret Material
No Keepiq MCP tool SHALL return, embed, reference by value, or provide a path to secret material — defined as: the encrypted blobs (`key`, `login`, `additional_fields`) in any form, any plaintext secret value, encryption-suite key material or `encryptionSuiteId`, private-key blobs, attachment content, share/link/lease/emergency tokens or snapshots, export artifacts, or secret-version bodies. No tool SHALL trigger a decrypt, export, share, link, delegation, lease, import, or emergency-access operation. This holds because the server cannot decrypt (encryption-suites spec) AND because Keepiq MUST NOT build a path that would let an agent-reachable process hold key material. Every tool result MUST be built by an allow-list serializer over plaintext metadata; a field not on the allow-list MUST NOT appear even if the underlying entity gains it later.

#### Scenario: A value request is structurally unanswerable
- **WHEN** an agent asks a Keepiq tool for the password of an entry
- **THEN** no tool exists that returns a value, and `listEntries` returns the entry's metadata only
- **AND** the response contains none of `key`, `login`, `additionalFields`, `encryptionSuiteId`
@e2e exclude allow-list contract with no UI surface; covered by PHPUnit (tests/unit/Mcp/MetadataAllowListTest.php asserting every result key ∈ allow-list, positive control with a poisoned fixture).

#### Scenario: New entity fields do not leak by default
- **WHEN** a new plaintext column is added to `Secret` in a later change
- **THEN** it MUST NOT appear in any tool result until this capability's allow-list is explicitly extended
@e2e exclude static allow-list contract; covered by PHPUnit.

#### Scenario: No side-effecting method carries the attribute
- **WHEN** the codebase is scanned for `#[McpTool]`
- **THEN** it appears only on the read methods named in this capability, and on no method that decrypts, exports, shares, links, leases, rotates, imports, or deletes
@e2e exclude static reflection assertion; covered by PHPUnit (tests/unit/Mcp/ScannableSurfaceTest.php).

### Requirement: Metadata-Only Entry Listing Tool
The system MUST expose `keepiq.listEntries` (`#[McpTool(name: 'listEntries', scope: 'read', readOnlyHint: true)]`) returning, for the invoking user's accessible entries (owned and shared copies, via the same access resolution as `SecretService::list()`), exactly: `id`, `name`, `url`, `typeId`, `folderId`, `expiresAt`, `keyUpdatedAt`, `possiblyCompromisedAt`, `tombstonedAt`. It MAY accept `folderId`, `typeId`, and a name `query` filter. It MUST be scoped to the session user; it MUST NOT accept a user parameter, and MUST return an empty list (not an error) when the user's vault is locked or has no active suite — the vault's metadata is readable server-side regardless, so no unlock is required, but nothing beyond metadata is ever computed.

#### Scenario: "Do I have an entry for the staging database?"
- **WHEN** an agent invokes `listEntries` with `query: "staging"`
- **THEN** matching entries are returned with name/url/type/folder/expiry metadata
- **AND** no value, ciphertext, or suite identifier is present
@e2e exclude agent-runtime invocation, no DOM surface; covered by PHPUnit + the MCP surface probe.

#### Scenario: Another user's vault is invisible
- **WHEN** the tool is invoked by user A
- **THEN** results contain only entries A can access through Keepiq's own authorization (owned, shared-to-A, team folders A belongs to)
- **AND** no parameter allows naming another principal
@e2e exclude access-scope contract; covered by PHPUnit mirroring the SecretService list authorization tests.

### Requirement: Expiry Report Tool
The system MUST expose `keepiq.expiryReport` (`#[McpTool(name: 'expiryReport', scope: 'read', readOnlyHint: true)]`, parameter `withinDays` default 30, max 365) returning the invoking user's certificates (from `CertificateLifecycleService::inventory()` rows: entry `id`/`name`, `subject`, `issuer`, `serial`, `notAfter`, days remaining) and secrets with `expiresAt` inside the window, plus already-expired ones flagged `expired: true`. It MUST NOT include CA/suite private material, PEM bodies, fingerprints of private keys, or the certificate secret's value; `fingerprintSha256` of the public certificate MAY be included. Admin callers see their own principal's view only.

#### Scenario: "Which certificates expire this month?"
- **WHEN** an agent invokes `expiryReport` with `withinDays: 30`
- **THEN** every certificate entry of the caller whose `notAfter` falls within 30 days is listed with subject, issuer, serial, `notAfter`, and days remaining
- **AND** the response contains no PEM, no private key, no secret value
@e2e exclude agent-runtime invocation; covered by PHPUnit (tests/unit/Mcp/ExpiryReportToolTest.php) with fixtures at the 90/30/7 thresholds.

#### Scenario: Window bounds are enforced
- **WHEN** `withinDays` is above 365 or below 0
- **THEN** the tool returns a validation error and no report

### Requirement: Rotation Status Tool
The system MUST expose `keepiq.rotationStatus` (`#[McpTool(name: 'rotationStatus', scope: 'read', readOnlyHint: true)]`) returning the invoking user's open rotation flags from `RotationFlagService::openFlags()` — entry `id`/`name`, `reason`, `status`, `flaggedAt`, `keyUpdatedAtAtFlag` — and aggregate counts (open, overdue by policy, compromised). It MUST NOT resolve, dismiss, or create flags; rotation remains a client-side re-encryption plus a human `markRotated` in the app.

#### Scenario: "What's overdue for rotation?"
- **WHEN** an agent invokes `rotationStatus`
- **THEN** the caller's open flags are listed with reason and timing metadata, plus counts
- **AND** no flag's state changes
@e2e exclude agent-runtime invocation; covered by PHPUnit (tests/unit/Mcp/RotationStatusToolTest.php).

#### Scenario: Marking rotated is not offered
- **WHEN** an agent enumerates Keepiq's tool surface
- **THEN** no tool marks a flag rotated, dismisses it, or flags a secret

### Requirement: Surface Is Exposed Only Through The Scannable-Services Opt-In
`lib/Mcp/KeepiqScannableServices.php` MUST implement `OCA\OpenRegister\Mcp\IMcpScannableServices`, MUST be registered under the `IMcpScannableServices::keepiq` DI alias, and MUST list exactly the classes carrying the three read tools. Keepiq MUST NOT register an `IMcpToolProvider`, MUST NOT declare `x-openregister-mcp` on any register (nothing to derive; vault rows never enter OR per the `integration-boundary` capability), and MUST NOT expose any write tool. Tool visibility is governed by OpenRegister's default-deny grant whitelist; Keepiq MUST NOT implement a parallel grant system.

The surface MUST exist only when OpenRegister is installed and enabled, because OpenRegister's MCP endpoint is what offers it. Keepiq MUST register the alias as a string-to-string service alias that autoloads nothing at registration time, so that registering it neither requires nor detects OpenRegister. `KeepiqScannableServices` and the `#[McpTool]`-carrying classes MUST be loaded only when OpenRegister's scanner resolves the alias, which can only happen while OpenRegister is running. Keepiq MUST NOT put OpenRegister's autoload prefix on the autoloader to make this work. Without OpenRegister, no Keepiq code path MUST resolve the alias or instantiate a tool class.

#### Scenario: The surface is exactly three read tools
- **WHEN** Hermiq lists the `keepiq.*` tool catalog
- **THEN** it contains exactly `listEntries`, `expiryReport`, `rotationStatus`, each `readOnlyHint: true`, `scope: read`
- **AND** no tool name ends in `.create`, `.update`, `.delete` and no other curated tool exists
@e2e exclude registry-shape contract; covered by the MCP surface probe (tests/integration/Mcp/McpSurfaceTest.php).

#### Scenario: Ungranted agent sees nothing
- **WHEN** an agent without a Keepiq grant lists tools
- **THEN** no `keepiq.*` tool is offered
@e2e exclude registry-shape contract; covered by the MCP surface probe (tests/integration/Mcp/McpSurfaceTest.php).

#### Scenario: Without OpenRegister nothing MCP-related loads
- **GIVEN** an instance where OpenRegister is not installed
- **WHEN** Keepiq registers and boots, and a user works through the vault
- **THEN** `Application::register()` MUST complete with the alias registered, and no `OCA\OpenRegister\Mcp\…` name and no `OCA\Keepiq\Mcp\…` class MUST be autoloaded during the request
@e2e exclude bootstrap wiring — no UI surface; covered by a unit test that registers McpRegistrar against a recording context and autoloader.

#### Scenario: With OpenRegister the tools appear without extra setup
- **GIVEN** an instance where OpenRegister is installed and enabled, and an agent with a Keepiq read grant
- **WHEN** the agent lists tools through OpenRegister's MCP endpoint
- **THEN** the three `keepiq.*` read tools MUST be offered, exactly as before this change
@e2e exclude agent-facing MCP surface — verified live through OpenRegister's MCP endpoint as part of the change's live check, as hermiq-ai-tooling was (#1048).

### Requirement: Invocations Are Audited As Agent Reads
Every tool invocation MUST be recorded through the existing `AuditService` with an `mcp` actor attribution and the invoking principal, distinguishing agent reads from UI reads, and MUST carry no result payload in the audit entry (counts only), consistent with the audit trail's own no-secret-content rule.

#### Scenario: An agent read leaves an attributable, content-free trace
- **WHEN** `expiryReport` is invoked by an agent acting for user A
- **THEN** an audit entry exists attributed `mcp` / A with the tool name and result count
- **AND** the entry contains no entry names, subjects, or values
@e2e exclude audit-contract; covered by PHPUnit (AuditService tests extended).
