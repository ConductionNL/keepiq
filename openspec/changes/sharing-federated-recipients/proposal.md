---
kind: code
---

# Share a secret with a user on another Nextcloud instance

## Why

Keepiq shares only with users and groups on the same Nextcloud instance. A municipality that works with a partner organisation on its own Nextcloud has to fall back to a password-protected public link, a snapshot that does not follow later changes and is not tied to a person. Nextcloud already federates files between instances through Open Cloud Mesh (OCM); Keepiq does not use it.

| Row | Capability | What Keepiq does today |
|---|---|---|
| sharing-17 | Share with someone outside the organisation who has their own account elsewhere. | Sharing with an account on a different Nextcloud instance is not supported; a password-protected public link (sharing-14) is the closest substitute. |

Matrix: keepiq `openspec/parity/capabilities.json`

Not built. Every sharing path targets a local user, a local group, or an anonymous link (`lib/Controller/ShareController.php`, `GroupShareController.php`, `LinkShareController.php`). The only federated cloud id in the code names a certificate's common name (`lib/Service/EncryptionSuiteProvisioningService.php:331`); nothing registers an OCM provider.

### Demand

No demand row.

### Competitors rated yes

- 1Password: "https://support.1password.com/share-items/ : recipient can save the shared item into their own 1Password account; guest accounts in https://support.1password.com/custom-groups/"
- Keeper: "https://docs.keeper.io/enterprise-guide/roles/enforcement-policies#creating-and-sharing : policies 'Share to users outside of the enterprise' and 'Receive items from users outside of the enterprise'"

## What Changes

- Administrators list trusted partner instances, pin each partner's Keepiq root certificate fingerprint, and allow outbound, inbound or both. Federation is off until a partner is added.
- A vault owner can share a secret with a federated cloud id (`bob@cloud.partner.example`). Their browser fetches Bob's certificate through their own server from the partner, checks it against the pinned root, shows its fingerprint, and encrypts the secret for Bob. Only ciphertext leaves the browser.
- Keepiq registers an OCM resource type `keepiq-secret`. The sending server announces the share over OCM; the receiving server fetches the ciphertext with the share's shared secret and, once Bob accepts, stores it as a secret in Bob's vault.
- Updates by the owner are re-encrypted in the owner's browser for the remote recipient and announced over OCM; revocation and expiry remove the remote copy.
- Remote copies are read-only for the recipient in this change.
- Nextcloud 33 or later is required for federation, so every server-to-server call is a signed OCM request.

## Capabilities

### New Capabilities

- `federated-sharing`: trusted partner instances, federated certificate lookup, OCM share delivery, acceptance, sync, revocation, and policy.

### Modified Capabilities

None. Local sharing stays as specified in `user-sharing`.

## Impact

- **Backend**: an OCM provider registered with `OCP\Federation\ICloudFederationProviderManager::addCloudFederationProvider()`, outbound shares through `sendCloudShare()` and `sendNotification()`, partner discovery through `OCP\OCM\IOCMDiscoveryService`, request verification through `IOCMDiscoveryService::getIncomingSignedRequest()`, cloud id parsing through `OCP\Federation\ICloudIdManager`; new controllers and services for partners, federated shares and inbound shares.
- **Frontend**: a federated recipient option in the share dialog, a partner list in the admin settings, and an "Incoming from other organisations" list for accepting shares.
- **Database**: three new tables; a migration and a `<version>` bump.
- **Security**: ciphertext only between instances; certificates checked against a pinned partner root; signed OCM requests; administrator allowlists in both directions.
- **Cross-app**: none. Nextcloud's own federated file sharing is untouched.
