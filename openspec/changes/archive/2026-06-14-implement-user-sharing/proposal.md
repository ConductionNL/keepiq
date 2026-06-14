## Why

With EncryptionSuites (implement-encryption-suites) and Secrets (implement-secrets) in place, Doriath can store encrypted secrets but users cannot share them. Sharing is the primary collaboration feature and a core differentiator over competitors — it leverages Nextcloud's native users and groups to distribute secrets using E2E encryption. Without user sharing, Doriath is a single-user vault; with it, Doriath becomes a team secret manager. This is an MVP-tier blocker (features 32-40 in FEATURES.md).

## What Changes

- Implement SecretShare entity and service for user-to-user secret sharing (encrypted copy in recipient's vault)
- Implement sync-on-update: when either party modifies a shared secret, re-encrypt and propagate to all copies
- Implement GroupShare entity and service for sharing with Nextcloud groups (static expansion to individual SecretShares at share time)
- Implement new group member notification and owner approval flow via OCP\Notification\IManager
- Implement auto-revocation of group-derived shares when a user leaves a group via OCP\Group\Events\UserRemovedEvent listener
- Implement share revocation (owner deletes recipient's SecretShare and encrypted copy)
- Implement share request mechanism (recipient asks owner to share with a third party; owner approves/denies)
- Implement share visibility rules (owner sees full recipient list; recipients cannot)
- Implement EncryptionSuite revocation cascade (revoke suite -> delete all SecretShares for that user)
- Implement EncryptionSuite compromise notification (shared copy flagged during migration -> notify original owner)
- Implement SecretDelegation entity and service for ownership delegation (admin power grab, user self-delegation)
- Implement delegation reclaim (owner revokes all temporary delegations)
- Implement permanent transfer on suite revocation (temporary delegations become permanent)
- Implement Nextcloud notifications for all share events (shared, revoked, request, group member, compromise)
- Add database migrations for `doriath_secret_shares`, `doriath_group_shares`, and `doriath_secret_delegations` tables
- Add client-side sharing UI: share dialog with user/group picker, recipient list for owners, share request form

## Capabilities

### New Capabilities
- `user-sharing`: User-to-user secret sharing — create encrypted copies, sync-on-update, share visibility, share revocation, share requests, and EncryptionSuite cascade behaviors
- `group-sharing`: Nextcloud group-based sharing — static expansion to individual shares, new member notification + approval, auto-revocation on group leave, group share revocation
- `ownership-delegation`: Secret ownership delegation — admin power grab, user self-delegation, delegation reclaim, permanent transfer on suite revocation
- `share-notifications`: Nextcloud notifications for all sharing events — share received, share request, group member added, compromise notification, request outcome

### Modified Capabilities
- `secrets`: Secret entity gains awareness of shared copies — delete cascade must remove associated SecretShares; update must trigger sync-on-update propagation to shared copies
- `encryption-suites`: EncryptionSuite revocation must cascade-delete SecretShares; compromise migration must notify original owners of shared copies flagged as possibly compromised

## Impact

- **Database**: Three new tables (`doriath_secret_shares`, `doriath_group_shares`, `doriath_secret_delegations`) via ISchemaWrapper migrations, with indexes per ARCHITECTURE.md
- **Backend**: New entities (SecretShare, GroupShare, SecretDelegation), mappers, services (ShareService, GroupShareService, DelegationService, NotificationService), controllers (ShareController, GroupShareController, DelegationController), event listeners (UserAddedToGroupListener, UserRemovedFromGroupListener, EncryptionSuiteRevokedListener)
- **Frontend**: New Pinia store (useShareStore), Vue components (ShareDialog, RecipientList, ShareRequestForm, GroupShareManager), modifications to SecretDetail.vue for share visibility and delegation UI
- **API**: REST endpoints for share CRUD, group share CRUD, delegation CRUD, share request submission/resolution, recipient list
- **Dependencies**: Depends on implement-encryption-suites (EncryptionSuite entity, crypto services, session store) and implement-secrets (Secret entity, SecretService, SecretMapper)
- **Cross-app**: Sharing is client-side E2E (ADR-003) — the browser decrypts with own private key, fetches recipient's public certificate, encrypts for recipient, and POSTs the encrypted blob. Server never sees plaintext during sharing operations. This means sync-on-update is O(N) RSA operations in the browser.
- **Nextcloud integration**: OCP\Notification\IManager for all share notifications, OCP\Group\Events for group membership change listeners, OCP\IGroupManager for group member enumeration
- **Security**: Share requests prevent uncontrolled re-sharing. Share visibility ensures recipients cannot enumerate other recipients. Delegation requires pre-existing share (cryptographic constraint — cannot decrypt without a copy). All shared copies are independently encrypted — compromising one recipient's suite does not expose the original or other copies.
