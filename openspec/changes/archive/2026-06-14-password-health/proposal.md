## Why

`docs/FEATURES.md` promises at **V1**: "Flag weak, reused, or old passwords" and a "Color-coded strength badge" in the secrets list. Nothing is specced or built. Health reporting is table stakes in every competitor Doriath measures itself against (Bitwarden's Vault Health Reports, 1Password's Watchtower, the Nextcloud Passwords app's security status) — a vault that cannot tell its owner "these 12 passwords are weak, these 4 are reused, this one has appeared in a breach" leaves users storing bad credentials confidently.

Doriath's always-E2E architecture (ADR-003, encryption-suites spec) makes this feature impossible to bolt on server-side — and that constraint is the design: the server never sees plaintext, so it can never score a password, compare two values for reuse, or hash a value for a breach lookup. **All health analysis must run in the browser against the unlocked vault**, and no score, hash, or derived signal that says anything about a secret's value may ever be persisted or transmitted — a stored strength score is itself a crackability oracle.

Reuse detection is the EXPECTED-GAP the feature re-evaluation called out as needing its own design (cross-vault comparison of decrypted values); breach checking lands here as the privacy-preserving HIBP k-anonymity range protocol, strictly opt-in and admin-disableable for air-gapped municipal deployments.

## What Changes

- Implement a **client-side health engine** (`src/health/`): runs only while the vault is unlocked, analyses decrypted key values of password-bearing secrets in browser memory (web worker for large vaults), and produces per-secret findings plus a vault-level summary — nothing leaves the page
- Implement **strength scoring**: zxcvbn score (0–4) per password-bearing secret, reusing the zxcvbn dependency already shipped for the master-password meter
- Implement **color-coded strength badges** in the secrets list and detail view, computed per session in memory — absent while the vault is locked, never rendered from stored data
- Implement **reuse detection**: in-memory comparison across the unlocked vault (SHA-256 digests of decrypted values in a transient map, discarded on lock), flagging every secret whose value is shared with at least one other secret
- Implement **password age**: a new server-side `key_updated_at` column maintained by the server whenever a secret's encrypted `key` ciphertext changes (no decryption needed — ciphertext-change detection), so renaming or moving a secret does not reset its age; secrets older than a user-configurable threshold (default 365 days) are flagged stale
- Implement a **vault health report view**: overall health score, finding lists (weak / reused / old / breached / possibly-compromised-by-suite-migration), each deep-linking to the secret; a summary card on the Doriath dashboard
- Implement **opt-in breach checking via HIBP k-anonymity**: SHA-1 the value client-side, send only the first 5 hash characters through a thin server-side proxy to the Have I Been Pwned range API, compare the returned suffix list locally — the full hash and the value never leave the browser; per-user opt-in, master admin toggle (default off) for instances that must not call external services
- Guarantee **no persistence**: scores, digests, reuse maps, and breach results live in memory only and are discarded on vault lock; the only schema change is `key_updated_at`, which describes ciphertext age, not content

## Capabilities

### New Capabilities
- `password-health`: Client-side vault health analysis under the E2E constraint — zxcvbn strength scoring with list badges, cross-vault reuse detection, ciphertext-age staleness flagging, a vault health report with dashboard summary, and opt-in HIBP k-anonymity breach checking through a prefix-only proxy; no health signal is ever persisted or transmitted

### Modified Capabilities
_(none in delta form — the secrets list/detail gain a purely client-computed badge; the dashboard gains a card within its existing Vault Summary Cards pattern; the `key_updated_at` column is additive metadata maintained transparently by the existing update path)_

## Impact

- **Database**: One migration — `key_updated_at` (datetime) on `doriath_secrets`, backfilled from `updated_at`, set by SecretService whenever the encrypted `key` field changes
- **Backend**: `key_updated_at` maintenance in SecretService; new `BreachProxyController` (`GET /api/v1/breach-check/range/{prefix}` — validates a 5-hex-char prefix, forwards to the HIBP range API, returns the suffix list verbatim, short server-side cache per prefix); `breach_check_enabled` admin setting (default off)
- **Frontend**: New `src/health/` modules (engine, scoring, reuse, age, hibp client) + web worker; `useHealthStore` (Pinia, memory-only state wired to vault lock); strength badge component in list/detail; `HealthReportView.vue`; dashboard health card; user-settings entries (staleness threshold, breach-check opt-in)
- **API**: One new endpoint (the breach range proxy). No endpoint ever receives a score, digest, full hash, or plaintext
- **Dependencies**: Depends on `implement-encryption-suites` (archived — unlocked-session CryptoKey), `implement-secrets` / `implement-secrets-write-ui` (vault data + list/detail UI), `implement-dashboard-settings` (dashboard card slot, user-settings dialog). zxcvbn is already a dependency (master-password meter); no new npm packages
- **Security**: The health engine widens no attack surface — it reads what the unlocked browser can already read. The proxy learns only 5 hash characters (each prefix matches ~800 leaked passwords plus the entire space of unleaked ones); instances can disable it entirely. Badges/report render only post-unlock, so a shoulder-surfer at the lock screen learns nothing
- **Privacy**: Breach checking is double-gated (admin enable + user opt-in). The proxy's prefix cache contains no user association
- **Cross-app**: External call to `api.pwnedpasswords.com` from the server only (never the browser, keeping CSP closed), only when both gates are on
