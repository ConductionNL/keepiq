## ADDED Requirements

### Requirement: Named SIEM connectors on a sink

The system MUST let an administrator create a SIEM sink with one of these connectors: `splunk_hec` (Splunk HTTP Event Collector), `sentinel` (Microsoft Sentinel through the Azure Monitor Logs Ingestion API), or a `syslog` sink with `format` `cef`. Existing `syslog` and `webhook` sinks with `format` `json` MUST behave exactly as before. A `format` of `cef` MUST be refused on any sink that is not `syslog`.

#### Scenario: Administrator creates a Splunk connector

- **GIVEN** an administrator on the SIEM section of the Nextcloud admin settings
- **WHEN** they call `POST /api/v1/siem/sinks` with `type` `splunk_hec`, an `https://` HEC endpoint and a HEC token
- **THEN** the sink MUST be stored as enabled and eligible for delivery
- **AND** the response MUST report `hasCredential` true and MUST NOT contain the token

#### Scenario: CEF on a webhook is refused

- **GIVEN** an administrator creating a sink
- **WHEN** they call `POST /api/v1/siem/sinks` with `type` `webhook` and `format` `cef`
- **THEN** the system MUST reject the request with a bad-request response
- **AND** no sink MUST be stored

#### Scenario: An existing syslog sink is unchanged

- **GIVEN** a `syslog` sink created before this change
- **WHEN** the migration runs and the next audit event is delivered
- **THEN** the sink MUST report `format` `json`
- **AND** the delivered message MUST be the same JSON payload as before the change

### Requirement: Splunk HTTP Event Collector delivery

The system MUST deliver to a `splunk_hec` sink by posting one event per request to the configured endpoint with the header `Authorization: Splunk <token>` and a body carrying `time`, `host`, `source` `keepiq`, `sourcetype` (default `keepiq:audit`), an optional `index`, and the sanitized payload as `event`. Delivery MUST count as successful only on HTTP 200 with a response `code` of 0; any other outcome MUST enter the existing retry and dead-letter path.

#### Scenario: Accepted event

- **GIVEN** an enabled `splunk_hec` sink and a queued audit event
- **WHEN** the delivery job posts the event and Splunk answers HTTP 200 with `code` 0
- **THEN** the queue item MUST be marked delivered and the sink's last delivery status MUST be `ok`

#### Scenario: Rejected token

- **GIVEN** an enabled `splunk_hec` sink whose token Splunk rejects
- **WHEN** the delivery job posts a queued event and Splunk answers HTTP 403
- **THEN** the item MUST be scheduled for retry with backoff
- **AND** after the retry ceiling it MUST be dead-lettered and an administrator notification MUST be raised

### Requirement: Microsoft Sentinel delivery through the Logs Ingestion API

The system MUST deliver to a `sentinel` sink by obtaining an Entra ID token with the client-credentials grant for scope `https://monitor.azure.com//.default` and posting the payload as a row with the columns `TimeGenerated`, `EventType`, `Category`, `ActorType`, `ActorId`, `ObjectType`, `ObjectId` and `Metadata` to `<data collection endpoint>/dataCollectionRules/<rule id>/streams/<stream>?api-version=2023-01-01`. HTTP 204 MUST count as success. The token MUST be held in process memory for one drain run only and MUST NOT be written to any cache or table. A 401 MUST clear the token and retry once in the same run.

#### Scenario: One token serves a drain run

- **GIVEN** an enabled `sentinel` sink with two queued events
- **WHEN** the delivery job drains the queue
- **THEN** the system MUST request exactly one token
- **AND** it MUST post two rows, each accepted with HTTP 204

#### Scenario: Expired token is refreshed once

- **GIVEN** a drain run whose cached token has expired at Entra ID
- **WHEN** the stream post answers HTTP 401
- **THEN** the system MUST request a new token and retry the post once
- **AND** a second 401 MUST enter the normal retry path

### Requirement: CEF formatting over syslog

The system MUST send, for a `syslog` sink with `format` `cef`, a message body of the form `CEF:0|Conduction|Keepiq|<app version>|<eventType>|<event name>|<severity>|<extensions>` inside the existing RFC 5424 frame. Header fields MUST escape `\` and `|`. Extension values MUST escape `\`, `=` and line breaks. Severity MUST come from a fixed map keyed on the event category.

#### Scenario: CEF line reaches QRadar

- **GIVEN** a `syslog` sink with `format` `cef` pointing at a QRadar syslog listener
- **WHEN** an administrator force-revokes a suite and the `suite.revoked` event is delivered
- **THEN** the listener MUST receive one octet-framed RFC 5424 message whose MSG starts with `CEF:0|Conduction|Keepiq|`
- **AND** the severity field MUST be the value the map assigns to the `suite` category

#### Scenario: A pipe in a value cannot break the header

- **GIVEN** an audit event whose whitelisted metadata contains the characters `|` and `=`
- **WHEN** the event is formatted as CEF
- **THEN** every `|` in a header field MUST be escaped as `\|`
- **AND** every `=` in an extension value MUST be escaped as `\=`

### Requirement: Connector credentials are write-only and encrypted at rest

The system MUST store the Splunk HEC token and the Sentinel client secret encrypted with Nextcloud `ICrypto` in `credential_enc`, MUST never return either in any API response, audit entry, log line or payload, and MUST keep the stored value when an update supplies a blank credential. Non-credential connector settings (tenant id, client id, data collection endpoint, rule id, stream, index, sourcetype) MAY be stored and returned in plain text.

#### Scenario: Reading a sink never reveals its credential

- **GIVEN** a `sentinel` sink with a stored client secret
- **WHEN** an administrator calls `GET /api/v1/siem/sinks`
- **THEN** the sink entry MUST include `hasCredential` true and the tenant and client ids
- **AND** it MUST NOT include the client secret or its ciphertext

#### Scenario: Blank credential on update keeps the stored one

- **GIVEN** a `splunk_hec` sink with a stored token
- **WHEN** an administrator calls `PUT /api/v1/siem/sinks/{id}` with a new index and an empty credential
- **THEN** the index MUST change and the stored token MUST remain in effect

### Requirement: Connector output carries no secret material

The system MUST build every connector's output only from the sanitized payload that `SiemService::buildPayload()` returns plus fixed vendor constants. No connector MUST add a secret value, login, password, additional field, ciphertext or key material, and a forbidden metadata key MUST never reach any connector's output.

#### Scenario: A planted forbidden key is dropped for every connector

- **GIVEN** an audit event whose metadata contains a `value` key
- **WHEN** the event is formatted for JSON, CEF, Splunk HEC and Sentinel
- **THEN** none of the four outputs MUST contain the `value` key or its content

### Requirement: Receiving-side templates ship with the app

The system MUST ship an Azure Resource Manager template under `integrations/siem/sentinel/` that creates the `KeepiqAudit_CL` table and the data collection rule with stream `Custom-KeepiqAudit`, and a `props.conf` under `integrations/siem/splunk/` that defines the `keepiq:audit` sourcetype. The Sentinel template's column list MUST match the columns the Sentinel formatter sends.

#### Scenario: Template and formatter agree

- **GIVEN** the Sentinel template in `integrations/siem/sentinel/keepiq-dcr.json`
- **WHEN** the test suite compares its table columns with the Sentinel formatter's output keys
- **THEN** the two lists MUST be identical
