# Vault recently used Specification

**Status**: done

**OpenSpec changes:**
- [vault-defaults-and-recently-used-widget](../../changes/archive/2026-09-30-vault-defaults-and-recently-used-widget/) _(archived 2026-09-30)_

## Purpose
The dashboard lists the secrets the user opened last, one row per secret, and a row opens the secret. Parity row vault-21.

## Requirements

### Requirement: Recently used on the dashboard

The dashboard MUST show a Recently used widget that lists the signed-in user's most recently read secrets, newest first, at most five, one row per secret with its name and a relative time. A row MUST open that secret. The widget MUST list only secrets the user still holds and MUST show an empty state when there are none.

#### Scenario: A user sees what they opened last

@e2e exclude Needs a vault with read secrets on a live instance; covered by PHPUnit tests/Unit/Service/RecentlyUsedServiceTest.php testDistinctSecretsNewestFirst and testCapsAtFiveRows.

- **GIVEN** a user who opened three different secrets and one of them twice
- **WHEN** the user opens the dashboard
- **THEN** the widget lists three rows, the twice-opened secret once, newest first

#### Scenario: A row opens the secret

@e2e exclude The row route is the widget's rowRoute SecretList with params {id}, the same location secretDetailLocation() builds; a live click needs a vault on the test instance.

- **GIVEN** the Recently used widget with rows
- **WHEN** the user clicks a row
- **THEN** the secret list opens with that secret selected in the sidebar

#### Scenario: A deleted secret is not listed

@e2e exclude Covered by PHPUnit tests/Unit/Service/RecentlyUsedServiceTest.php testSecretsNoLongerHeldAreDropped.

- **GIVEN** a user who read a secret that has since been deleted
- **WHEN** the user opens the dashboard
- **THEN** the widget does not list it
