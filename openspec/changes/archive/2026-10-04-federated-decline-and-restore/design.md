## Context

Since #1092 a recipient who trashes or purges an accepted read-only copy marks the inbound share declined and sends OCM `SHARE_DECLINED`, signed by the recipient's instance and carrying the shared secret. The owner's instance keeps only the secret's SHA-256, checks it, checks that the signer is the recipient's partner, and marks its share declined.

## Decisions

### D1: A pending decline uses the same notification

Declining a pending share calls the same `FederatedCopyDeclineService::tellOwner()` that the trash uses. The owner's side does not care whether a copy ever existed: a declined share is declined. A lost decline heals the same way, because the owner's next `SHARE_UPDATED` for a declined inbound row repeats it.

### D2: Taking a share back is the standard OCM `SHARE_ACCEPTED`

The OCM specification defines `SHARE_ACCEPTED` as the recipient telling the sender that it accepted a share. Restoring a declined copy is exactly that, so no Keepiq-specific notification type is needed. The message goes through the same signed channel as the decline: the recipient's instance signs it, the payload carries the shared secret and the share id, and `KeepiqSecretFederationProvider::getFederationIdFromSharedSecret()` names the recipient as the signer. `FederatedDeclineReceiver::resume()` applies it under the same checks as a decline.

The owner's side resumes only a share that is `declined`. A share that is already active answers success and stays as it is. A share that was revoked, suspended or failed, one the owner already removed, and one whose secret the owner shared again with the same recipient after the decline all answer "share not found". Reviving the old one next to a newer live share would give the recipient two live shares for one secret.

### D3: The recipient follows the owner's answer, and never invents access

The recipient's instance takes the HTTP status as the answer:

| Owner's answer | Recipient's inbound share | Copy | Restore says |
|---|---|---|---|
| 201 | accepted | pulls the current value | `resumed` |
| any other 4xx (share not found, signature refused) | revoked | stays read-only with its last value | `ended` |
| no answer, or 5xx | stays declined | stays read-only with its last value | `unreachable` |

A share the recipient's instance already knows is revoked (a `SHARE_UNSHARED` arrived while the copy was in the trash) ends without asking. A pull that fails after a 201 leaves the share accepted; the owner's next change pulls again.

### D4: The trash keeps the link to the copy

`copyDeleted()` used to clear the inbound row's `secret_id`. A restore needs it to find the share, so the trash now keeps it and only a purge (`copyPurged()`) clears it. Copies declined before this change have no link; restoring one says the share has ended, which is true from the recipient's side.

### D5: Grade refusals

`TeamFolderQueryService::loadManageableTeamFolder()` throws `ManagerOnlyException` (an `InvalidArgumentException`) for a member who is neither owner nor manager. `TeamFolderMemberController` answers it as 403 with `error: manager_only`, and `OcsRefusalMiddleware` delivers that as 428, as it does for `owner_only`. Other callers still treat it as an invalid request, as before.

## Risks / Trade-offs

- A signature problem between the two instances answers 400, which the recipient reads as "ended". The copy stays read-only either way, so the risk is a wrong message, not wrong access.
- `unreachable` leaves the share declined. The recipient can trash and restore the copy again once the owner's organisation is back.
