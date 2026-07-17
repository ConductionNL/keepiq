# Design — cxp-transfer

## Context

`cxf-import-export` ships the FIDO Credential Exchange **Format** as a file on Doriath's existing client-side pipelines: import parses + encrypts a CXF JSON document in the browser and commits already-encrypted rows; export decrypts + assembles a CXF JSON document in the browser, gated by an unencrypted-file warning + fresh master-password re-auth because a raw CXF file is plaintext (`openspec/changes/cxf-import-export/design.md:53`). That change explicitly deferred the encrypted transport — the FIDO Credential Exchange **Protocol** (CXP), HPKE — as future work (`openspec/changes/cxf-import-export/proposal.md:28`, design D1 `:26`). This change builds it.

CXP is a *transport wrapper*, not a new format. It carries the **same CXF payload** the sibling change already produces and consumes, sealed with **HPKE (RFC 9180)** so the credentials travel provider-to-provider without ever landing on disk as a plaintext file — the single riskiest artifact of a file-based migration (a forgotten `.cxf` in `~/Downloads`). Hence `depends_on: [cxf-import-export]`: CXP seals what CXF assembles and unseals into what CXF imports.

## Goals / Non-Goals

**Goals:**

- Direct provider-to-provider credential transfer with the CXF payload **HPKE-sealed end to end**, decrypted/assembled **only** in the browser (ADR-003).
- Doriath as **importing** provider (generate keypair, produce CXP request, receive + decrypt sealed CXF, feed the existing CXF import pipeline) and as **exporting** provider (receive CXP request, assemble CXF via the existing export path, seal for the requester, return the sealed envelope).
- **No plaintext file ever written to disk** in either direction — the headline win over file-based CXF.
- Reuse the CXF mapping, import pipeline, export assembly, unmapped-item report, and re-auth gating from `cxf-import-export` unchanged.

**Non-Goals:**

- Native OS / platform credential-provider integration — browser-session flow only in v1.
- A new CXF mapping or a second import/export pipeline — CXP wraps the existing CXF payload.
- Writing a plaintext file — that is the existing file-based CXF path, deliberately not this one.

## CXP message flows

**Doriath as IMPORTING provider (pulling credentials in):**

```
1. Doriath browser generates an ephemeral HPKE recipient keypair (RFC 9180).
2. Doriath builds a CXP request { requesterPublicKey, requestedFormat: CXF, nonce }.
3. Request handed to the exporting provider (browser-session flow — see D3).
4. Exporting provider seals its CXF payload to Doriath's public key → HPKE envelope.
5. Doriath browser HPKE-opens the envelope with the ephemeral private key → CXF JSON (in memory).
6. The CXF JSON feeds the EXISTING cxf-import-export import pipeline:
   mapping preview → folder mapping → duplicate detection → chunked encrypted commit
   → unmapped-item report → summary.  No plaintext file is ever written.
```

**Doriath as EXPORTING provider (handing credentials out):**

```
1. Doriath browser receives a CXP request { requesterPublicKey, requestedFormat: CXF, nonce }.
2. User is gated by the EXISTING fresh master-password re-auth (cxf-import-export D5).
3. Doriath browser assembles the CXF payload via the EXISTING cxf-import-export export path
   (client-side decrypt + assemble; unmapped-item report shown before send).
4. Doriath browser HPKE-seals the assembled CXF payload under requesterPublicKey → envelope.
5. Only the sealed envelope is handed back.  No plaintext file is ever written.
6. Reports to the existing export-event endpoint with mode `cxp`.
```

The ephemeral private key never leaves the browser; the server (and any relay) sees only public keys and HPKE ciphertext.

## Auth / trust model

- **Payload confidentiality** is HPKE: the CXF payload is sealed to the recipient's public key, so only the holder of the matching private key (the importing browser session) can open it. Server-side compromise or a passive relay yields only ciphertext.
- **Export gating** is unchanged from `cxf-import-export` D5: fresh master-password re-auth before Doriath assembles and seals an export, even when the vault is already unlocked (a client-side proof that decrypts the stored private-key blob, discarded immediately).
- **Relay (if needed)** is opaque: any minimal endpoint that shuttles the CXP request and the sealed envelope between providers carries only public keys and HPKE ciphertext — never plaintext, never key material the server could open with.

## Declarative-vs-imperative decision

Imperative, per **ADR-001** (`openspec/architecture/adr-001-own-database-tables.md`): Doriath owns its tables and does not use OpenRegister. CXP import commits through the existing `cxf-import-export` batch path into Doriath's own `doriath_secrets`/`doriath_folders` tables; CXP export reads client-decrypted values from them. HPKE seal/open is pure client-side crypto (ADR-003). No register, schema, or seed data is involved.

## Decisions made under uncertainty

- **CXP spec version targeted.** CXP is newer than CXF and interop is still emerging (Bitwarden is the first third party; 1Password/Dashlane/Proton following). Decision: **target the FIDO CXP draft aligned with the CXF Proposed Standard (Aug 2025) that Bitwarden's shipping implementation interoperates with**, pin the exact draft/version in the code and the discovery/handshake, and isolate all CXP-version specifics in a single transport module so a spec revision touches only that module — the same isolation discipline `cxf-import-export` applied to the format (design D1/uncertainty note). This is recorded as a decision because the version is a moving target.
- **HPKE cipher suite.** Decision: use an RFC 9180 suite matching the shipping ecosystem (interoperable with Bitwarden's CXP) rather than inventing parameters; the exact KEM/KDF/AEAD triple is pinned in the transport module and validated at open time, failing fast on a suite mismatch rather than mis-decrypting.
- **v1 scope = browser-session flow, not native OS provider.** Decision: v1 handles the CXP request/response within a Doriath **browser session** against a cooperating provider. The OS-mediated native credential-provider handoff (as Apple/Bitwarden ship at the platform level) needs platform-specific integration and is deferred. This keeps the crypto + pipeline reuse in scope while leaving the platform surface for later.
- **Relay vs direct.** Decision: prefer a **direct browser-to-provider** handshake where the cooperating provider exposes one; fall back to a **minimal opaque relay** that carries only public keys and sealed bytes. Either way the server never sees plaintext, so the choice is a connectivity detail, not a security boundary — stated plainly rather than over-engineered.
- **Reuse CXF assembly rather than a CXP-specific serializer.** Decision: CXP seals the *identical* CXF payload the file path produces, so the format mapping, unmapped-item report, and E2E guarantees are inherited unchanged; CXP adds only seal/open + handshake. Rejected alternative: a bespoke CXP payload shape — rejected as needless divergence from the standard and from the sibling change.
- **Event mode `cxp`, distinct from `cxf`.** Decision: a CXP export reports mode `cxp` on the existing `SecretExportedEvent` (no secret material), distinguishing the sealed transfer from a plaintext-file `cxf` export in the audit trail while reusing the same event contract.

## Risks / Trade-offs

- **CXP interop still stabilising.** → All version/suite specifics isolated in one transport module; strict open-time validation (suite + version) fails fast rather than mis-decrypting. Interop is verified against a shipping implementation (Bitwarden) as the reference peer.
- **Depends on `cxf-import-export`.** → Explicit `depends_on`; CXP has no payload without the CXF mapping and pipelines. Non-negotiable ordering: the format lands first, then the transport.
- **A cooperating provider is required.** → CXP is inherently two-sided; against a provider that only offers file-based CXF, the user falls back to the existing file path. Documented, not hidden.
- **Ephemeral key handling.** → The recipient private key lives only in the browser session and is discarded after open; a fingerprint/nonce check binds a sealed envelope to the request that produced it, preventing a misdirected-envelope decrypt attempt.

## Migration / Rollout

No data migration. CXP is an additive transport over the existing CXF pipelines; the file-based CXF path is unchanged and remains the fallback for providers that do not speak CXP. `cxf-import-export` must land first (it owns the CXF payload, mapping, import pipeline, and export assembly this change seals and unseals). Ships behind the normal migration UI as an alternative "encrypted direct transfer" option alongside file import/export.
