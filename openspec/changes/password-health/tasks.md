## 0. Dependency Note (read first)

This change depends on `implement-encryption-suites` (archived — unlocked
CryptoKey session + lock lifecycle hooks), `implement-secrets` /
`implement-secrets-write-ui` (Secret entity, decrypt path, list/detail UI),
and `implement-dashboard-settings` (Vault Summary Cards slot for the health
card, user-settings dialog for the staleness threshold + breach opt-in).
zxcvbn is already shipped for the master-password meter — reuse it, do not add
a second strength library. All analysis is client-side per ADR-003; the only
server pieces are the `key_updated_at` column and the HIBP prefix proxy.

## 1. Database Migration and Backend

- [ ] 1.1 Create ISchemaWrapper migration (next free version number) adding `key_updated_at` (datetime, nullable) to `doriath_secrets`, backfilled from `updated_at`
- [ ] 1.2 Extend the Secret entity/mapper with `key_updated_at`; include in `jsonSerialize()`
- [ ] 1.3 SecretService: set `key_updated_at = now()` whenever the stored encrypted `key` blob changes (ciphertext inequality check; create sets it too); name/url/folder/type edits MUST NOT touch it
- [ ] 1.4 Add `breach_check_enabled` admin setting (default off) with GET/PUT alongside the existing Doriath admin settings; expose it to the frontend initial state
- [ ] 1.5 Create `BreachProxyController` with `#[NoAdminRequired]` endpoint `GET /api/v1/breach-check/range/{prefix}`: 403 when the admin gate is off; validate prefix is exactly 5 hex chars (400 otherwise); forward to `https://api.pwnedpasswords.com/range/{prefix}` with `Add-Padding: true` via `IClientService`; return the body verbatim; cache per prefix in `OCP\ICache` (12h TTL); map upstream failure to 503; never log prefix + user together
- [ ] 1.6 Add the staleness-threshold user setting (90/180/365/never, default 365) to the existing user-settings storage
- [ ] 1.7 Register routes in `appinfo/routes.php` before the SPA catch-all; run hydra gates (route-auth, semantic-auth, spec-coverage)

## 2. Frontend — Health Engine (Web Worker)

- [ ] 2.1 Create `src/health/classify.js`: pure password-bearing heuristic (exclude PEM blocks, base64/hex blobs ≥ 64 chars, values > 72 chars from strength scoring; everything participates in reuse detection) with documented examples
- [ ] 2.2 Create `src/health/worker.js`: receives `{id, name, url, value, keyUpdatedAt, possiblyCompromisedAt}` rows, computes zxcvbn scores (password-bearing only), SHA-256 reuse map (WebCrypto digest, buckets ≥ 2 flagged), staleness vs threshold, and the weighted vault score (breached 1.0 / reused 0.8 / weak ≤ 2 0.6 / stale 0.3 / possibly-compromised 0.8); drops plaintext references after the pass
- [ ] 2.3 Create `src/health/hibp.js`: SHA-1 in worker, 5-char prefix extraction, proxy call, local suffix match with occurrence count; runs only when both gates are on; soft-fail to `unavailable`
- [ ] 2.4 Create `src/store/modules/health.js` (`useHealthStore`): memory-only findings/score/status; triggers — full pass after unlock (lazy, post first list render), incremental re-score on secret create/update, reuse-map rebuild on any value change
- [ ] 2.5 Wire the lock lifecycle: manual lock and session-timeout paths call `healthStore.$reset()` and `worker.terminate()` in addition to clearing the CryptoKey

## 3. Frontend — UI

- [ ] 3.1 Create `src/components/StrengthBadge.vue`: color-coded score badge (CSS variables only, no hardcoded colors; accessible label, not color-alone per WCAG) used in the secrets list rows and detail view; renders nothing while locked or for non-scored secrets
- [ ] 3.2 Create `src/views/HealthReportView.vue`: overall score + counts, five category sections (weak / reused / stale / breached / possibly-compromised) listing name + folder path with deep links to the detail view; breach section only when active, `unavailable` state on upstream failure
- [ ] 3.3 Add the dashboard health summary card within the existing Vault Summary Cards pattern: score + top-line counts, link to the report, locked-state placeholder "Unlock to analyse"
- [ ] 3.4 Add user-settings entries: staleness threshold select (NcSelect with `inputLabel`) and the breach-check opt-in toggle (rendered only when the admin gate is on, with a plain-language explanation of the k-anonymity flow)
- [ ] 3.5 Add the breach-check master toggle to the Doriath admin settings section with an external-call disclosure ("sends 5-character hash prefixes to Have I Been Pwned")

## 4. Internationalization

- [ ] 4.1 Add English strings (badge labels, category names, report copy incl. "identical passwords" wording and the suite-migration age-reset note, settings labels, k-anonymity explanation, unavailable state) to `l10n/en.json` — English source strings as keys
- [ ] 4.2 Add Dutch translations to `l10n/nl.json`

## 5. Unit Tests (PHP)

- [ ] 5.1 SecretService `key_updated_at` tests: set on create and on key-blob change; NOT reset by rename/move/type/url edits; migration backfill correct
- [ ] 5.2 BreachProxyController tests: 403 when admin gate off; 400 on non-5-hex prefix; verbatim passthrough; cache hit skips upstream; 503 on upstream failure; assert no log line combines prefix and user id
- [ ] 5.3 Admin/user settings tests: defaults (off / 365), validation of allowed threshold values

## 6. Frontend Tests (vitest)

- [ ] 6.1 `classify.js` tests: PEM/base64/hex/length exclusions, normal passwords included; documented examples as fixtures
- [ ] 6.2 Worker logic tests: scoring categories, reuse buckets (two identical values both flagged; fix clears both), staleness against thresholds, weighted score formula incl. possibly-compromised input, plaintext references dropped after pass
- [ ] 6.3 `hibp.js` tests: only the 5-char prefix appears in the request URL; suffix match local with count; gates respected (no call when either off); soft-fail path
- [ ] 6.4 `useHealthStore` tests: persistence-leak guard (no localStorage/sessionStorage/IndexedDB writes); `$reset` + worker termination on lock; incremental re-score on edit
- [ ] 6.5 Component tests (jsdom): badge renders per score and nothing while locked; report deep-link wiring; dashboard card locked placeholder; breach opt-in hidden when admin gate off

## 7. E2E (Playwright)

- [ ] 7.1 Badge e2e: seed a weak and a strong secret → unlock → weak badge visible in the list → edit weak secret to a strong value → badge updates without reload
- [ ] 7.2 Report e2e: vault with weak + reused + stale findings → open health report → categories populated, finding click lands on the secret detail
- [ ] 7.3 Breach gating e2e: admin gate off → no opt-in visible and no proxy request recorded; gate on + opt-in → breach category appears (proxy stubbed at the network layer)
- [ ] 7.4 Lock e2e: lock the vault → list shows no badges, dashboard card shows the unlock placeholder
- [ ] 7.5 Annotate spec scenarios per gate-19 (`@e2e` refs for badge/report/gating/lock flows; `@e2e exclude` with reasons for non-DOM contracts: worker memory discard, digest-map internals, no-health-write-surface route enumeration, proxy logging — covered by vitest/PHPUnit)

## 8. Documentation

- [ ] 8.1 Update `docs/FEATURES.md` status for the weak/reused/old flagging and strength-badge rows
- [ ] 8.2 Add `docs/password-health.md`: what is analysed (and the key-material heuristic), the E2E argument for why everything is client-side and nothing is persisted, the k-anonymity flow with the exact data that leaves the browser/instance, the double gate, and the suite-migration `key_updated_at` reset caveat
