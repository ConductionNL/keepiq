## ADDED Requirements

### Requirement: Client-Side Health Analysis
The system MUST perform all password health analysis (strength scoring, reuse detection, breach checking) in the browser against the unlocked vault, in a dedicated web worker. Analysis MUST only run while the vault is unlocked (CryptoKey in session per the encryption-suites Session Mechanism requirement). No plaintext value, strength score, value digest, full hash, or breach verdict may be transmitted to the server or persisted in `localStorage`, `sessionStorage`, IndexedDB, or any other storage. When the vault locks (manual lock, session timeout), all health state MUST be discarded and the worker terminated.

#### Scenario: Analysis stays in the browser
- **WHEN** the health engine analyses an unlocked vault of 200 secrets
- **THEN** no HTTP request issued by the analysis contains a plaintext value, score, digest, or full hash
- **AND** no health data is written to any browser persistence mechanism

#### Scenario: Lock discards health state
- **WHEN** the user locks the vault
- **THEN** all computed findings, scores, and reuse maps MUST be discarded from memory
- **AND** the health worker MUST be terminated

#### Scenario: Locked vault shows no health data
- **WHEN** the vault is locked
- **THEN** no strength badges or health findings are rendered anywhere
- **AND** the dashboard health card shows an "unlock to analyse" placeholder

### Requirement: Strength Scoring and Badges
The system MUST score each password-bearing secret's decrypted value with zxcvbn (0–4) and render a color-coded strength badge in the secrets list and detail view while the vault is unlocked. Secrets whose value is plausibly machine-generated key material (PEM blocks, long base64/hex blobs, values longer than 72 characters) MUST be excluded from strength scoring — zxcvbn on random key material is noise, not signal. Badges MUST be computed from in-memory session state only; secrets scoring zxcvbn ≤ 2 are flagged weak.

#### Scenario: Weak password badged
- **WHEN** an unlocked vault contains a login secret with value "Summer2024!"
- **THEN** the secrets list MUST show a weak (low-score) color-coded badge on that secret

#### Scenario: Key material not strength-scored
- **WHEN** a secret's value is a PEM-encoded private key
- **THEN** the secret MUST NOT receive a strength score or appear among weak findings

#### Scenario: Badge updates after edit
- **WHEN** the user replaces a weak password with a generated strong one
- **THEN** the badge MUST update to the new score within the same session without a page reload

### Requirement: Reuse Detection
The system MUST detect byte-identical decrypted values across the user's unlocked vault (owned secrets and received share copies alike, including non-password key material such as duplicated API keys) and flag every secret in a group of two or more as reused. Comparison MUST happen via in-memory SHA-256 digests inside the worker; the digest map MUST never be persisted or transmitted and MUST be discarded with the rest of the health state.

#### Scenario: Identical passwords flagged
- **WHEN** two secrets in the vault hold the same decrypted value
- **THEN** both secrets MUST be flagged as reused, each finding listing how many secrets share the value

#### Scenario: Fix clears the flag
- **WHEN** the user changes one of two secrets sharing a value
- **THEN** the reuse flag MUST be removed from both within the same session

### Requirement: Password Age Tracking
The system MUST track when each secret's encrypted `key` ciphertext last changed in a server-maintained `key_updated_at` field, set whenever the stored `key` blob changes and backfilled from `updated_at` on migration. Renaming a secret, moving it between folders, or editing non-key fields MUST NOT reset `key_updated_at`. Secrets whose key is older than the user's staleness threshold (configurable: 90, 180, or 365 days, or never; default 365) MUST be flagged stale. The field describes ciphertext change only — the server performs no decryption to maintain it.

#### Scenario: Rename does not reset age
- **WHEN** a user renames a secret without changing its value
- **THEN** `key_updated_at` MUST remain unchanged

#### Scenario: Stale password flagged
- **WHEN** a secret's `key_updated_at` is older than the user's staleness threshold
- **THEN** the health report MUST list the secret as stale

### Requirement: Vault Health Report
The system MUST provide a vault health report view showing an overall health score (0–100, derived from weighted finding counts, shown together with the counts) and per-category finding lists: weak, reused, stale, breached (when breach checking is active), and possibly-compromised (secrets carrying the `possibly_compromised_at` flag from suite compromise recovery). Each finding MUST deep-link to the secret's detail view. A summary card on the Doriath dashboard MUST show the score and top-line counts and link to the report.

#### Scenario: Report lists findings with deep links
- **WHEN** an unlocked user opens the health report on a vault with weak and reused passwords
- **THEN** each category MUST list the affected secrets by name and folder path
- **AND** clicking a finding MUST navigate to that secret's detail view

#### Scenario: Suite-compromise findings included
- **WHEN** a secret carries `possibly_compromised_at` after a compromise-recovery migration
- **THEN** the health report MUST list it under possibly-compromised with a rotate-this-value call to action

### Requirement: Opt-In Breach Checking via k-Anonymity
The system MUST support checking password-bearing values against the Have I Been Pwned corpus using the k-anonymity range protocol, double-gated: an instance-wide admin setting (`breach_check_enabled`, default off) AND a per-user opt-in. When either gate is off, no breach UI is shown and no breach traffic occurs. When active: the browser computes SHA-1 of the value, sends ONLY the first 5 hash characters to a Doriath server proxy endpoint, the proxy forwards the prefix to the HIBP range API (with response padding) and returns the suffix list verbatim, and the browser performs the suffix match locally. The full hash and the value MUST never leave the browser; the proxy MUST NOT log prefixes with user association and MAY cache responses per prefix. Upstream failure MUST degrade softly (breach category shows unavailable; other findings unaffected).

#### Scenario: Only the prefix is transmitted
- **WHEN** a breach check runs for a secret
- **THEN** the only value-derived data in any HTTP request is the 5-character SHA-1 prefix
- **AND** the suffix comparison happens in the browser

#### Scenario: Breached password flagged
- **WHEN** a secret's value appears in the HIBP corpus
- **THEN** the secret MUST be flagged breached in the report and on its detail view, with the corpus occurrence count

#### Scenario: Admin gate off means no traffic
- **WHEN** `breach_check_enabled` is off
- **THEN** the breach-check opt-in MUST NOT appear in user settings
- **AND** no request to the breach proxy or to HIBP is made by any flow

#### Scenario: Upstream failure degrades softly
- **WHEN** the HIBP range API is unreachable
- **THEN** the breach category MUST show as unavailable
- **AND** weak, reused, and stale findings MUST still be produced

### Requirement: No Server-Side Health Knowledge
The server MUST hold no health information about any secret: no endpoint accepts scores, digests, hashes (beyond the 5-character range prefix), reuse data, or breach verdicts, and none are derivable from stored data beyond `key_updated_at` (ciphertext age) and the pre-existing `possibly_compromised_at` flag. A database dump MUST reveal nothing about the strength, reuse, or breach status of any secret value.

#### Scenario: No health write surface exists
- **WHEN** the registered routes are enumerated
- **THEN** no endpoint accepts strength, digest, reuse, or breach data from the client
