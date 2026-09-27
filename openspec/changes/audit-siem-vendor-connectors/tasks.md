# Tasks: SIEM vendor connectors

## 1. Data model

- [ ] 1.1 Add a migration step that adds `format` (default `json`), `credential_enc` and `connector_options` to `keepiq_siem_sinks`, and bump `<version>` in `appinfo/info.xml`. Verify: a PHPUnit migration test asserts the three columns and that an existing sink reads back `format` `json`.
- [ ] 1.2 Extend `SiemSink` with the three fields; `jsonSerialize()` returns `format`, `connectorOptions` and `hasCredential` but never the credential. Verify: PHPUnit `SiemSinkTest` asserts no serialized key holds the credential value.
- [ ] 1.3 Extend `SiemSinkService` validation: accept `splunk_hec` and `sentinel` as `type`, require `https://` endpoints for them, require the Sentinel options, allow `format` `cef` only on `syslog`, and encrypt a supplied credential with `ICrypto` (blank keeps the stored one). Verify: PHPUnit `SiemSinkServiceTest` covers each accept and reject path.

## 2. Formatters

- [ ] 2.1 Add `lib/Service/Siem/JsonFormatter` (today's output, unchanged) and `CefFormatter` with header and extension escaping and the category severity map. Verify: PHPUnit covers escaping of `|`, `\`, `=` and line breaks, and one line per category.
- [ ] 2.2 Add `SplunkHecFormatter` (event envelope with time, host, source, sourcetype, optional index) and `SentinelRowFormatter` (the D3 columns). Verify: PHPUnit snapshots of both outputs for a fixed payload.
- [ ] 2.3 Add a guard test that feeds every formatter a payload holding a planted forbidden key and asserts the output carries only payload-derived values and fixed vendor constants. Verify: the PHPUnit test fails when a formatter reads outside the payload.

## 3. Transports

- [ ] 3.1 Route `syslog` sinks with `format` `cef` through `CefFormatter` in `SiemTransport::deliverSyslog()`. Verify: PHPUnit with a local TCP listener reads back an octet-framed RFC 5424 line whose MSG starts with `CEF:0|Conduction|Keepiq|`.
- [ ] 3.2 Add Splunk HEC delivery: `Authorization: Splunk <token>`, success only on HTTP 200 with response `code` 0. Verify: PHPUnit with a mocked `IClientService` covers success, a non-2xx, and a 200 with a non-zero `code` entering the retry path.
- [ ] 3.3 Add Sentinel delivery: client-credentials token request, per-run token reuse, post to the stream URL, 204 as success, one retry after a 401. Verify: PHPUnit asserts one token request for two deliveries in a run, and the retry-once behaviour.
- [ ] 3.4 Make test-fire work for every connector through the existing `SiemService::testSink()`. Verify: PHPUnit asserts the outcome message per connector.

## 4. Admin interface

- [ ] 4.1 Add a connector picker to `src/components/settings/SiemSection.vue` (Splunk HTTP Event Collector, Microsoft Sentinel, CEF over syslog, syslog JSON, webhook JSON) with only the fields each connector needs and a write-only credential field. Verify: vitest asserts the field set per connector and that the credential field is never pre-filled.
- [ ] 4.2 Add a Playwright flow: an administrator creates a Splunk HEC sink on the SIEM section of Nextcloud admin settings, runs test-fire against an unreachable endpoint and sees the failure outcome. Verify: the Playwright spec passes in the E2E job.

## 5. Receiving side

- [ ] 5.1 Add `integrations/siem/sentinel/keepiq-dcr.json` (custom table `KeepiqAudit_CL` and the data collection rule) with a README. Verify: a PHPUnit test compares the template's column list with `SentinelRowFormatter`; manual check with `az deployment group what-if` against a test workspace.
- [ ] 5.2 Add `integrations/siem/splunk/props.conf` for the `keepiq:audit` sourcetype with a README. Verify: manual check in a Splunk development container that a test-fire event lands with parsed fields.
- [ ] 5.3 Document each connector's setup and least-privilege credential in `docs/`. Verify: manual review against the writing rules.

## 6. Audit

- [ ] 6.1 Add the connector type to the sink create and update audit metadata (identifiers only). Verify: PHPUnit asserts the audit metadata holds the type and never the credential.

## Acceptance criteria

- An administrator can create a Splunk HEC, Microsoft Sentinel or CEF syslog sink from the SIEM section, and existing syslog and webhook sinks keep working unchanged.
- Every connector sends only values derived from `SiemService::buildPayload()` plus fixed vendor constants.
- The HEC token and the Sentinel client secret are encrypted at rest, never returned by the API, and never written to a log or audit entry.
- A failed connector delivery enters the existing retry, dead-letter and notification path.
- The Sentinel template and the Sentinel formatter carry the same columns.
