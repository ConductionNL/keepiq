# Keepiq audit events in Splunk

Keepiq posts each sanitized audit event to the HTTP Event Collector (HEC)
with sourcetype `keepiq:audit`.

## Set it up

1. Copy `props.conf` into an app on your search heads and indexers, for
   example `$SPLUNK_HOME/etc/apps/keepiq/local/props.conf`.
2. Create an index for the events, for example `keepiq`.
3. Create a HEC token that may write to that one index only, with source type
   `keepiq:audit`. Do not give it more indexes.
4. In Nextcloud, open the Keepiq admin settings, add a SIEM sink, pick
   **Splunk HTTP Event Collector**, and fill in
   `https://<splunk-host>:8088/services/collector/event`, the token and the
   index. Press **Test**.

Search with `index=keepiq sourcetype=keepiq:audit`. The event fields are
`eventType`, `category`, `actorType`, `actorId`, `objectType`, `objectId`,
`occurredAt` and `metadata.*`. No secret value, login, additional field,
ciphertext or key ever arrives.
