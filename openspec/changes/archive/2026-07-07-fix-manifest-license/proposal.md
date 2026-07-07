---
kind: code
---

## Why

Doriath's Nextcloud manifest declares the wrong licence. `appinfo/info.xml` contains `<licence>agpl</licence>`, but every other authoritative licence declaration in the repository says **EUPL-1.2**:

- `LICENSE` — the full text of the *European Union Public Licence v. 1.2* (not AGPL).
- `composer.json` — `"license": "EUPL-1.2"`.
- `README.md` — the licence badge (`license-EUPL--1.2`) and the "This project is licensed under the [EUPL-1.2]" statement.
- Every `lib/**/*.php` SPDX docblock — `@license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12`.

The `<licence>` element is not cosmetic: it is the value the **Nextcloud app store surfaces to every user and administrator** evaluating the app, and it is the machine-readable licence of record for the packaged app. EUPL-1.2 and AGPL-3.0 are different licences with different copyleft scope and compatibility obligations, so an `agpl` declaration on an EUPL-1.2 work is a substantive licensing misstatement, not a typo. It is also internally contradictory — a downstream consumer cannot tell which of the two conflicting declarations governs.

This is a readiness-honesty defect: the manifest claims something the codebase does not back up. The fix is one line and carries no functional risk.

The correct Nextcloud app-store licence code for EUPL-1.2 is `eupl`, which is already the value used by sibling Conduction apps that ship under this licence (e.g. pipelinq, portaliq, hermiq, petstore, and the Conduction Nextcloud app template).

## What Changes

- Change `appinfo/info.xml` `<licence>agpl</licence>` to `<licence>eupl</licence>`, so the manifest matches the `LICENSE` file, `composer.json`, the README, and the per-file SPDX headers.
- Reconcile two stale tier labels in `docs/FEATURES.md` where a shipped-and-verified capability is still filed under an unbuilt future tier — an adjacent published-claim honesty defect touched in the same pass:
  - "Password health report (weak, reused, old passwords)" is labelled **Enterprise** in the Dashboard & Reporting matrix (line ~198) but is built and archived as the `password-health` capability (and is already marked `V1 ✅` earlier in the same document at line ~76). Align it to the shipped tier.
  - "Breach detection (HaveIBeenPwned integration)" is labelled **Enterprise** in the same matrix (line ~199) but is built (`BreachProxyController`, k-anonymity range proxy) and already marked `V1 ✅` at line ~82. Align it to the shipped tier.
- No functional code changes: this is a manifest/metadata correction only. No route, controller, service, entity, migration, or frontend change.

### Non-Goals

- **No change to the actual licence.** Doriath is and remains EUPL-1.2; this change only makes the manifest tell the truth. The `LICENSE` file, `composer.json`, README, and SPDX headers are already correct and are not touched (except the two `docs/FEATURES.md` tier labels above).
- **No sweep of sibling apps.** Some older Conduction apps still carry `<licence>agpl</licence>`; correcting them is out of scope for this Doriath change.
- **No `info.xml` version bump for cache-busting** beyond whatever the normal release flow applies — this is not an immutable-asset change.

## Capabilities

### Added Capabilities

- `distribution`: adds the "Manifest Licence Declaration Consistency" requirement — the app's published manifest licence must match its canonical `LICENSE`/`composer.json` licence. This capability is the correct home because the concern is the correctness of the packaged, app-store-published metadata, which no existing feature capability (`admin-settings`, `secrets`, …) owns.

## Impact

- **Manifest**: `appinfo/info.xml` — one attribute value.
- **Docs**: `docs/FEATURES.md` — two tier-label cells reconciled to shipped reality.
- **Backend / Frontend / Database / API**: none.
- **App store**: the published licence will read EUPL-1.2 (`eupl`) instead of AGPL, matching the bundled `LICENSE`. No behavioural change for existing installs.
- **Security**: none.
