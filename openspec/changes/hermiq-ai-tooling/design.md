## Context

Doriath's architecture (ADR-003, encryption-suites) is always-E2E: the server stores RSA-encrypted blobs and can neither decrypt nor verify a master password. That property is what makes an MCP surface here unusual — the interesting question is not "which writes are safe to gate" (decidesk/DocuDesk) but "what can a server-side tool even *say*". The answer is the plaintext metadata layer the app already serves under its own ACL: `Secret::jsonSerializeBlocked()` is literally the metadata-only list shape (used when a suite is revoked and ciphertext must be withheld), `CertificateLifecycleService::inventory()` and `RotationFlagService::openFlags()` are the certificate and rotation read paths, and `DashboardSummaryService::fetchSummary()` aggregates them. The three tools are those reads, re-shaped through an allow-list.

Fleet mechanism (ADR-063): derive CRUD from `x-openregister-mcp`, curate genuine non-CRUD via `#[McpTool]` + `IMcpScannableServices`. Doriath has nothing to derive (placeholder register; vault rows never enter OR — `integration-boundary` capability), so it is curated-only.

## Goals / Non-Goals

**Goals:**
- A metadata-only, read-only surface answering the three real assistant questions (do I have X, what expires, what's overdue)
- A hard, testable "no secret material ever" contract that binds future changes
- Audit attribution for agent reads
- Explicit refusal of every write, with reasons

**Non-Goals:**
- Any tool that returns or derives from a decrypted value (impossible server-side; forbidden to make possible)
- Any write, gated or not (see D3)
- Deriving tools from the register / mirroring vault data into OR
- Cross-user or admin-wide vault views (an admin's principal sees an admin's own vault; compliance aggregates stay in the compliance-reporting UI)

## Decisions

### D1: Allow-list serializer, not entity `jsonSerialize()`
`Secret::jsonSerialize()` includes the ciphertext blobs (`key`, `login`, `additionalFields`) because the client needs them to decrypt. Tools MUST NOT call it. A dedicated serializer emits only the allow-listed keys (`id`, `name`, `url`, `typeId`, `folderId`, `expiresAt`, `keyUpdatedAt`, `possiblyCompromisedAt`, `tombstonedAt`); the unit test asserts result keys ⊆ allow-list and includes a positive control (a fixture with an extra key must fail). Deny-lists rot when a column is added; allow-lists fail closed.

### D2: Tools live in `lib/Mcp/*Tools.php` facades, not directly on the domain services
`SecretService::list()` returns full entities and `CertificateLifecycleService::inventory()` returns rows with admin-only suite data. Rather than annotate those (and risk the scanner exposing a method whose return shape includes material), three thin facades (`EntryMetadataTools`, `ExpiryReportTools`, `RotationStatusTools`) carry the attributes, call the services, and pass results through the allow-list serializer. `DoriathScannableServices` lists exactly these three classes, so the "no `#[McpTool]` outside the list" reflection test has a small, stable target. This mirrors decidesk's `lib/Mcp/*Tools.php` split.

### D3: No write tools — each candidate refused

| Candidate | Why refused |
|-----------|-------------|
| create/update/delete secret | Values are encrypted client-side under the user's suite; the server cannot produce a valid ciphertext, and an agent that could would hold key material |
| `markRotated` / `dismiss` flag | Marking rotated without a client-side re-encryption is a false audit signal; the flag lifecycle is the human's attestation |
| `setExpiry` | Metadata-only and technically possible — refused for now: it changes policy-driven notification behaviour, and no chat workflow asked for it; the exception path is a future change modifying this capability with a gate |
| share / delegate / link / lease / emergency | Every one is a security-consequential grant; several require client-side re-encryption for the recipient's suite |
| import / export | Client-side by design (secret-import/secret-export specs) |

### D4: Reach is `self`; admins get no wider view through MCP
Compliance-level aggregates for admins exist in the compliance-reporting surface with its own authorization. Reproducing them as tools would widen the agent's blast radius (an over-granted agent on an admin session enumerating the whole estate's expiry map). Reach stays `self`; a future admin tool must be its own change.

### D5: Audit as `mcp` actor, counts only
`AuditService` already refuses secret content in entries. Tool invocations log tool name, principal, `mcp` actor, result count — enough for "an agent read my expiry report on Tuesday", nothing more.

## Risks

- **Metadata is still sensitive** (a list of what credentials exist and when they rot). Mitigation: reach `self`, default-deny grants, audit trail, and the tools expose no field the same user cannot already see in the vault UI. Residual risk is the user granting an untrusted agent — Hermiq's governance surface, not Doriath's.
- **OpenRegister absent** — the scannable services alias must be registered only when the OR prefix resolves (`OpenRegisterAutoloader` guard); otherwise inert.
