> **Build note (2026-06-10) — DEFERRED to a dedicated build cycle.**
>
> This change is an 87-task net-new build (SecretShare + GroupShare +
> SecretDelegation entities/mappers/services + ShareService +
> GroupShareService + DelegationService + share-request flow +
> NotificationService + DoriathNotifier + 4 event listeners + 4
> controllers + 6 Vue components + i18n + extensive unit/integration
> tests). It is the foundational spec that several other unbuilt
> doriath changes depend on (`implement-application-mgmt` and
> `implement-secret-requests` both reuse the NotificationService that
> ships from this change).
>
> Honest implementation requires a coordinated build cycle for the
> entity layer + service layer + controllers + frontend + tests; a
> per-task tick in this batch would either be a fabrication or would
> ship a partial graph that other open specs immediately consume.
>
> The 87 unchecked tasks below are flipped to **[~] DEFERRED** with the
> "foundation spec for the doriath sharing capability" rationale
> surfaced so a dependency-aware orchestrator can schedule the build
> cycle (recommended FIRST in the doriath unbuilt-spec dependency order,
> before application-mgmt and secret-requests).
>
> No code changes in this commit — state-tracking only.

## 1. Database Migrations and Seed Data

- [x] 1.1 Create ISchemaWrapper migration `Version000007Date20260331000006` for `doriath_secret_shares` table with columns: id (UUID PK), source_secret_id (FK to secrets), target_user_id (string), secret_id (FK to secrets), group_share_id (FK nullable to group_shares), created_at (datetime); indexes on source_secret_id, target_user_id — W14 scaffold: `lib/Migration/Version000009Date20260611000000.php` creates `doriath_share_targets` (renamed for the smallest-scaffold naming) with `id` PK + `created_by` + 3 indexes (`doriath_st_source_idx`, `doriath_st_target_idx`, `doriath_st_copy_idx`)
- [x] 1.2 Create ISchemaWrapper migration `Version000008Date20260331000007` for `doriath_group_shares` table with columns: id (UUID PK), secret_id (FK to secrets), group_id (string), created_by (string), created_at (datetime); composite index on (secret_id, group_id) <!-- W16: shipped as `lib/Migration/Version000013Date20260611000004.php` with the same shape (composite (secret_id, group_id) + (group_id) indexes) -->
- [~] 1.3 Create ISchemaWrapper migration `Version000009Date20260331000008` for `doriath_secret_delegations` table with columns: id (UUID PK), secret_id (FK to secrets), original_owner_id (string), delegated_to (string), delegated_at (datetime), initiated_by (string), is_permanent (boolean default false), made_permanent_at (datetime nullable); index on secret_id
- [~] 1.4 Create `SeedDevelopmentShares` IRepairStep (debug-only) that creates example shares between dev test users for existing dev secrets: direct shares (GitHub, AWS) and one group share (Production Database with dev-group-1), plus one SecretDelegation (dev-user-2 as delegate for GitHub); encrypts with recipients' public certificates using EncryptService server-side
- [~] 1.5 Register `SeedDevelopmentShares` as post-migration repair step in `info.xml` (debug-only condition, after SeedDevelopmentSecrets)
- [~] 1.6 Update `InitializeSettings` repair step to seed default user notification settings: notify_shares=true, notify_group_shares=true, notify_security=true

## 2. Entities and Mappers

- [x] 2.1 Create `SecretShare` Doctrine entity in `lib/Db/SecretShare.php` with all fields, JsonSerializable, and column type annotations — W14 scaffold: `lib/Db/ShareTarget.php` (entity renamed to ShareTarget; the rest of the spec's `findByGroupShare`/`findByTargetUser` shape is preserved)
- [x] 2.2 Create `SecretShareMapper` extending QBMapper in `lib/Db/SecretShareMapper.php` with methods: findById(id), findBySourceSecret(sourceSecretId), findByTargetUser(targetUserId), findByGroupShare(groupShareId), findBySourceSecretAndTargetUser(sourceSecretId, targetUserId), deleteBySourceSecret(sourceSecretId), deleteByTargetUser(targetUserId), deleteByGroupShare(groupShareId) — W14 scaffold: `lib/Db/ShareTargetMapper.php` ships `findById` / `findBySourceSecret` / `findByTargetUser` / `deleteBySourceSecret`; the remaining lookups land with the GroupShare build cycle
- [x] 2.3 Create `GroupShare` Doctrine entity in `lib/Db/GroupShare.php` with all fields and JsonSerializable <!-- W16 -->
- [x] 2.4 Create `GroupShareMapper` extending QBMapper in `lib/Db/GroupShareMapper.php` with methods: findById(id), findBySecret(secretId), findByGroup(groupId), findBySecretAndGroup(secretId, groupId), deleteBySecret(secretId) <!-- W16 -->
- [~] 2.5 Create `SecretDelegation` Doctrine entity in `lib/Db/SecretDelegation.php` with all fields and JsonSerializable
- [~] 2.6 Create `SecretDelegationMapper` extending QBMapper in `lib/Db/SecretDelegationMapper.php` with methods: findById(id), findBySecret(secretId), findActiveBySecretAndUser(secretId, userId), findByOriginalOwner(originalOwnerId), findTemporaryByOriginalOwner(originalOwnerId), deleteBySecret(secretId), makePermanentByOriginalOwner(originalOwnerId)

## 3. Services (PHP) -- Core Sharing

- [x] 3.1 Create `ShareService` in `lib/Service/ShareService.php` with methods: createShare(sourceSecretId, targetUserId, encryptedData, userId), revokeShare(shareId, userId), getSharesForSecret(sourceSecretId, userId), syncUpdate(secretId, updates, userId) — W14 scaffold: `lib/Service/ShareService.php` ships `createShare` (signature takes the pre-encrypted recipient Secret copy ID instead of raw encryptedData — the browser persists the copy first), `listSharesForSecret`, `revokeShare`, and `deleteAllForSecret` for the cascade; `syncUpdate` lands with the full build cycle
- [~] 3.2 Implement createShare: validate owner/delegate authorization, validate recipient has active EncryptionSuite, create Secret row for recipient (encrypted copy), create SecretShare record, trigger notification
- [~] 3.3 Implement revokeShare: validate owner/delegate authorization, delete recipient's Secret copy, delete SecretShare record
- [~] 3.4 Implement syncUpdate: receive array of {secret_id, encrypted_key, encrypted_login, encrypted_additional_fields}, validate caller is owner or share recipient, write all blobs in single transaction, unset possibly_compromised_at on all copies if any was set; use updated_at optimistic locking to detect concurrent updates
- [~] 3.5 Implement getSharesForSecret: return full recipient list only to owner/delegates; return empty to regular recipients
- [~] 3.6 Implement batch share creation for group expansion: createBatchShares(sourceSecretId, shares[], groupShareId, userId) that creates multiple SecretShare records in a transaction

## 4. Services (PHP) -- Group Sharing

- [~] 4.1 Create `GroupShareService` in `lib/Service/GroupShareService.php` with methods: createGroupShare(secretId, groupId, userId), revokeGroupShare(groupShareId, userId), getGroupSharesForSecret(secretId, userId), getGroupMembers(groupId)
- [~] 4.2 Implement createGroupShare: validate owner/delegate authorization, query group members via IGroupManager, exclude owner and members without active EncryptionSuite, create GroupShare record, return member list for browser-side encryption
- [~] 4.3 Implement revokeGroupShare: validate owner/delegate authorization, delete all SecretShares with matching group_share_id (cascade delete encrypted copies), delete GroupShare record
- [~] 4.4 Implement handleNewGroupMember(userId, groupId): query all GroupShares for the group, create notification to each secret owner with subject group_member_added
- [~] 4.5 Implement approveGroupMemberShare(notificationId, userId): create SecretShare for new member referencing the GroupShare, notify new member
- [~] 4.6 Implement handleMemberLeave(userId, groupId): query all SecretShares where target_user_id = userId AND group_share_id references a GroupShare for groupId, delete those SecretShares and their Secret copies

## 5. Services (PHP) -- Share Requests

- [~] 5.1 Implement submitShareRequest(sourceSecretId, targetUserId, requesterId): validate requester holds a share of the secret, create notification to owner with subject share_request and parameters (requesterId, targetUserId, sourceSecretId) stored in notification message
- [~] 5.2 Implement approveShareRequest(notificationId, ownerId): extract parameters from notification, trigger standard share flow (returns recipient info for browser-side encryption)
- [~] 5.3 Implement denyShareRequest(notificationId, ownerId): create notification to requester with subject share_request_result indicating denial

## 6. Services (PHP) -- Delegation

- [~] 6.1 Create `DelegationService` in `lib/Service/DelegationService.php` with methods: createDelegation(secretId, delegateTo, initiatedBy), reclaimDelegation(secretId, ownerId), getDelegationsForSecret(secretId), makePermanent(originalOwnerId)
- [~] 6.2 Implement createDelegation: validate initiator is owner or admin, validate delegatee holds a share of the secret, create SecretDelegation record with is_permanent=false
- [~] 6.3 Implement reclaimDelegation: validate caller is original owner, delete all temporary SecretDelegation records for the secret
- [~] 6.4 Implement makePermanent(originalOwnerId): set is_permanent=true and made_permanent_at=now on all temporary delegations where original_owner_id matches, delete original owner's Secret copies for delegated secrets

## 7. Services (PHP) -- Notifications

- [x] 7.1 Create `NotificationService` in `lib/Service/NotificationService.php` with SUBJECT_SETTING_MAP constant mapping subjects to user setting keys (secret_shared -> notify_shares, share_request -> notify_shares, share_request_result -> notify_shares, group_member_added -> notify_group_shares, secret_compromised -> notify_security)
- [x] 7.2 Implement notify(subject, recipientId, params): check user preference via SUBJECT_SETTING_MAP, create and dispatch notification via OCP\Notification\IManager
- [x] 7.3 Create `DoriathNotifier` implementing OCP\Notification\INotifier in `lib/Notification/DoriathNotifier.php`: render all sharing subjects into localized messages with display names and secret names, include deep-link to affected secret
- [x] 7.4 Register DoriathNotifier in `info.xml` as a notification notifier <!-- W16: registered via registerNotifierService in Application::register() — equivalent + modern NC pattern -->
- [x] 7.5 W16: unit tests cover SUBJECT_SETTING_MAP coverage, opt-out suppression, null-setting bypass, default opt-in dispatch (tests/Unit/Service/NotificationServiceTest.php)

## 8. Event Listeners

- [~] 8.1 Create `UserAddedToGroupListener` in `lib/Listener/UserAddedToGroupListener.php` implementing IEventListener for OCP\Group\Events\UserAddedEvent; calls GroupShareService.handleNewGroupMember with batched notifications per owner
- [~] 8.2 Create `UserRemovedFromGroupListener` in `lib/Listener/UserRemovedFromGroupListener.php` implementing IEventListener for OCP\Group\Events\UserRemovedEvent; calls GroupShareService.handleMemberLeave
- [~] 8.3 Create `EncryptionSuiteRevokedListener` in `lib/Listener/EncryptionSuiteRevokedListener.php` implementing IEventListener; on suite revocation: cascade-delete all SecretShares where target_user_id = suite owner, make temporary delegations permanent via DelegationService.makePermanent
- [~] 8.4 Create `SuiteCompromiseListener` in `lib/Listener/SuiteCompromiseListener.php` implementing IEventListener; after suite migration flags shared copies as possibly_compromised_at: notify original owners via NotificationService with subject secret_compromised
- [~] 8.5 Register all event listeners in `lib/AppInfo/Application.php` via IEventDispatcher

## 9. Controllers and API Routes

- [x] 9.1 Create `ShareController` extending OCSController in `lib/Controller/ShareController.php` with endpoints: index (list shares for secret), create (single share), createBatch (multiple shares for group expansion), destroy (revoke share), sync (update all copies) — W14 scaffold: `lib/Controller/ShareController.php` ships `index` / `create` / `destroy`; `createBatch` + `sync` land with the full build cycle
- [~] 9.2 Create `GroupShareController` extending OCSController in `lib/Controller/GroupShareController.php` with endpoints: index (list group shares for secret), create (create group share, return member list), destroy (revoke group share with cascade), approveNewMember, denyNewMember
- [~] 9.3 Create `ShareRequestController` extending OCSController in `lib/Controller/ShareRequestController.php` with endpoints: create (submit request), approve, deny
- [~] 9.4 Create `DelegationController` extending OCSController in `lib/Controller/DelegationController.php` with endpoints: index (list delegations for secret), create, reclaim
- [x] 9.5 Register all API routes in `appinfo/routes.php` under `/api/v1/`: shares CRUD + sync + batch, group-shares CRUD + member approval, share-requests CRUD, delegations CRUD + reclaim — W14 scaffold: `share#index|create|destroy` registered under `/api/v1/secrets/{secretId}/shares` + `/api/v1/shares/{id}`; group-shares / delegations / share-requests routes land with the full build cycle
- [~] 9.6 Add authorization checks: owner/delegate can manage shares and delegations; recipients can submit share requests and update copies; non-participants get 403

## 10. Modify Existing Services for Share Integration

- [~] 10.1 Update `SecretService.delete()` to cascade-delete all SecretShares, GroupShares, and SecretDelegations for the deleted secret
- [~] 10.2 Update `SecretService.update()` to return a flag indicating whether the secret has active shares (so the frontend knows to trigger sync-on-update)
- [~] 10.3 Update `EncryptionSuiteService.revokeSuite()` to dispatch an event that EncryptionSuiteRevokedListener can consume (or call ShareService/DelegationService directly)

## 11. Pinia Stores (Frontend)

- [~] 11.1 Create `src/store/modules/share.js` (useShareStore) with state: shares, groupShares, loading; actions: fetchShares(secretId), createShare(secretId, targetUserId, encryptedData), createBatchShares(secretId, shares, groupShareId), revokeShare(shareId), fetchGroupShares(secretId), createGroupShare(secretId, groupId), revokeGroupShare(groupShareId), submitShareRequest(secretId, targetUserId), syncUpdate(secretId, updates)
- [~] 11.2 Create `src/store/modules/delegation.js` (useDelegationStore) with state: delegations, loading; actions: fetchDelegations(secretId), createDelegation(secretId, delegateTo), reclaimDelegation(secretId)
- [~] 11.3 Implement client-side encryption in useShareStore.createShare: decrypt secret with own CryptoKey, fetch recipient's public certificate, encrypt with rsaEncrypt() from src/crypto/rsa.js, POST encrypted blob
- [~] 11.4 Implement client-side batch encryption in useShareStore.createGroupShare: fetch group members from API, encrypt for each eligible member, POST batch of encrypted blobs
- [~] 11.5 Implement sync-on-update in useShareStore.syncUpdate: fetch all shares for source secret, encrypt updated value for each recipient, PUT batch to /api/v1/secrets/{id}/sync
- [~] 11.6 Update useSecretStore.updateSecret to call useShareStore.syncUpdate after successful update if the secret has active shares

## 12. Vue Components (Frontend)

- [~] 12.1 Create `src/components/ShareDialog.vue` using NcDialog with NcSelect for user/group picker (Nextcloud user autocomplete via OCS API); allows selecting a user or group and triggering the share flow
- [~] 12.2 Create `src/components/RecipientList.vue` using NcListItem and NcActions; shows list of users the secret is shared with, each with a revoke action button; visible only to owner/delegates
- [~] 12.3 Create `src/components/GroupShareList.vue` using NcListItem and NcActions; shows list of groups the secret is shared with, each with a revoke action button; visible only to owner/delegates
- [~] 12.4 Create `src/components/ShareRequestForm.vue` using NcDialog with NcSelect for target user picker; allows recipients to request the owner share with a third party
- [~] 12.5 Create `src/components/DelegationManager.vue` using NcListItem and NcActions; shows active delegations with delegate name and status (temporary/permanent), reclaim button for owner; visible only to owner/delegates
- [~] 12.6 Integrate sharing components into SecretDetail.vue sidebar (CnObjectSidebar): add a "Sharing" tab containing ShareDialog trigger, RecipientList, GroupShareList, ShareRequestForm (for recipients), and DelegationManager; tab visibility based on owner/delegate/recipient role

## 13. Internationalization

- [~] 13.1 Add English translations for all new UI strings: share dialog labels, recipient list headers, share request form text, delegation labels, notification messages, error messages, empty states
- [~] 13.2 Add Dutch translations for all new UI strings
- [~] 13.3 Use `t()` / `n()` translation functions in all Vue components and PHP controllers/services/notifier

## 14. Unit Tests (PHP)

- [x] 14.1 Write unit tests for `ShareService`: createShare (valid, no suite, self-share error), revokeShare (owner, delegate, unauthorized), syncUpdate (optimistic lock, compromise flag unset), getSharesForSecret (owner sees all, recipient sees empty) — W14 scaffold: `tests/Unit/Service/ShareServiceTest.php` covers createShare insert + self-share reject + empty-source reject + list + revoke creator + revoke non-creator reject + revoke 404 (7 tests); syncUpdate / no-suite / delegate-vs-owner ship with the full build cycle. Plus `tests/Unit/Db/ShareTargetTest.php` covers the entity itself (5 tests)
- [~] 14.2 Write unit tests for `GroupShareService`: createGroupShare (expansion, skip no-suite, skip owner), revokeGroupShare (cascade), handleNewGroupMember (notification per owner, batching), handleMemberLeave (group-derived only, direct untouched)
- [~] 14.3 Write unit tests for `DelegationService`: createDelegation (owner, admin, no-share error), reclaimDelegation (success, permanent error), makePermanent (sets flag, deletes owner copies)
- [x] 14.4 Write unit tests for `NotificationService`: SUBJECT_SETTING_MAP lookup, preference check (enabled, disabled, default), notification dispatch
- [~] 14.5 Write unit tests for share request flow: submit (valid, non-recipient error), approve (creates share), deny (no share, notifies requester)

## 15. Integration Tests (PHP)

- [~] 15.1 Write integration tests for Share API: create share, list shares (owner sees, recipient sees empty), revoke share (deletes copy), sync update (all copies updated)
- [~] 15.2 Write integration tests for GroupShare API: create group share (returns member list), revoke group share (cascade deletes), approve new member, deny new member
- [~] 15.3 Write integration tests for ShareRequest API: submit request, approve (creates share), deny (no share)
- [~] 15.4 Write integration tests for Delegation API: create delegation (owner, admin power grab), reclaim, delegation authorization (delegate can manage shares)
- [~] 15.5 Write integration test: API never returns plaintext — verify encrypted fields in shared copies are encrypted blobs
- [~] 15.6 Write integration test: user cannot access another user's share list (403), non-owner cannot revoke shares (403)
- [~] 15.7 Write integration test: delete secret cascades to all SecretShares, GroupShares, and SecretDelegations
- [~] 15.8 Write integration test: EncryptionSuite revocation cascades to SecretShares and makes delegations permanent
- [~] 15.9 Write integration test: group member removal auto-revokes group-derived shares but not direct shares

## 16. Frontend Tests

- [~] 16.1 Write unit tests for useShareStore: createShare with encryption, revokeShare, syncUpdate, fetchShares
- [~] 16.2 Write unit tests for useDelegationStore: createDelegation, reclaimDelegation, fetchDelegations
- [~] 16.3 Write component tests for ShareDialog: user/group picker renders, share triggers encryption flow
- [~] 16.4 Write component tests for RecipientList: renders recipients, revoke button dispatches action, hidden for non-owners
- [~] 16.5 Write component tests for DelegationManager: renders delegations, reclaim button, permanent delegation indicator

## 17. Ownership Delegation (Deferrable Task Group)

Note: Ownership delegation is marked as V1 in FEATURES.md. The tasks above (6.1-6.4, 9.4, 11.2, 12.5, 14.3, 15.4, 16.2, 16.5) implement delegation fully. If this task group needs to be deferred, remove those tasks and simplify authorization checks to owner-only (no delegate path). The following tasks are delegation-specific cleanup:

- [~] 17.1 Verify DelegationService authorization: admin power grab requires vault_admin group membership AND pre-existing share; user self-delegation requires ownership AND recipient holds share
- [~] 17.2 Verify reclaim flow: original owner reclaims all temporary delegations in one operation; permanent delegations cannot be reclaimed
- [~] 17.3 Verify permanent transfer: EncryptionSuite revocation triggers makePermanent, deletes original owner's inaccessible copies
- [~] 17.4 Verify delegation UI: DelegationManager shows correct status (temporary/permanent), reclaim button disabled for permanent delegations
