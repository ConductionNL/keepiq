---
kind: code
---

# Ready-made Splunk, Microsoft Sentinel and CEF connectors for the SIEM export

## Why

Keepiq already streams its sanitized audit events to a SIEM, but only as a generic syslog line or a generic signed webhook. An administrator who runs Splunk or Microsoft Sentinel has to build the receiving side by hand: a collector, a parser and a table. The three competitors that rate yes ship named connectors instead.

| Row | Capability | What Keepiq does today |
|---|---|---|
| audit-14 | Use ready-made connectors for Splunk, Microsoft Sentinel or similar tools. | No named connectors or vendor formats; Splunk, Sentinel and similar tools can ingest the generic syslog or webhook stream, but the admin has to configure the receiving side. |

Matrix: keepiq `openspec/parity/capabilities.json`

This row is partial. What is built: the generic RFC 5424 syslog transport (`lib/Service/SiemTransport.php:100`) and the generic HMAC-signed HTTPS webhook (`lib/Service/SiemTransport.php:152`), with queueing, retry, dead-lettering and test-fire. The missing half, from the decision: named Splunk, Microsoft Sentinel and CEF presets on the SIEM export.

### Demand

No demand row.

### Competitors rated yes

- Bitwarden: "bitwarden/clients@web-v2026.9.0 bitwarden_license/bit-web/src/app/dirt/organization-integrations/organization-integrations.resolver.ts:182 Microsoft Sentinel, :188 Rapid7, :195 Elastic, :201 Panther, :207 Sumo Logic, :228 Splunk (HEC, flag EventManagementForSplunk), :263 CrowdStrike and Datadog (flag); bitwarden/server@v2026.9.1 src/Core/Dirt/Enums/IntegrationType.cs:9 Hec, :10 Datadog ... Integrations page with SIEM connectors"
- 1Password: "https://support.1password.com/events-reporting/ : Splunk, Microsoft Sentinel, Datadog, Elastic, CrowdStrike and more (Business)"
- Keeper: "https://docs.keeper.io/enterprise-guide/event-reporting : built-in SIEM connectors for Splunk, Microsoft Sentinel, QRadar, Elastic, Datadog, Sumo Logic and more"

## What Changes

- A SIEM sink gets a connector choice. Next to the existing `syslog` and `webhook` transports, an administrator can pick `splunk_hec` (Splunk HTTP Event Collector) or `sentinel` (Microsoft Sentinel through the Azure Monitor Logs Ingestion API).
- A syslog sink gets a `format` choice: `json` (today's behaviour) or `cef` (ArcSight Common Event Format). CEF covers QRadar, ArcSight and Sentinel's own CEF connector through the Azure Monitor Agent.
- Each connector maps the same sanitized payload that `SiemService::buildPayload()` already builds. No connector adds a field that the audit whitelist does not carry.
- Connector credentials (the Splunk HEC token and the Sentinel client secret) are encrypted at rest with Nextcloud's `ICrypto` and are write-only, exactly like the webhook HMAC secret today.
- The admin SIEM section shows a connector picker with only the fields that connector needs.
- Keepiq ships receiving-side templates under `integrations/siem/`: an Azure Resource Manager template for the Sentinel data collection rule and custom table, and a Splunk `props.conf` for the `keepiq:audit` sourcetype.

## Capabilities

### New Capabilities

- `siem-vendor-connectors`: named Splunk HEC, Microsoft Sentinel and CEF connectors on a SIEM sink, their credential handling, payload mapping and receiving-side templates.

### Modified Capabilities

None. The generic syslog and webhook behaviour of `siem-audit-export` stays as specified; this change adds requirements in its own capability.

## Impact

- **Backend**: `SiemSink` gains `format`, `credentialEnc` and `connectorOptions`; `SiemSinkService` validates each connector; `SiemTransport` gains a Splunk HEC and a Sentinel delivery path and a CEF formatter for syslog; new formatter classes under `lib/Service/Siem/`.
- **Frontend**: `src/components/settings/SiemSection.vue` gets a connector picker and per-connector fields.
- **Database**: three new nullable or defaulted columns on `keepiq_siem_sinks`; a new migration step and a `<version>` bump.
- **Security**: no secret material enters any payload; the new credentials are server-held integration credentials, not vault secrets, and follow the HMAC secret's write-only rule.
- **Cross-app**: none. OpenConnector is not involved.
