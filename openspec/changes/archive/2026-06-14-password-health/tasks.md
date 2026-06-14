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

- [x] 1.1 Create ISchemaWrapper migration (next free version number) adding `key_updated_at` (datetime, nullable) to `doriath_secrets`, backfilled from `updated_at` — `Version000016Date20260614000001` (postSchemaChange backfill)
- [x] 1.2 Extend the Secret entity/mapper with `key_updated_at`; include in `jsonSerialize()` (also surfaced `possiblyCompromisedAt` for the report)
- [x] 1.3 SecretService: set `key_updated_at = now()` whenever the stored encrypted `key` blob changes (ciphertext inequality check; create sets it too); name/url/folder/type edits MUST NOT touch it
- [x] 1.4 Add `breach_check_enabled` admin setting (default off) with GET/PUT alongside the existing Doriath admin settings; expose it to the frontend initial state (`breachCheckEnabled` via DashboardController)
- [x] 1.5 Create `BreachProxyController` with `#[NoAdminRequired]` endpoint `GET /api/v1/breach-check/range/{prefix}`: 403 when the admin gate is off; validate prefix is exactly 5 hex chars (400 otherwise); forward to `https://api.pwnedpasswords.com/range/{prefix}` with `Add-Padding: true` via `IClientService`; return the body verbatim; cache per prefix in `OCP\ICache` (12h TTL); map upstream failure to 503; never log prefix + user together
- [x] 1.6 Add the staleness-threshold user setting (90/180/365/never, default 365) to the existing user-settings storage (`health_staleness_days` + `breach_check_opt_in`, validated)
- [x] 1.7 Register routes in `appinfo/routes.php` before the SPA catch-all; run hydra gates (route-auth, semantic-auth, spec-coverage) — all 24 gates green for the diff

## 2. Frontend — Health Engine (Web Worker)

- [x] 2.1 Create `src/health/classify.js`: pure password-bearing heuristic (exclude PEM blocks, base64/hex blobs ≥ 64 chars, values > 72 chars from strength scoring; everything participates in reuse detection) with documented examples
- [x] 2.2 Create `src/health/worker.js` + `src/health/engine.js`: receives `{id, name, url, value, keyUpdatedAt, possiblyCompromisedAt}` rows, computes zxcvbn scores (password-bearing only), SHA-256 reuse map (WebCrypto digest, buckets ≥ 2 flagged), staleness vs threshold, and the weighted vault score (breached 1.0 / reused 0.8 / weak ≤ 2 0.6 / stale 0.3 / possibly-compromised 0.8); drops plaintext references after the pass. The pure logic lives in `engine.js` (unit-tested directly); `worker.js` is the off-main-thread wrapper, with an inline fallback in the store when the Worker API is absent.
- [x] 2.3 Create `src/health/hibp.js`: SHA-1, 5-char prefix extraction, proxy call, local suffix match with occurrence count; runs only when both gates are on; soft-fail to `unavailable`
- [x] 2.4 Create `src/store/modules/health.js` (`useHealthStore`): memory-only findings/score/status; triggers — full pass after unlock (lazy, post first list render, fired from SecretList), re-score on re-analyse, reuse-map rebuild on any value change
- [x] 2.5 Wire the lock lifecycle: manual lock and session-timeout paths call `healthStore.reset()` (clears state) and `worker.terminate()` in addition to clearing the CryptoKey — via an `onVaultLock` hook registered by the store (avoids a circular import; equivalent to the `$reset()` intent)

## 3. Frontend — UI

- [x] 3.1 Create `src/components/StrengthBadge.vue`: color-coded score badge (CSS variables only, no hardcoded colors; accessible label, not color-alone per WCAG) used in the secrets list rows (`SecretListItem`); renders nothing while locked or for non-scored secrets
- [x] 3.2 Create `src/views/HealthReportView.vue`: overall score + counts, five category sections (weak / reused / stale / breached / possibly-compromised) listing name + folder path with deep links to the detail view (via `HealthCategory.vue`); breach section only when active, `unavailable` state on upstream failure
- [x] 3.3 Add the dashboard health summary card (`HealthSummaryCard.vue`) within the existing Vault Summary Cards pattern: score + top-line counts, link to the report, locked-state placeholder "Unlock to analyse"
- [~] 3.4 Add user-settings entries: staleness threshold select (NcSelect with `inputLabel`) and the breach-check opt-in toggle (rendered only when the admin gate is on, with a plain-language k-anonymity explanation). Placed INLINE in `HealthReportView` rather than a separate user-settings dialog — the standalone user-prefs section host (`NotificationTogglesSection` etc.) is not currently mounted in any view, so the report view is the live surface; prefs persist via `PUT /api/settings/user`.
- [x] 3.5 Add the breach-check master toggle to the Doriath admin settings section (`BreachCheckSection.vue`) with an external-call disclosure ("sends 5-character hash prefixes to Have I Been Pwned")

## 4. Internationalization

- [x] 4.1 Add English strings (badge labels, category names, report copy incl. "identical passwords" wording and the suite-migration age-reset note, settings labels, k-anonymity explanation, unavailable state) to `l10n/en.json` — English source strings as keys; l10n parity check green
- [x] 4.2 Add Dutch translations to `l10n/nl.json`

## 5. Unit Tests (PHP)

- [x] 5.1 SecretService `key_updated_at` tests: set on create and on key-blob change; NOT reset by rename/move/url edits or a no-op key resubmit
- [x] 5.2 BreachProxyController tests: 403 when admin gate off; 400 on non-5-hex prefix; verbatim passthrough with `Add-Padding`; cache hit skips upstream; 503 on upstream failure; assert no log line combines prefix and user id
- [x] 5.3 Admin/user settings tests: defaults (off / 365), validation of allowed threshold values

## 6. Frontend Tests (vitest)

- [x] 6.1 `classify.js` tests: PEM/base64/hex/length exclusions, normal passwords included; documented examples as fixtures
- [x] 6.2 Engine logic tests: scoring categories, reuse buckets (two identical values both flagged; fix clears both), staleness against thresholds, weighted score formula incl. possibly-compromised input, no-plaintext-in-findings
- [x] 6.3 `hibp.js` tests: only the 5-char prefix appears in the request; suffix match local with count; soft-fail path; known SHA-1 vector
- [x] 6.4 `useHealthStore` tests: persistence-leak guard (no localStorage/sessionStorage writes); `reset()` + worker termination on lock; lock-hook reset; abort-when-locked
- [~] 6.5 Component tests (jsdom): the existing `DashboardSummaryView` jsdom spec still passes with the embedded health card. Dedicated badge/report jsdom component specs were not added separately — the badge/report logic is fully covered by the engine + store vitest suites and the Playwright e2e; the components are thin presentation over `scoreById` / store getters.

## 7. E2E (Playwright)

- [x] 7.1 Badge e2e: unlock → strength badge visible in the list (`password-health.spec.ts`). The "edit to strong → badge updates without reload" step is covered as an in-session recompute by the engine vitest (`@e2e exclude` on that scenario).
- [x] 7.2 Report e2e: open health report → categories populated, finding click lands on the secret detail
- [x] 7.3 Breach gating e2e: admin gate off → no opt-in visible and no proxy request recorded. The "gate on + opt-in → breach category appears" path needs live HIBP + both gates and is `@e2e exclude`d (covered by vitest hibp + engine).
- [x] 7.4 Lock e2e: locked vault → list shows no badges, dashboard card shows the unlock placeholder
- [x] 7.5 Annotate spec scenarios per gate-19 (`@e2e` refs for the badge/report/gating/lock DOM flows; `@e2e exclude` with reasons for non-DOM contracts: in-memory/worker discard, digest-map internals, wire-shape, server age-maintenance, no-health-write-surface route enumeration, proxy logging — covered by vitest/PHPUnit). Gate-19 green.

## 8. Documentation

- [x] 8.1 Update `docs/FEATURES.md` status for the weak/reused/old flagging, strength-badge, breach, and age rows (marked ✅ implemented in `password-health`)
- [x] 8.2 Add `docs/password-health.md`: what is analysed (and the key-material heuristic), the E2E argument for why everything is client-side and nothing is persisted, the k-anonymity flow with the exact data that leaves the browser/instance, the double gate, and the suite-migration `key_updated_at` reset caveat
