# Keepiq audit events in Microsoft Sentinel

Keepiq posts each sanitized audit event as one row to the Azure Monitor Logs
Ingestion API. This template creates the table `KeepiqAudit_CL` and the data
collection rule with the stream `Custom-KeepiqAudit` that receive them.

## Set it up

1. Create a data collection endpoint in the region of your Sentinel workspace,
   or reuse one. Note its logs ingestion URL and its resource id.
2. Deploy the template:

   ```sh
   az deployment group create --resource-group <rg> --template-file keepiq-dcr.json \
     --parameters workspaceName=<workspace> dataCollectionEndpointResourceId=<dce resource id>
   ```

   The output `dataCollectionRuleImmutableId` is the rule id Keepiq needs.
3. Register an application in Microsoft Entra ID and create a client secret.
4. Give that application one role on the new data collection rule only:
   **Monitoring Metrics Publisher**. It needs nothing else.
5. In Nextcloud, open the Keepiq admin settings, add a SIEM sink, pick
   **Microsoft Sentinel**, and fill in the logs ingestion URL, the tenant id,
   the client id, the rule's immutable id and the client secret. Press
   **Test**.

## What arrives

| Column | From |
|---|---|
| `TimeGenerated` | the event time |
| `EventType` | for example `suite.revoked` |
| `Category` | the part before the dot |
| `ActorType`, `ActorId` | who did it |
| `ObjectType`, `ObjectId` | what it was done to |
| `Metadata` | the whitelisted metadata (dynamic) |

No secret value, login, additional field, ciphertext or key ever arrives.
When Keepiq adds a column, update the template too; a Keepiq test keeps the
template and the sender in step.
