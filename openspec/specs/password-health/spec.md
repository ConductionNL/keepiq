# password-health Specification

## Purpose
TBD - created by archiving change password-health. Update Purpose after archive.
## Requirements
### Requirement: Client-Side Health Analysis
The system MUST perform all password health analysis (strength scoring, reuse detection, breach checking) in the browser against the unlocked vault, in a dedicated web worker. Analysis MUST only run while the vault is unlocked (CryptoKey in session per the encryption-suites Session Mechanism requirement). No plaintext value, strength score, value digest, full hash, or breach verdict may be transmitted to the server or persisted in `localStorage`, `sessionStorage`, IndexedDB, or any other storage. When the vault locks (manual lock, session timeout), all health state MUST be discarded and the worker terminated.

#### Scenario: Analysis stays in the browser
@e2e exclude In-memory + wire-shape contract — asserting that no HTTP request carries a plaintext/score/digest/full-hash and that nothing is written to browser storage is a network/persistence assertion, not a DOM flow; covered by vitest (health store persistence-leak guard + hibp prefix-only test + engine no-plaintext-in-findings test).
- **WHEN** the health engine analyses an unlocked vault of 200 secrets
- **THEN** no HTTP request issued by the analysis contains a plaintext value, score, digest, or full hash
- **AND** no health data is written to any browser persistence mechanism

#### Scenario: Lock discards health state
@e2e exclude Memory + worker-lifecycle contract — asserting the findings/scores/reuse-map are dropped from memory and the worker is terminated is not DOM-observable; covered by vitest (health store reset() + lock-hook test asserting worker.terminate and state clear).
- **WHEN** the user locks the vault
- **THEN** all computed findings, scores, and reuse maps MUST be discarded from memory
- **AND** the health worker MUST be terminated

#### Scenario: Locked vault shows no health data
@e2e tests/e2e/workflows/password-health.spec.ts
- **WHEN** the vault is locked
- **THEN** no strength badges or health findings are rendered anywhere
- **AND** the dashboard health card shows an "unlock to analyse" placeholder

### Requirement: Strength Scoring and Badges
The system MUST score each password-bearing secret's decrypted value with zxcvbn (0–4) and render a color-coded strength badge in the secrets list and detail view while the vault is unlocked. Secrets whose value is plausibly machine-generated key material (PEM blocks, long base64/hex blobs, values longer than 72 characters) MUST be excluded from strength scoring — zxcvbn on random key material is noise, not signal. Badges MUST be computed from in-memory session state only; secrets scoring zxcvbn ≤ 2 are flagged weak.

#### Scenario: Weak password badged
@e2e tests/e2e/workflows/password-health.spec.ts
- **WHEN** an unlocked vault contains a login secret with value "Summer2024!"
- **THEN** the secrets list MUST show a weak (low-score) color-coded badge on that secret

#### Scenario: Key material not strength-scored
@e2e exclude Classification-logic contract — asserting a PEM value is excluded from scoring is a pure-function decision, not a DOM flow; covered by vitest (classify isKeyMaterial PEM example + engine "does NOT strength-score key material" test).
- **WHEN** a secret's value is a PEM-encoded private key
- **THEN** the secret MUST NOT receive a strength score or appear among weak findings

#### Scenario: Badge updates after edit
@e2e exclude In-session recompute contract — asserting the score changes after an edit without reload is the engine's incremental re-score, covered by vitest (engine "clears the reuse flag once one of the pair changes" + scoreById getter); the DOM badge binding to scoreById is component-trivial.
- **WHEN** the user replaces a weak password with a generated strong one
- **THEN** the badge MUST update to the new score within the same session without a page reload

### Requirement: Reuse Detection
The system MUST detect byte-identical decrypted values across the user's unlocked vault (owned secrets and received share copies alike, including non-password key material such as duplicated API keys) and flag every secret in a group of two or more as reused. Comparison MUST happen via in-memory SHA-256 digests inside the worker; the digest map MUST never be persisted or transmitted and MUST be discarded with the rest of the health state.

#### Scenario: Identical passwords flagged
@e2e exclude In-memory reuse-map contract — asserting both copies are flagged with the share count is the engine's SHA-256 bucketing, covered by vitest (engine "flags both secrets sharing a value with the share count" + the health store reuse test); the DOM list rendering is component-trivial.
- **WHEN** two secrets in the vault hold the same decrypted value
- **THEN** both secrets MUST be flagged as reused, each finding listing how many secrets share the value

#### Scenario: Fix clears the flag
@e2e exclude In-session recompute contract — asserting the reuse flag clears on both after one changes is the engine's rebuild, covered by vitest (engine "clears the reuse flag once one of the pair changes").
- **WHEN** the user changes one of two secrets sharing a value
- **THEN** the reuse flag MUST be removed from both within the same session

### Requirement: Password Age Tracking
The system MUST track when each secret's encrypted `key` ciphertext last changed in a server-maintained `key_updated_at` field, set whenever the stored `key` blob changes and backfilled from `updated_at` on migration. Renaming a secret, moving it between folders, or editing non-key fields MUST NOT reset `key_updated_at`. Secrets whose key is older than the user's staleness threshold (configurable: 90, 180, or 365 days, or never; default 365) MUST be flagged stale. The field describes ciphertext change only — the server performs no decryption to maintain it.

#### Scenario: Rename does not reset age
@e2e exclude Server-side persistence contract — asserting key_updated_at is unchanged on rename is a DB/service assertion, covered by PHPUnit (SecretServiceTest testRenameDoesNotResetKeyUpdatedAt + testUnchangedKeyDoesNotResetKeyUpdatedAt).
- **WHEN** a user renames a secret without changing its value
- **THEN** `key_updated_at` MUST remain unchanged

#### Scenario: Stale password flagged
@e2e exclude Staleness-logic contract — asserting an over-threshold key is listed stale is the engine's age comparison, covered by vitest (engine "flags a key older than the threshold" + ageInDays/staleCutoffDays helpers).
- **WHEN** a secret's `key_updated_at` is older than the user's staleness threshold
- **THEN** the health report MUST list the secret as stale

### Requirement: Vault Health Report
The system MUST provide a vault health report view showing an overall health score (0–100, derived from weighted finding counts, shown together with the counts) and per-category finding lists: weak, reused, stale, breached (when breach checking is active), and possibly-compromised (secrets carrying the `possibly_compromised_at` flag from suite compromise recovery). Each finding MUST deep-link to the secret's detail view. A summary card on the Doriath dashboard MUST show the score and top-line counts and link to the report.

#### Scenario: Report lists findings with deep links
@e2e tests/e2e/workflows/password-health.spec.ts
- **WHEN** an unlocked user opens the health report on a vault with weak and reused passwords
- **THEN** each category MUST list the affected secrets by name and folder path
- **AND** clicking a finding MUST navigate to that secret's detail view

#### Scenario: Suite-compromise findings included
@e2e exclude Data-dependent server-flag surfacing — requires a secret carrying possibly_compromised_at from a compromise-recovery migration (not in dev seed); the engine's compromised-count is covered by vitest (engine "counts possibly-compromised secrets").
- **WHEN** a secret carries `possibly_compromised_at` after a compromise-recovery migration
- **THEN** the health report MUST list it under possibly-compromised with a rotate-this-value call to action

### Requirement: Opt-In Breach Checking via k-Anonymity
The system MUST support checking password-bearing values against the Have I Been Pwned corpus using the k-anonymity range protocol, double-gated: an instance-wide admin setting (`breach_check_enabled`, default off) AND a per-user opt-in. When either gate is off, no breach UI is shown and no breach traffic occurs. When active: the browser computes SHA-1 of the value, sends ONLY the first 5 hash characters to a Doriath server proxy endpoint, the proxy forwards the prefix to the HIBP range API (with response padding) and returns the suffix list verbatim, and the browser performs the suffix match locally. The full hash and the value MUST never leave the browser; the proxy MUST NOT log prefixes with user association and MAY cache responses per prefix. Upstream failure MUST degrade softly (breach category shows unavailable; other findings unaffected).

#### Scenario: Only the prefix is transmitted
@e2e exclude Wire-shape contract — asserting only the 5-char prefix is transmitted and the suffix match is local is a request-shape assertion, covered by vitest (hibp "sends ONLY the 5-char prefix to the range fetcher" + matchSuffix tests).
- **WHEN** a breach check runs for a secret
- **THEN** the only value-derived data in any HTTP request is the 5-character SHA-1 prefix
- **AND** the suffix comparison happens in the browser

#### Scenario: Breached password flagged
@e2e exclude Requires live HIBP corpus + both gates on (admin gate is default-off, no external calls in CI); the breach flagging is covered by vitest (hibp checkValue "breached"/"clean" + engine breachResults wiring).
- **WHEN** a secret's value appears in the HIBP corpus
- **THEN** the secret MUST be flagged breached in the report and on its detail view, with the corpus occurrence count

#### Scenario: Admin gate off means no traffic
@e2e tests/e2e/workflows/password-health.spec.ts
- **WHEN** `breach_check_enabled` is off
- **THEN** the breach-check opt-in MUST NOT appear in user settings
- **AND** no request to the breach proxy or to HIBP is made by any flow

#### Scenario: Upstream failure degrades softly
@e2e exclude Soft-degrade contract — asserting the breach category shows unavailable while other findings still produce is covered by vitest (hibp "soft-fails to unavailable when the fetcher throws") + PHPUnit (BreachProxyController 503 soft-degrade).
- **WHEN** the HIBP range API is unreachable
- **THEN** the breach category MUST show as unavailable
- **AND** weak, reused, and stale findings MUST still be produced

### Requirement: No Server-Side Health Knowledge
The server MUST hold no health information about any secret: no endpoint accepts scores, digests, hashes (beyond the 5-character range prefix), reuse data, or breach verdicts, and none are derivable from stored data beyond `key_updated_at` (ciphertext age) and the pre-existing `possibly_compromised_at` flag. A database dump MUST reveal nothing about the strength, reuse, or breach status of any secret value.

#### Scenario: No health write surface exists
@e2e exclude Route-enumeration contract — asserting no endpoint accepts strength/digest/reuse/breach data is a static routes.php / controller-surface assertion, not a DOM flow; the only health-related endpoint (the breach proxy) accepts a 5-char prefix only, covered by PHPUnit (BreachProxyController prefix-validation tests).
- **WHEN** the registered routes are enumerated
- **THEN** no endpoint accepts strength, digest, reuse, or breach data from the client

