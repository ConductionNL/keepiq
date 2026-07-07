## 0. Scope Note (read first)

Manifest/metadata honesty fix only — no functional code. One attribute in `appinfo/info.xml` plus two stale tier labels in `docs/FEATURES.md`. **Deviation from the authored value (empirically verified 2026-07-07):** the deployed Nextcloud `app-info.xsd` licence enumeration accepts the SPDX code `EUPL-1.2` (which requires `<nextcloud min-version>` ≥ 31 — Doriath already declares `min-version="31"`) but does NOT contain the short `eupl` code. Validating `<licence>eupl</licence>` against the deployed `app-info.xsd` fails with an enumeration error; `<licence>EUPL-1.2</licence>` passes. The correct value is therefore `EUPL-1.2`, not `eupl`.

## 1. Manifest

- [x] 1.1 In `appinfo/info.xml`, change `<licence>agpl</licence>` to `<licence>EUPL-1.2</licence>` (see the deviation note above — `EUPL-1.2` is the schema-valid SPDX code, not `eupl`).
- [x] 1.2 Confirmed the manifest validates against the deployed Nextcloud `app-info.xsd` (`EUPL-1.2` passes the licence enumeration; `eupl` is rejected — empirically checked with `DOMDocument::schemaValidate` against `/var/www/html/resources/app-info.xsd`).

## 2. Published feature-tier reconciliation (docs/FEATURES.md)

- [ ] 2.1 In the "Dashboard & Reporting" matrix, change the "Password health report (weak, reused, old passwords)" tier from **Enterprise** to the shipped tier, consistent with its `V1 ✅` label in the Core Secret Management matrix (it is built and archived as `password-health`).
- [ ] 2.2 In the same matrix, change the "Breach detection (HaveIBeenPwned integration)" tier from **Enterprise** to the shipped tier, consistent with its `V1 ✅` label earlier in the same document (built as `BreachProxyController` k-anonymity range proxy).
- [x] 2.3 Skimmed the rest of `docs/FEATURES.md`; audit trail and GDPR export/deletion are already marked `✅ Built`; the only shipped-but-mislabelled cells were the two reconciled above. No further contradictions remain.

## 3. Verification

- [x] 3.1 Grepped first-party packaging files for `agpl`/`AGPL`; the only remaining hit is a licence-compatibility list in `README.md` (naming AGPL-3.0 among EUPL-compatible licences), not a declaration. No first-party packaging file declares agpl.
- [x] 3.2 Confirmed `LICENSE`, `composer.json`, `README.md`, and the `lib/` SPDX headers are unchanged and all still name EUPL-1.2 (this change only corrects the manifest to match them).

## Acceptance Criteria

- `appinfo/info.xml` declares `<licence>EUPL-1.2</licence>` and the manifest validates against the deployed `app-info.xsd`.
- No first-party packaging file (`info.xml`, `composer.json`, `LICENSE`, `README.md`) declares any licence other than EUPL-1.2.
- The two `docs/FEATURES.md` tier labels for password-health and breach detection reflect their shipped status rather than an unbuilt Enterprise tier.
- No functional code, route, or schema change is introduced.
