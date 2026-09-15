# admin-integrations Specification Delta

**Status**: proposed
**Scope**: keepiq
**OpenSpec changes**:
- [adopt-connection-registry](../../)

## Purpose

Admins see Keepiq's outside connections on one page, with a status Keepiq can back.

## ADDED Requirements

### Requirement: REQ-KEEPIQ-CONN-001 Keepiq declares its outside connections in one static file

Keepiq SHALL declare `hibp` and `siem` in `lib/Settings/connections.json` in the shape of hydra connection-registry design D2 (hydra REQ-CONN-001). The file MUST validate against integriq's `connections.schema.json`, and its `app` MUST equal the id in `appinfo/info.xml`. The `hibp` entry SHALL declare `breach_check_enabled` as its `switch` and SHALL require no setting, so a switched-off check reads `disabled` (hydra connection-registry D12 item 9). The `siem` entry SHALL be `reportedOnly`, because the sinks are records and not settings. Every `settingsUrl` SHALL point at a section id that exists in the Keepiq admin settings.

#### Scenario: The declaration names this app and passes integriq's schema
@e2e exclude A static file with no browser surface; tests/Unit/Settings/ConnectionsDeclarationTest.php validates it against the schema, and checks the app id, unique keys and the anchors.

- **GIVEN** `lib/Settings/connections.json`
- **WHEN** it is validated against integriq's `connections.schema.json`
- **THEN** it SHALL validate
- **AND** its `app` SHALL equal the id in `appinfo/info.xml`
- **AND** every key SHALL be unique
- **AND** every `#section-...` anchor SHALL be an id in a settings section component

#### Scenario: A switched-off breach check reads switched off
@e2e tests/e2e/workflows/integrations-page.spec.ts

- **GIVEN** integriq has synced Keepiq's declaration
- **WHEN** `breach_check_enabled` holds `false`
- **THEN** the Breach check row SHALL read `disabled` with the declared message
- **AND** when an admin switches breach checking on, the row SHALL read Not configured with "Not checked yet" until a lookup reports

### Requirement: REQ-KEEPIQ-CONN-002 A save asks integriq to look again, and a lookup or a drain reports what it met

When an admin save writes `breach_check_enabled`, Keepiq SHALL send `ConnectionRefreshRequestedEvent` with app `keepiq` and key `hibp`. When an admin creates, changes or deletes a SIEM sink, Keepiq SHALL send a refresh for `siem`. When no sink is switched on afterwards, it SHALL then report `disabled` while sinks exist, and `unconfigured` when none does. A `disabled` report SHALL name no host. A refresh SHALL come before any report for the same key (hydra REQ-CONN-004, hydra#674). A range lookup that reaches Have I Been Pwned SHALL report `configured` on a 2xx answer, `limited` on HTTP 429 and `error` on any other answer or no answer. A SIEM drain SHALL report over the sinks it delivered to in that run: all took it as `configured`, some as `limited`, none as `error`. A drain that delivered to no sink SHALL report nothing. The same status SHALL be reported at most once an hour and a new status at most once every five minutes, and a save SHALL clear that memory. Both events SHALL be named by string and sent only when the class exists. Neither SHALL change the response of the request, job or run that sent it.

#### Scenario: Saving the breach check switch asks for a refresh
@e2e exclude The event is not observable from a browser; tests/Unit/Controller/SettingsControllerConnectionRefreshTest.php asserts the refresh and the unchanged response.

- **GIVEN** integriq is installed
- **WHEN** an admin saves the admin settings with `breach_check_enabled`
- **THEN** Keepiq SHALL send a refresh for `hibp`
- **AND** a save without that key SHALL send nothing

#### Scenario: Deleting the last sink refreshes, then reports
@e2e exclude A sink change needs a SIEM receiver the CI instance does not have; tests/Unit/Service/Connection/ConnectionReporterTest.php asserts the order, and tests/Unit/Service/SiemConnectionReportCallersTest.php that the sink service hands it over.

- **GIVEN** integriq is installed
- **WHEN** an admin deletes the only SIEM sink
- **THEN** Keepiq SHALL send a refresh for `siem`
- **AND** then a report `unconfigured` saying no sink is added yet

#### Scenario: Switching off the last enabled sink reports disabled
@e2e exclude A sink change needs a SIEM receiver the CI instance does not have; tests/Unit/Service/SiemConnectionReportCallersTest.php and tests/Unit/Service/Connection/ConnectionReporterTest.php assert the refresh, the `disabled` report and its host-free message.

- **GIVEN** integriq is installed and two SIEM sinks exist
- **WHEN** an admin switches off the last one that was enabled
- **THEN** Keepiq SHALL send a refresh for `siem`
- **AND** then a report `disabled` that names no host

#### Scenario: A drain where some sinks fail reads limited
@e2e exclude A drain needs reachable and unreachable receivers; tests/Unit/Service/Connection/ConnectionObservationsTest.php drives the outcomes.

- **GIVEN** two enabled sinks with queued events
- **WHEN** the drain delivers to one and fails on the other
- **THEN** Keepiq SHALL report `siem` as `limited`
- **AND** the message SHALL name the failing sink's host

#### Scenario: A repeated outcome is not reported on every lookup
@e2e exclude The throttle is a time window; tests/Unit/Service/Connection/ConnectionReporterTest.php drives the clock.

- **GIVEN** a lookup reported `configured` a minute ago
- **WHEN** another lookup answers 200
- **THEN** Keepiq SHALL send no report
- **AND** a lookup that fails five minutes after the last report SHALL report `error`

#### Scenario: Without integriq nothing is sent
@e2e exclude The CI instance installs integriq; tests/Unit/Service/Connection/ConnectionReporterTest.php asserts nothing is sent, read or logged when the class is absent.

- **GIVEN** integriq is not installed
- **WHEN** an admin saves the breach check switch, a lookup runs or a drain runs
- **THEN** no event SHALL be sent and nothing SHALL be logged
- **AND** the save, lookup or drain SHALL answer as it did before this change

### Requirement: REQ-KEEPIQ-CONN-003 A report names a status code or a host, and nothing a user typed

A connection report is read by every admin, and integriq stores it in OpenRegister. A breach check report SHALL carry no hash prefix, no password, no hash suffix and no exception text. Only the HTTP status of the upstream answer SHALL reach it. A SIEM report SHALL name a sink by its host only, never by its URL path, query, user info or token, and SHALL never carry a delivery error text. No report SHALL carry vault data: no entry name, folder, user id or secret (integration-boundary).

#### Scenario: A failed lookup reports no part of the lookup
@e2e exclude The prefix only exists inside one request; tests/Unit/Controller/BreachProxyControllerConnectionReportTest.php sends a known prefix through a failing and a passing upstream and reads every event.

- **GIVEN** a user checks a password whose hash starts with `A1B2C`
- **WHEN** the upstream call fails with an exception naming the full range URL
- **THEN** Keepiq SHALL report `error`
- **AND** no report message SHALL contain `A1B2C`, in any letter case, or the upstream URL

#### Scenario: A sink with a token in its URL is named by host
@e2e exclude Needs a failing webhook receiver; tests/Unit/Service/Connection/ConnectionObservationsTest.php feeds URLs with user info, paths and tokens.

- **GIVEN** a webhook sink at `https://user:s3cret@siem.gemeente.example/ingest?token=abc`
- **WHEN** a drain fails to deliver to it
- **THEN** the report SHALL name `siem.gemeente.example`
- **AND** it SHALL contain none of `s3cret`, `user`, `/ingest` or `token`

### Requirement: REQ-KEEPIQ-CONN-004 An admin reads the connections on an Integrations page

Keepiq SHALL render an `index` page at `/settings/integrations` over `integriq/app_connection`, reached from the settings gear and preset to `app` equal to `keepiq` through its menu entry's `query` (hydra REQ-CONN-006). The page and its menu entry SHALL be admin only. The page SHALL require Integriq, and the menu entry SHALL only render when integriq is installed. Keepiq's navigation rail SHALL pass the entry's `query` into the route and SHALL honour `permission: admin` and `visibleIf.appInstalled`. The status column SHALL name all six statuses, `limited` included. The page SHALL NOT offer a generic Add button. Its Add integration action SHALL open `/apps/integriq/connections?app=keepiq&link=1`.

#### Scenario: The page lists only the rows of keepiq
@e2e tests/e2e/workflows/integrations-page.spec.ts

- **GIVEN** Keepiq and integriq are installed and integriq has synced the declaration
- **WHEN** an admin opens the Integrations page from the settings gear
- **THEN** the page SHALL list the two declared connections
- **AND** every listed row SHALL have `app` equal to `keepiq`

#### Scenario: Add integration goes to integriq
@e2e tests/e2e/workflows/integrations-page.spec.ts

- **GIVEN** the Integrations page
- **WHEN** the admin chooses Add integration
- **THEN** the browser SHALL open integriq's Connections overview with `app=keepiq` and `link=1`

#### Scenario: The menu entry hides without integriq and from non-admins
@e2e exclude The CI instance always installs integriq and the e2e user is an admin; tests/vitest/navEntries.spec.js drives both conditions.

- **GIVEN** the Integrations menu entry
- **WHEN** integriq is not enabled, or the user is not an instance admin
- **THEN** Keepiq's navigation rail SHALL NOT render the entry

#### Scenario: A connection that works in part reads Limited
@e2e exclude Only a rate-limited lookup or a partly failing drain produces limited; tests/vitest/connectionRegistry.spec.js asserts the label in English and Dutch.

- **GIVEN** a row whose status is `limited`
- **WHEN** the page renders it
- **THEN** the cell SHALL read Limited, or Beperkt on a Dutch instance
