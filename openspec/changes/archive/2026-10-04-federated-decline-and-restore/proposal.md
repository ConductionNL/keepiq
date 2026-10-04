---
kind: code
---

# Decline a pending federated share, take a deleted copy back, and refuse grade changes visibly

## Why

Federated sharing (archived 4 Oct 2026, keepiq#789) tells the owner when a recipient deletes an accepted copy: the owner's share shows "declined" and stops sending. Two edges stayed open, and Ruben decided both on 4 Oct 2026:

- Declining a share that is still pending under "Incoming from other organisations" changes only the recipient's row. The owner's share stays active and keeps sending updates that the recipient's server drops.
- Restoring a deleted copy from the trash gives back a read-only copy whose share is declined on both sides. It never follows the owner again, and nothing says so.

The live lanes also found two small mechanics:

- The offboarding summary says "Removed the user from 1 team folders."
- A viewer or editor who changes another member's grade gets a 400 bad request, while folder-permission-grades asks for a forbidden response.

## What Changes

- Declining a pending incoming share sends OCM `SHARE_DECLINED` to the owner's instance, the same signed notification with the shared secret that deleting an accepted copy sends. The owner's share shows "declined" at once and stops retrying.
- Restoring a declined read-only copy from the trash sends the standard OCM `SHARE_ACCEPTED` to the owner's instance. When the owner's instance takes it, the inbound share is accepted again, the owner's share leaves "declined" and resumes updates, and the copy pulls the current value. When the owner revoked or replaced the share meanwhile, the copy comes back read-only as it was and the restore says the share has ended.
- The restore endpoint adds `federatedShare` (`resumed`, `ended` or `unreachable`) to its answer for such a copy, and the restore dialog says when a share ended or the owner's organisation could not be reached.
- The offboarding summary uses a real plural.
- A member who is neither the owner nor a manager is refused with `error: manager_only`, delivered as HTTP 428 like `owner_only` (#1104).

## Capabilities

### Modified Capabilities

- `federated-sharing`: the read-only copy requirement covers declining a pending share and restoring a deleted copy.
- `folder-permission-grades`: a viewer's or editor's grade change is refused as forbidden with HTTP 428 and `error: manager_only`.

## Impact

- `lib/Service/FederatedInboundService.php`, `FederatedCopyDeclineService.php`, `FederatedDeclineReceiver.php`, `SecretTrashService.php`, `lib/Federation/KeepiqSecretFederationProvider.php`, `lib/Controller/SecretTrashController.php`.
- `lib/Service/TeamFolderQueryService.php`, `lib/Controller/TeamFolderMemberController.php`, new `lib/Exception/ManagerOnlyException.php`.
- `src/dialogs/BulkStateDialog.vue`, `src/store/modules/secret.js`, `src/components/settings/OffboardingSection.vue`, `src/utils/auditEventLabels.js`.
- New audit event `federated_share.recipient_resumed`. No schema change.
