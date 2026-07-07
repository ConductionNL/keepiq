## 0. Scope Note (read first)

Manifest/metadata honesty fix only — no functional code. One attribute in `appinfo/info.xml` plus two stale tier labels in `docs/FEATURES.md`. Verify the correct app-store licence code against a sibling EUPL-1.2 app's `info.xml` at HEAD (e.g. pipelinq, portaliq, hermiq, petstore) before editing — the value is `eupl`.

## 1. Manifest

- [ ] 1.1 In `appinfo/info.xml`, change `<licence>agpl</licence>` to `<licence>eupl</licence>`.
- [ ] 1.2 Confirm the manifest still validates against the Nextcloud `info.xsd` (`eupl` is an accepted app-store licence code — already used by sibling Conduction apps).

## 2. Published feature-tier reconciliation (docs/FEATURES.md)

- [ ] 2.1 In the "Dashboard & Reporting" matrix, change the "Password health report (weak, reused, old passwords)" tier from **Enterprise** to the shipped tier, consistent with its `V1 ✅` label in the Core Secret Management matrix (it is built and archived as `password-health`).
- [ ] 2.2 In the same matrix, change the "Breach detection (HaveIBeenPwned integration)" tier from **Enterprise** to the shipped tier, consistent with its `V1 ✅` label earlier in the same document (built as `BreachProxyController` k-anonymity range proxy).
- [ ] 2.3 Skim the rest of `docs/FEATURES.md` for any other shipped capability still filed under an unbuilt future tier and align it (audit trail, GDPR export/deletion are already marked `✅ Built`; verify no further contradictions remain).

## 3. Verification

- [ ] 3.1 Grep the repository for any remaining `agpl` / `AGPL` licence declaration outside third-party dependency metadata; confirm none remain in first-party packaging files.
- [ ] 3.2 Confirm `LICENSE`, `composer.json`, `README.md`, and the `lib/` SPDX headers are unchanged and all still name EUPL-1.2 (this change only corrects the manifest to match them).

## Acceptance Criteria

- `appinfo/info.xml` declares `<licence>eupl</licence>` and the manifest validates against `info.xsd`.
- No first-party packaging file (`info.xml`, `composer.json`, `LICENSE`, `README.md`) declares any licence other than EUPL-1.2.
- The two `docs/FEATURES.md` tier labels for password-health and breach detection reflect their shipped status rather than an unbuilt Enterprise tier.
- No functional code, route, or schema change is introduced.
