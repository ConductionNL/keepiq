## Context

FEATURES.md V1 promises weak/reused/old flagging and a color-coded strength badge; the re-evaluation additionally flagged cross-vault reuse detection as an expected gap that "requires client-side comparison across decrypted values (E2E constraint), so it needs its own design". ADR-003 / the encryption-suites spec is the hard boundary: the master password, AES-derived key, and decrypted values exist only in browser memory while the vault is unlocked; the server stores and serves ciphertext. Every health computation is therefore a client-side computation, and every health *signal* (a score, a digest, a breach verdict) is derived from plaintext and must be treated with the same never-leaves-the-browser discipline as the plaintext itself.

The vault UI already has the pieces this feature hangs off: the secrets list/detail (implement-secrets-write-ui), the dashboard Vault Summary Cards and user-settings dialog (implement-dashboard-settings), the zxcvbn dependency (master-password meter), and the `possibly_compromised_at` flag set by suite compromise recovery (secrets spec) which the health report surfaces as a fifth finding category.

## Goals / Non-Goals

**Goals:**
- Per-secret zxcvbn score + color-coded badge in list and detail, computed in-session
- Cross-vault reuse detection over decrypted values, fully in browser memory
- Staleness flagging from real key age (`key_updated_at`), not row `updated_at`
- Vault health report view + dashboard summary card, findings deep-linking to secrets
- Opt-in HIBP breach checking via k-anonymity range queries through a prefix-only server proxy
- Hard guarantee: no score, digest, full hash, breach verdict, or plaintext is persisted anywhere or transmitted to any server

**Non-Goals:**
- Server-side or scheduled health scans (impossible under E2E — the server cannot score ciphertext)
- Persisting health history / trends over time (would require storing derived-from-plaintext signals)
- Health scoring of application-owned vaults (the user's browser cannot decrypt them; application consumers own their own hygiene)
- Breach-checking usernames/emails (HIBP account API needs an API key and different privacy reasoning — separate change if ever)
- Automatic password rotation or "fix it for me" flows (rotation belongs to the secret edit + key-generator path that already exists)
- Custom/offline breach corpus upload (Enterprise-tier candidate)

## Decisions

### D1: Which Secrets Are Password-Bearing

Strength, reuse, and breach analysis apply to secrets whose type is `login` plus any type the user marks as password-like? No — type is "a UI hint only" (secrets spec) and inventing per-type semantics contradicts it. Decision: the engine analyses **every secret's `key` value**, but applies zxcvbn scoring and breach checks only where the value is plausibly a human-chosen password (heuristic: length ≤ 72 and not matching obvious key-material shapes — PEM headers, long base64/hex blobs ≥ 64 chars). SSH keys and API tokens are excluded from "weak" findings (zxcvbn on a 40-char random token is meaningless noise) but **included in reuse detection** (the same API key pasted into two secrets is a real finding). The heuristic is a pure function with unit tests and documented examples.

### D2: Client-Side Engine in a Web Worker, Keyed to the Lock Lifecycle

`src/health/engine.js` runs in a dedicated web worker: the main thread posts `{id, name, url, value, keyUpdatedAt, possiblyCompromisedAt}` rows after decryption, the worker computes findings, posts them back, and both sides drop plaintext references immediately after the pass. The worker is terminated — not just idled — when the vault locks (the lock action and session-timeout path already clear the CryptoKey; they additionally call `healthStore.$reset()` + `worker.terminate()`).

**Why a worker:** zxcvbn over a 1,000-secret vault is hundreds of ms of CPU; blocking the main thread on unlock would jank the list render. **Why findings live in Pinia memory only:** same rule as the session CryptoKey — a JS variable, never `localStorage`/`sessionStorage`, vanishing with the tab.

Analysis triggers: full pass on unlock (lazy — after first list render), incremental re-score on secret create/update, full reuse-map rebuild on any value change (the map is cheap: one SHA-256 per secret).

### D3: Reuse Detection via In-Memory SHA-256 Map

Reuse = two or more secrets whose decrypted `key` values are byte-identical. The worker builds `Map<sha256(value), secretId[]>` and flags every id in buckets of size ≥ 2. SHA-256 via WebCrypto `crypto.subtle.digest`, digests kept only inside the worker for the lifetime of the pass.

**Why digests instead of comparing raw strings:** avoids holding all plaintexts simultaneously in one long-lived structure; the map retains 32-byte digests, plaintext rows are released per-chunk. **Why exact match only:** near-duplicate detection ("password2024" vs "password2025") is a real Watchtower-class refinement but needs similarity metrics over plaintext that keep far more material in memory — explicitly deferred, noted in the report UI copy ("identical passwords").

### D4: `key_updated_at` — Server-Maintained Ciphertext Age

`updated_at` resets when a user renames a secret or moves it to a folder, which would silently un-stale a 5-year-old password. New column `key_updated_at` on `doriath_secrets`: SecretService sets it to now() whenever the stored encrypted `key` blob changes (simple inequality on the ciphertext string — no decryption involved; re-encryption during suite migration also touches the blob, an acceptable false-reset documented in the report copy). Backfilled from `updated_at` in the migration. Staleness threshold is a user setting (90/180/365 days/never, default 365), stored with the existing user settings.

**Why this is E2E-safe:** the column says *when the ciphertext last changed* — a fact the server already knows from its own write log — and reveals nothing about the value.

### D5: HIBP via k-Anonymity Through a Prefix-Only Server Proxy

Flow (per password-bearing secret, when both gates are on): worker computes SHA-1(value) → keeps the 35-char suffix locally → calls `GET /api/v1/breach-check/range/{prefix}` with the 5-char prefix → Doriath's server forwards to `https://api.pwnedpasswords.com/range/{prefix}` (with `Add-Padding: true`) and returns the suffix list verbatim → worker checks for its suffix in the list.

- **Why a server proxy instead of browser-direct:** Nextcloud CSP would need an external `connect-src` carve-out for every user, and browser-direct calls leak each user's IP + query timing to a third party. The proxy keeps CSP closed and makes the instance the only party HIBP sees. The proxy learns exactly 5 hash characters — by k-anonymity design that matches ~800 known-breached passwords plus the unbounded space of all others; it cannot recover the password and MUST NOT log prefixes with user association.
- **Gating:** `breach_check_enabled` admin setting (default **off** — municipal/air-gapped instances must not surprise-call external APIs) AND a per-user opt-in in user settings. UI for the feature is absent unless both are on.
- **Proxy cache:** per-prefix response cached server-side (`OCP\ICache`, 12h TTL) — responses are public breach data keyed by prefix, carrying no user data; caching also rate-limits outbound traffic naturally. Upstream failure degrades softly: breach findings show "check unavailable", other findings unaffected.
- **Why SHA-1:** it is the HIBP corpus's key, used here as a lookup index, not as a security control.

### D6: Scores Render Only From Memory — Nothing Persisted, Ever

The badge component and report view read exclusively from `useHealthStore`. There is no API that accepts a score, digest, hash (beyond the 5-char prefix), or verdict; nothing health-related is written to `localStorage`, `sessionStorage`, IndexedDB, or the server. Rationale: a persisted zxcvbn score is a crackability oracle ("attack the score-1 ones first"), a persisted digest is an offline-bruteforce target, and a persisted breach verdict outlives the user's fix. This is stated as a spec requirement with a persistence-leak test (mirroring the export store's guard).

Consequence accepted: badges appear only after the post-unlock analysis pass, and health state is recomputed every session. At vault scale (50-item pages, worker-side compute) this is cheap.

### D7: Health Score and Report Composition

Vault health score = `100 × (1 − weighted findings / analysed secrets)`, weights: breached 1.0, reused 0.8, weak (zxcvbn ≤ 2) 0.6, stale 0.3, possibly-compromised (suite migration flag, from the server — the one server-known finding) 0.8; clamped 0–100, shown with the finding counts rather than as an opaque grade. The report view lists each category with affected secrets (name, folder path, badge) deep-linking to the detail view. The dashboard card shows the score + top-line counts and links to the report; it renders a locked-state placeholder ("Unlock to analyse") when no session exists, because the dashboard is reachable pre-unlock.

## Risks / Trade-offs

- **[Risk] Plaintext transits the worker boundary** — `postMessage` structured-clones values into the worker. Same-origin, same-process memory, identical trust domain as the main thread that already holds decrypted values; references dropped after the pass and the worker terminated on lock. No weaker than the status quo.
- **[Risk] Heuristic misclassification (D1)** — a random 24-char generated password scores zxcvbn 4 (fine); a 100-char passphrase gets excluded from weak-scoring by the length cut (acceptable: it is not weak); an API token under 64 chars gets zxcvbn-scored and may show "strong" noise. Bounded by tests + the report copy explaining what is analysed.
- **[Risk] HIBP availability/policy drift** — soft-degrade per D5; the admin toggle is also the kill switch.
- **[Trade-off] No health history** — operators cannot chart vault hygiene over time. Deliberate (D6): trends require persisting derived signals. Revisit only with an explicit privacy design (e.g. persisting only aggregate counts, never per-secret).
- **[Trade-off] Suite-migration re-encryption resets `key_updated_at`** — rare event, documented; tracking "value age" separately from "ciphertext age" would require the client to attest value-unchanged, adding a writable client-controlled field for marginal gain.
- **[Trade-off] Shared secrets analysed per copy** — a reused password shared to 5 recipients appears in each recipient's own analysis independently. Correct under E2E (each copy is the recipient's row) and arguably the desired behaviour: every holder should see the finding.

## Migration Plan

1. **Database migration**: ISchemaWrapper migration adding `key_updated_at` (datetime, nullable) to `doriath_secrets`, backfilled from `updated_at`; `occ upgrade`
2. **Backend**: SecretService ciphertext-change maintenance + BreachProxyController + admin setting; routes registered
3. **Frontend build**: no new npm dependencies (zxcvbn present); `npm run build`
4. **Rollback**: feature is UI + one inert nullable column + one proxy endpoint behind a default-off admin setting
5. **Greenfield**: first analysis pass happens on first unlock after deployment; no data migration

## Open Questions

- Should "weak" thresholds be admin-raisable (flag zxcvbn ≤ 3 instead of ≤ 2) to mirror the master-password policy floor pattern? Current decision: fixed at ≤ 2 for v1; the admin policy applies to the master password, not stored credentials.
- Should the dashboard card be visible to users who opted out of breach checking with a reduced category set? Current decision: yes — breach is one of five categories; the card simply omits it.
- Near-duplicate detection (edit-distance over plaintext) — deferred per D3; if demanded, it should run in the same worker pass with explicit memory bounds.
