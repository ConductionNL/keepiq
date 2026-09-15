# Design: adopt-connection-registry

The contract is hydra `openspec/changes/connection-registry/design.md` (hydra#667, amended in hydra#673, hydra#674 and hydra#676). This file records how Keepiq meets it and where it fits loosely.

## D1. Which connections are declared

Each candidate was checked against the code on `development` on 2026-09-14.

| Key | Declared as | Why |
|---|---|---|
| `hibp` | `requiredConfig: ["breach_check_enabled"]` | `BreachProxyController::range()` refuses every lookup with 403 while the key is off. Nothing else gates the call. |
| `siem` | `reportedOnly: true` | `SiemService::deliverDue()` drains every enabled sink in `keepiq_siem_sinks`. Sinks are records, not app config. |

**Why a boolean key is honest here.** `AdminSettingsService` stores `breach_check_enabled` with `setValueBool`. Integriq's `ConnectionConfigReader::readAnyType()` reads a typed key with `getValueBool`, and since hydra#676 a `false` counts as empty. A switched-off check therefore reads Not configured, and a switched-on one reads Configured with "Required settings are filled." until the first lookup reports. Verified against `integriq/lib/Service/ConnectionConfigReader.php` on `development`.

**Why no adapter on `hibp`.** The upstream is a fixed constant, `https://api.pwnedpasswords.com/range/`. There is no mock to select, so rule 3 has nothing to read.

**Why one row for every sink.** A static file cannot list records an admin adds at runtime. D12 names SIEM sinks as the example of a family, and says to declare one row and report on it.

**Anchors.** The admin section id is `keepiq` (`Sections\SettingsSection`, bound to OpenRegister's `GenericSettingsSection`), so each link is `/settings/admin/keepiq#section-...`. `BreachCheckSection.vue` and `SiemSection.vue` put the id on their `CnSettingsSection`, which passes it to the `NcSettingsSection` root through `v-bind="$attrs"`.

**No vault data.** A row holds a status, a message and a host. It holds no secret, entry name, folder or user id, so it stays inside the `integration-boundary` capability, which forbids vault data in OpenRegister objects.

## D2. What Keepiq reports, and when

`lib/Service/Connection/ConnectionReporter.php` sends both events. It names the classes by string behind `class_exists` (ADR-041) and never throws. `lib/Service/Connection/ConnectionObservations.php` maps an outcome to a status and a message. It is pure, so every mapping is testable without a double.

**Breach check, on an admin settings save** (`PUT /api/settings/admin` with `breach_check_enabled`). A refresh for `hibp` and no report. Integriq reads the switch itself.

**Breach check, after a range lookup that reached the upstream.** A cache hit makes no call and reports nothing. The 401, 403 and 400 answers happen before any call and report nothing.

| The upstream | Status | Message |
|---|---|---|
| answered 2xx | `configured` | "The last range lookup reached Have I Been Pwned." |
| answered 429 | `limited` | "Have I Been Pwned limited the last range lookup (HTTP 429)." |
| answered anything else | `error` | "Have I Been Pwned answered HTTP {n} on the last range lookup." |
| did not answer | `error` | "The last range lookup got no answer from Have I Been Pwned." |

**SIEM, on a sink create, change or delete.** A refresh for `siem`. When no enabled sink is left, a report `unconfigured`: "No SIEM sink is switched on. Add one under SIEM audit export." Otherwise the refresh alone, so the row reads the declared "Not checked yet" until the next drain delivers.

**SIEM, after a drain** (`DeliverSiemEventsJob`, every 60 seconds). The report looks only at sinks the drain tried to deliver to in this run. A sink's older `lastDeliveryStatus` is not used: it would bring back an error from before a save, which is exactly what hydra#674 retires.

| Sinks the drain delivered to | Status |
|---|---|
| none, and no sink is enabled | `unconfigured` |
| none, while sinks are enabled | nothing |
| all took it | `configured` |
| some took it | `limited`, naming the first host that failed |
| none took it | `error`, naming the first host that failed |

**What a message may carry.** The breach check reporter takes only an HTTP status, as `?int`. A hash prefix cannot reach it by type. The SIEM mapper takes a host, derived with `parse_url(..., PHP_URL_HOST)`: a webhook URL as is, a syslog `host:port` behind `tcp://`. A value with no host is left out of the message, which then reads "The SIEM sink took the last delivery." Neither ever reads an exception message, because a Guzzle exception names the full request URL, and on a range lookup that URL ends in the prefix.

**Throttle.** The reporter remembers the last status and time per key in the app-config key `connection_report_{key}`. The same status goes out again after an hour. A different status waits five minutes after the last report, so an upstream that flips cannot report on every lookup. A save deletes the memory for its key, so the first outcome after a save is reported at once. The copy is the one buildiq#777 uses.

**Why this is cheap (ADR-076).** A lookup reports only on a cache miss, and then reads one in-memory app-config value. The drain runs from cron, never from a page request. A save is an admin action.

**Wiring.** `SettingsController` is built by hand in `DomainOverrideRegistrar::register()`, which `Application::register()` calls. That factory passes the reporter by name. `BreachProxyController`, `SiemService` and `SiemSinkService` are autowired, so each takes the reporter as an optional last argument.

## D3. The page

- `src/manifest.d/80-connection-registry.json`: an `index` page `Integrations` at `/settings/integrations`, `requiresApp` integriq, `permission: admin`, `showAdd: false`, and the columns connection, status, status message, last checked and settings.
- Its menu entry `IntegrationsMenu` sits in the settings gear with `query: {app: keepiq}`, `permission: admin` and `visibleIf.appInstalled: integriq`.
- `src/services/connectionRegistry.js` holds the two formatters and `openIntegriqConnections`.
- `App.vue` passes the formatters through CnAppRoot's `formatters` prop, and merges the handler into the `customComponents` it passes, because CnIndexPage resolves a header action's handler against `customComponents`. It passed no formatters before this change.

**Keepiq's own navigation rail.** Keepiq renders `KeepiqAppNav` in CnAppRoot's `#menu` slot, because CnAppNav cannot draw the vault folder tree. That rail read only `route`, `href` and `action`. It dropped `query`, so the menu would have opened the page with no preset and listed every app's rows. It also ignored `permission` and `visibleIf`, so the entry would have shown to every user and without integriq. `src/utils/navEntries.js` now holds both rules, taken from CnAppNav: `menuEntryTo()` passes `query` into the route, and `isMenuEntryVisible()` checks `visibleIf.appInstalled` against `OC.appswebroots` and `permission: admin` against the instance admin flag. No existing entry declares either field, so nothing else in the rail changes.

**Why `/settings/integrations` does not break ADR-004.** The rule forbids routing an admin settings component, such as `AdminRoot.vue`, inside the app. This route renders a CnIndexPage over integriq's `app_connection`, whose schema grants read access to admins only. The admin settings themselves stay in `AdminSettings.php`. The `hydra-gate-admin-router` check reads `src/router/index.js`, which Keepiq does not have: routes come from the manifest.

**Formatters.** The installed `@conduction/nextcloud-vue` 2.41.1 ships no `connectionStatus` built-in, so Keepiq carries a local copy with all six labels, `limited` included.

## D4. Contract misfits

- **The navigation slot.** D8 assumes CnAppNav reads the menu entry. An app that fills CnAppRoot's `#menu` slot with its own rail gets none of `query`, `permission` or `visibleIf` for free. Keepiq fixed its rail. Other apps with a custom rail need the same check.
- **A family row with a test button.** SIEM has a per-sink test-fire. Its outcome says nothing about the other sinks, so it does not report. The contract has no per-record status.
- **A report that needs a user action.** `hibp` reports only when a user runs a check. On an instance where nobody checks, the row keeps "Required settings are filled." indefinitely. Rule 5 claims only what integriq can see, so the message stays honest, but it never proves the upstream answers.
- **The vault lock.** Every routed Keepiq page, this one included, sits behind the master password. An admin with a locked vault meets the lock screen before the Integrations page.

## Risks

- **Same-second ordering.** A sink save sends the refresh before the report. Hydra#674 compares with "not older than", so an equal stamp counts.
- **A SIEM row can lag.** A drain with nothing queued reports nothing, so the row keeps the last outcome until an event is forwarded.
