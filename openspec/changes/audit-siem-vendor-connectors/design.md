# Design: SIEM vendor connectors

## Context

The SIEM export is built and specified in `openspec/specs/siem-audit-export/spec.md`. The code this change touches, at development `4c214a9d`:

- `lib/Service/SiemTransport.php:81` `deliver()` is the single place the transport is chosen: `syslog` goes to `deliverSyslog()` (`:100`, RFC 5424 with RFC 6587 octet framing, PRI 134, the JSON payload as MSG) and everything else to `deliverWebhook()` (`:152`, HTTPS POST with an `X-Keepiq-Signature` HMAC header, the secret decrypted from `hmacSecretEnc` with `ICrypto` at `:156`).
- `lib/Service/SiemService.php:110` `buildPayload()` rebuilds each audit event through `AuditEventTypes::WHITELIST` and drops every `AuditEventTypes::FORBIDDEN_KEYS` entry (`lib/Event/Audit/AuditEventTypes.php:173`). The payload keys are `eventType`, `category`, `actorType`, `actorId`, `objectType`, `objectId`, `occurredAt` and `metadata`.
- `lib/Service/SiemService.php:237` `deliverOne()` drains one queued item per call; `lib/BackgroundJob/DeliverSiemEventsJob.php` runs the drain.
- `lib/Service/SiemSinkService.php:93` accepts only `syslog` or `webhook` as `type`, and `:102` requires `https://` for webhooks.
- `lib/Db/SiemSink.php:109` holds `hmacSecretEnc`; `jsonSerialize()` (`:265`) only reports `hasHmacSecret` (`:273`), never the value.
- `lib/Controller/SiemSinkController.php` gates every route in-body on `IGroupManager::isAdmin()` (the `adminUid()` helper near `:69`); routes are `appinfo/routes.php:203` to `:207`.
- `src/components/settings/SiemSection.vue:140` offers the type select with `['syslog', 'webhook']`.
- The table is `siem_sinks` in `lib/Migration/Version001000Date20260908000000.php:681` (`type` is `STRING(16)`).

## Goals / Non-Goals

**Goals:**

- Three named connectors an administrator can pick: Splunk HEC, Microsoft Sentinel, and CEF over syslog.
- Every connector sends a mapping of the existing sanitized payload and nothing more.
- Connector credentials follow the webhook HMAC secret's rules: encrypted at rest, write-only, never logged.
- Receiving-side templates in the repository, so the Sentinel table and the Splunk sourcetype need no hand-built parser.

**Non-Goals:**

- Datadog, Elastic, Sumo Logic, CrowdStrike, Panther or Rapid7 presets. They accept the generic webhook or CEF today; a named preset for each is a later change if demand shows.
- Pulling events (a SIEM polling a Keepiq events API). This change stays push-only, like the existing export.
- Batching several events into one request. The queue drains one item per delivery, as today.
- Sentinel analytics rules, workbooks or Splunk dashboards.

## Decisions

### D1: Splunk and Sentinel are new transports; CEF is a format of syslog

`splunk_hec` and `sentinel` speak their own wire protocols with their own authentication, so they are new values of `type` next to `syslog` and `webhook`. CEF is not a protocol; it is a message body that SIEMs expect on a syslog stream, so it is a new `format` column (`json` default, `cef`) that only a `syslog` sink may set.

Alternative considered: one `preset` column that rewrites a webhook sink's URL and headers. Rejected: Sentinel needs an OAuth token exchange before each batch of posts, which a webhook preset cannot express, and a preset that silently changes transport behaviour is harder to test than an explicit transport.

### D2: Splunk HTTP Event Collector

The sink endpoint is the HEC URL (`https://<splunk-host>:8088/services/collector/event`; `https://` required). Keepiq posts one event per request with the header `Authorization: Splunk <token>` and the body `{"time": <epoch seconds>, "host": "<nextcloud host>", "source": "keepiq", "sourcetype": "keepiq:audit", "index": "<optional index>", "event": <payload>}`. Delivery succeeds on HTTP 200 with a response `code` of `0`; anything else is a transport failure that enters the existing retry and dead-letter path. `connectorOptions` holds the optional `index` and `sourcetype` override.

Alternative considered: Splunk's raw endpoint (`/services/collector/raw`). Rejected: the event endpoint carries time and sourcetype explicitly, so no Splunk-side timestamp extraction is needed.

### D3: Microsoft Sentinel through the Logs Ingestion API

Keepiq uses the Azure Monitor Logs Ingestion API, not the HTTP Data Collector API that Microsoft is retiring. `connectorOptions` holds `tenantId`, `clientId`, the data collection endpoint URL, the data collection rule immutable id and the stream name (default `Custom-KeepiqAudit`), plus an `authorityHost` (default `https://login.microsoftonline.com`) for sovereign clouds. The client secret is the sink credential.

Per drain run, the transport requests a token with the client-credentials grant and scope `https://monitor.azure.com//.default`, keeps it in the PHP process for that run only, and posts `[row]` to `<dce>/dataCollectionRules/<dcr-id>/streams/<stream>?api-version=2023-01-01` with `Authorization: Bearer <token>`. HTTP 204 is success. A 401 clears the cached token and retries once in the same run.

The row maps the payload one to one: `TimeGenerated` (from `occurredAt`), `EventType`, `Category`, `ActorType`, `ActorId`, `ObjectType`, `ObjectId` and `Metadata` (a dynamic column holding the whitelisted metadata object).

Alternative considered: caching the token in Nextcloud's distributed cache across runs. Rejected: a bearer token is a credential, and a cache is not an encrypted store. One token request per drain run is cheap.

### D4: CEF formatting

A `cef` syslog sink sends `CEF:0|Conduction|Keepiq|<app version>|<eventType>|<event name>|<severity>|<extensions>` as the RFC 5424 MSG. Header fields escape `\` and `|`; extension values escape `\`, `=` and line breaks, as the CEF specification requires. Extensions: `rt` (event time in epoch milliseconds), `cat` (category), `act` (event type), `suser` (actor id when the actor is a user), `cs1Label=actorType cs1`, `cs2Label=objectType cs2`, `cs3Label=objectId cs3`, and `msg` (the whitelisted metadata as compact JSON). Severity comes from a fixed map keyed on category (for example `honey` 10, `suite` 8, `emergency` 7, `share` 5, everything else 3) kept next to the formatter and covered by a test.

Alternative considered: LEEF for QRadar. Rejected for this change: QRadar parses CEF, so one format covers QRadar, ArcSight and Sentinel's CEF connector. LEEF can follow if a customer asks.

### D5: Formatters are separate, pure classes

Each output shape is a small pure class under `lib/Service/Siem/` (`JsonFormatter`, `CefFormatter`, `SplunkHecFormatter`, `SentinelRowFormatter`) that takes the `buildPayload()` array and returns a string or array. `SiemTransport::deliver()` picks the formatter and the transport. This keeps the no-secret-material rule testable in one place: a single test feeds every formatter a payload and asserts that no output value comes from anywhere but that payload and fixed vendor constants.

### D6: Receiving-side templates live in `integrations/siem/`

`integrations/siem/sentinel/keepiq-dcr.json` is an Azure Resource Manager template that creates the custom table `KeepiqAudit_CL` with the D3 columns and the data collection rule with stream `Custom-KeepiqAudit`. `integrations/siem/splunk/props.conf` defines the `keepiq:audit` sourcetype (`KV_MODE = json`, time taken from the HEC envelope). Both are copied into place by the administrator; neither is executed by Keepiq. A README in each directory lists the setup steps and the least privilege the credential needs (a HEC token scoped to one index; an Entra application with only the Monitoring Metrics Publisher role on the one data collection rule).

## Security and zero-knowledge

Nothing here touches vault content. The payload stays the sanitized audit entry: identifiers plus whitelisted metadata, never a secret value, login, additional field, ciphertext or key (ADR-003). The formatters only reshape it.

Stored encrypted (with Nextcloud `ICrypto`, the server's own key): the Splunk HEC token and the Sentinel client secret, in the new `credential_enc` column. These are integration credentials that a background job must use unattended, so the server necessarily holds them in a form it can decrypt, exactly like `hmac_secret_enc` today. They are not vault secrets, they are never returned by any API (the sink reports only `hasCredential`), and they are decrypted in memory for one request.

Stored in plain text: the connector type, the format, the endpoint URL, and `connector_options` (tenant id, client id, data collection endpoint, rule id, stream name, index, sourcetype). None of these is a credential.

The sink routes stay admin-only through the existing in-body `isAdmin()` gate. Sink lifecycle audit events add the connector type as an identifier and never the credential.

## Risks / Trade-offs

- **An administrator points a connector at an internal URL.** The endpoint is admin-configured, as for the webhook today; Keepiq requires `https://` for HEC, Sentinel and webhook endpoints and uses Nextcloud's `IClientService`, which applies Nextcloud's local-address protection.
- **Sentinel column drift.** If Keepiq adds a payload key later, the data collection rule drops it until the template is updated. The template and the formatter carry the same column list, and a test compares them.
- **CEF severity is a judgement.** The map is small and documented; an administrator who disagrees can re-map in the SIEM.
- **One token request per drain run** adds a round trip to Entra ID. Acceptable at the drain cadence.

## Seed data

None. Keepiq owns its tables (ADR-001) and has no OpenRegister register. Tests use a mocked `IClientService` and a local socket listener; no development fixture is needed.

## Migration

A new migration step adds three columns to `keepiq_siem_sinks`: `format` (`STRING(16)`, not null, default `json`), `credential_enc` (`TEXT`, nullable) and `connector_options` (`TEXT`, nullable, JSON). Existing sinks keep `type` `syslog` or `webhook` and get `format` `json`, so their behaviour does not change. The `<version>` in `appinfo/info.xml` must bump so Nextcloud runs the step.
