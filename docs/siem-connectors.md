<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# SIEM connectors

Send Keepiq's audit events straight into Splunk, Microsoft Sentinel, QRadar or ArcSight, without building a collector or a parser.
Each event carries the same sanitized metadata as the generic export: who did what to which object, never a secret value, login or ciphertext.

Add a sink in the Keepiq admin settings, under SIEM audit export, and pick a connector.

## Splunk HTTP Event Collector

1. Create an index, for example `keepiq`.
2. Create a HEC token that may write to that index only.
3. Install `integrations/siem/splunk/props.conf` for the `keepiq:audit` sourcetype.
4. Pick **Splunk HTTP Event Collector**, enter `https://<splunk-host>:8088/services/collector/event`, the token and the index.

Keepiq accepts a delivery only when Splunk answers 200 with code 0.
Anything else is retried with backoff and, after the retry ceiling, dead-lettered with an admin notification.

## Microsoft Sentinel

1. Deploy `integrations/siem/sentinel/keepiq-dcr.json`. It creates the `KeepiqAudit_CL` table and a data collection rule.
2. Register an application in Microsoft Entra ID with a client secret.
3. Give it only the Monitoring Metrics Publisher role, on that one data collection rule.
4. Pick **Microsoft Sentinel**, enter the logs ingestion URL of your data collection endpoint, the tenant id, the client id, the rule's immutable id and the client secret.

Keepiq asks Entra ID for a token once per delivery run and keeps it in memory for that run only.
The setup steps are in `integrations/siem/sentinel/README.md`.

## CEF over syslog

Pick **CEF over syslog** for QRadar, ArcSight or Sentinel's CEF connector.
Keepiq sends `CEF:0|Conduction|Keepiq|<version>|<event type>|<event name>|<severity>|…` in the same RFC 5424 frame as the JSON syslog sink.

Severity follows the event category:

| Category | Severity |
|---|---|
| honey | 10 |
| suite | 8 |
| emergency | 7 |
| share | 5 |
| any other | 3 |

## Credentials

The HEC token and the client secret are encrypted at rest and never shown again.
Leave the field blank when you edit a sink to keep the stored one.
They never appear in an API answer, a log line, an audit entry or a delivered event.

## Next step

Create the least-privilege credential for your SIEM, add the sink, and press **Test**.
