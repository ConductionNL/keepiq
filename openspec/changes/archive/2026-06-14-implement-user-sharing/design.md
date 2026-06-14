## Context

Doriath is an encrypted secrets manager for Nextcloud. The implement-encryption-suites change provides the cryptographic foundation (EncryptionSuite entity, CA, WebCrypto, session store, lock screen). The implement-secrets change provides the core data entities (Secret, SecretType, Folder) with full CRUD, search, and unified search integration.

With both foundations in place, Doriath is a single-user vault. This change adds user-to-user and group-based secret sharing, transforming Doriath into a collaborative secret manager. Sharing is the primary collaboration feature and the core differentiator over competitors that lack Nextcloud-native integration.

The existing codebase after the two dependency changes will have: six database tables (encryption_suites, ca_certificates, suite_migrations, secret_types, folders, secrets), stateless crypto services, a session store with WebCrypto, a lock screen, Vue Router with navigation guard, Pinia stores for secrets/folders/types, and SecretDetail/SecretList views. No sharing-related entities, services, or UI exist yet.

Sharing in Doriath is fundamentally client-side (ADR-003): Alice's browser decrypts the secret with her private key, fetches Bob's public certificate from the server, encrypts the plaintext with Bob's public certificate, and POSTs the encrypted blob. The server stores encrypted blobs only and never sees plaintext during sharing operations.

## Goals / Non-Goals

**Goals:**
- Implement SecretShare, GroupShare, and SecretDelegation entities with ISchemaWrapper database migrations
- Implement user-to-user secret sharing with encrypted copy creation (client-side E2E)
- Implement sync-on-update: changes propagate to all copies via O(N) RSA operations in the browser
- Implement Nextcloud group-based sharing with static expansion to individual SecretShares
- Implement new group member notification and owner approval flow
- Implement auto-revocation of group-derived shares on group member leave
- Implement share revocation (owner deletes recipient's copy)
- Implement share request mechanism (recipient asks owner to share with third party)
- Implement share visibility rules (owner sees recipients; recipients cannot)
- Implement EncryptionSuite revocation cascade to shares
- Implement EncryptionSuite compromise notification for shared copies
- Implement ownership delegation (admin power grab, user self-delegation, reclaim, permanent transfer)
- Implement Nextcloud notifications for all sharing events via NotificationService
- Implement sharing UI: share dialog, recipient list, share request form, delegation management

**Non-Goals:**
- Link sharing (password-protected public links) -- separate change
- Secret requests (fill-in links for write-without-read) -- separate change
- Application sharing (application-to-application) -- separate change
- Bulk sharing operations -- V1 tier
- Share audit trail -- Enterprise tier
- Mandatory admin share on creation (policy enforcement) -- future exploration

## Decisions

### D1: Database Migration Versioning -- Continue Sequence

Migrations continue the version numbering from implement-secrets:
- `Version000007Date20260331000006` -- `doriath_secret_shares` table
- `Version000008Date20260331000007` -- `doriath_group_shares` table
- `Version000009Date20260331000008` -- `doriath_secret_delegations` table

SecretShares depend on Secrets (FK), GroupShares depend on Secrets (FK), SecretDelegations depend on Secrets (FK). All three can be created in any order since they only reference the existing secrets table.

**Why:** Nextcloud executes migrations in alphabetical order by class name. Sequential numbering ensures correct ordering. All three tables reference `doriath_secrets` which already exists from implement-secrets.

### D2: Client-Side Sharing Encryption Flow

```
Alice's Browser                              Server
  |                                            |
  |  1. Decrypt secret with own private key    |
  |     (WebCrypto RSA-OAEP-SHA256)            |
  |                                            |
  |  2. GET /api/v1/encryption-suites          |
  |     ?owner_type=user&owner_id=bob -------->|
  |  <---- { certificate: "-----BEGIN..." }    |
  |                                            |
  |  3. Import Bob's public key from cert      |
  |     (WebCrypto importKey SPKI)             |
  |                                            |
  |  4. Encrypt plaintext with Bob's key       |
  |     (RSA-OAEP-SHA256 with chunking)        |
  |                                            |
  |  5. POST /api/v1/shares ------------------>|
  |     { source_secret_id, target_user_id,    |
  |       encrypted_key, encrypted_login,      |
  |       encrypted_additional_fields,         |
  |       name, url, type_id }                 |
  |                                            |
  |                                            |  6. Create Secret row (recipient's copy)
  |                                            |  7. Create SecretShare record
  |                                            |  8. Send notification to Bob
  |  <---- { share_id, secret_id }             |
```

The server receives the pre-encrypted blob from Alice's browser and stores it. The server never has access to the plaintext. Bob's copy is a full Secret row in `doriath_secrets` with `owner_type=user, owner_id=bob, encryption_suite_id=bob's_suite_id`.

**Why:** ADR-003 mandates always-E2E. The server is a ciphertext store. Client-side encryption ensures the server cannot read shared secrets even during the sharing operation.

**Alternatives considered:**
- Server-side re-encryption with temporary key: Rejected -- violates ADR-003. Would require the server to hold plaintext momentarily, expanding the attack surface.

### D3: Sync-on-Update Architecture

When any copy of a shared secret is updated, the browser must re-encrypt the new value for all other copies. The flow:

1. User updates a field in their copy (browser has plaintext after decryption)
2. Browser fetches the list of all SecretShares for the source secret: `GET /api/v1/shares?source_secret_id={id}`
3. For each share, browser fetches the recipient's public certificate
4. Browser encrypts the plaintext with each recipient's public key (O(N) RSA operations)
5. Browser sends a batch update: `PUT /api/v1/secrets/{id}/sync` with the array of `{secret_id, encrypted_key, encrypted_login, encrypted_additional_fields}`
6. Server writes all encrypted blobs in a single transaction
7. If any copy has `possibly_compromised_at` set, the server unsets it on all copies in the same transaction

For metadata-only changes (name, url, type_id), the server propagates without browser involvement since these fields are unencrypted.

**Why:** O(N) RSA operations in the browser is acceptable for typical share counts (1-20 recipients). The browser already holds the plaintext in memory after decryption. Batch API reduces round trips.

**Trade-off:** For secrets shared with 100+ users (via large groups), sync-on-update could be slow. This is an accepted limitation for MVP. A future optimization could use a background queue with the server holding a temporary session key -- but this violates E2E principles and is deferred.

### D4: Group Share Expansion with Event Listeners

Group sharing uses Nextcloud's group infrastructure:

1. Owner shares with group G -> `POST /api/v1/group-shares`
2. Server queries group members via `OCP\IGroupManager::get($groupId)->getUsers()`
3. Server returns the member list to the browser (excluding the owner)
4. Browser encrypts for each eligible member (same as D2 flow, repeated N times)
5. Browser sends batch: `POST /api/v1/shares/batch` with all encrypted copies
6. Server creates N SecretShare records + 1 GroupShare record in a transaction

For new member events:
- Register `UserAddedToGroupListener` for `OCP\Group\Events\UserAddedEvent`
- Listener queries all GroupShares for the group
- For each GroupShare, create a Nextcloud notification to the secret owner (subject: `group_member_added`)
- Owner approval triggers the standard share creation flow (browser-side encryption)

For member leave events:
- Register `UserRemovedFromGroupListener` for `OCP\Group\Events\UserRemovedEvent`
- Listener queries all SecretShares where `group_share_id` references a GroupShare for the group AND `target_user_id` = removed user
- Delete those SecretShares and their associated Secret copies in a transaction

**Why:** Static expansion at share time (not dynamic membership check on every access) is simpler, more predictable, and aligned with the E2E model where each recipient needs their own encrypted copy. Event listeners handle the gap between static and dynamic by notifying owners of membership changes.

### D5: Share Request as Notification Action

Share requests are implemented as Nextcloud notifications with action buttons, not as a separate database entity. The flow:

1. Recipient B triggers share request via UI -> `POST /api/v1/share-requests`
2. Server creates a notification to owner A with subject `share_request` and actions Approve/Deny
3. A clicks Approve -> `POST /api/v1/share-requests/{id}/approve` -> triggers standard share flow
4. A clicks Deny -> `POST /api/v1/share-requests/{id}/deny` -> notification to B

A lightweight `doriath_share_requests` tracking table is NOT needed for MVP. The notification itself is the request. The API endpoints `/share-requests/{id}/approve` and `/share-requests/{id}/deny` use the notification ID to look up the request parameters stored in the notification's `$messageParameters`.

**Why:** Avoids a new table. Notifications are transient by nature (dismissed, acted on, or ignored). Using notification parameters to store request context keeps the implementation minimal.

**Alternative considered:**
- Dedicated ShareRequest table: More robust for audit trails and re-processing. Deferred to V1 if audit trail is needed.

### D6: NotificationService with Subject Setting Map

A `NotificationService` centralizes all notification logic:

```php
class NotificationService {
    const SUBJECT_SETTING_MAP = [
        'secret_shared'       => 'notify_shares',
        'share_request'       => 'notify_shares',
        'share_request_result'=> 'notify_shares',
        'group_member_added'  => 'notify_group_shares',
        'secret_compromised'  => 'notify_security',
    ];

    public function notify(string $subject, string $recipientId, array $params): void {
        $settingKey = self::SUBJECT_SETTING_MAP[$subject] ?? null;
        if ($settingKey !== null) {
            $enabled = $this->config->getUserValue($recipientId, 'doriath', $settingKey, 'true');
            if ($enabled !== 'true') return;
        }
        // Create and dispatch notification via IManager
    }
}
```

An `INotifier` implementation (`DoriathNotifier`) renders all subjects into localized messages.

**Why:** FEATURES.md specifies per-subject user notification toggles. The constant map makes it trivial to add new subjects and keeps the preference check centralized.

### D7: Service Layer Architecture

```
ShareController
  └── ShareService (share CRUD, sync-on-update server-side orchestration)
        ├── SecretShareMapper (DB)
        ├── SecretMapper (create recipient copies, delete cascades)
        ├── EncryptionSuiteService (fetch recipient's public cert)
        └── NotificationService

GroupShareController
  └── GroupShareService (group share CRUD, batch expansion)
        ├── GroupShareMapper (DB)
        ├── SecretShareMapper (for cascade operations)
        ├── IGroupManager (group membership queries)
        └── NotificationService

DelegationController
  └── DelegationService (delegation CRUD, reclaim, permanent transfer)
        ├── SecretDelegationMapper (DB)
        ├── SecretShareMapper (verify share existence)
        └── NotificationService

Event Listeners:
  UserAddedToGroupListener   → GroupShareService + NotificationService
  UserRemovedFromGroupListener → GroupShareService
  EncryptionSuiteRevokedListener → ShareService + DelegationService
```

All controllers extend OCSController. All services follow Controller -> Service -> Mapper layering (ADR-008).

### D8: Frontend Architecture -- Share Store and Components

**useShareStore (Pinia):**
- State: shares (array for current secret), shareRequests (pending), loading
- Actions: fetchShares(secretId), createShare(secretId, targetUserId, encryptedData), revokeShare(shareId), createGroupShare(secretId, groupId, encryptedBatch), revokeGroupShare(groupShareId), submitShareRequest(secretId, targetUserId), approveShareRequest(requestId), denyShareRequest(requestId)

**useDelegationStore (Pinia):**
- State: delegations (array for current secret), loading
- Actions: fetchDelegations(secretId), createDelegation(secretId, delegateTo), reclaimDelegation(secretId)

The share creation action in `useShareStore.createShare` performs client-side encryption:
1. Get plaintext from the session (already decrypted in useSecretStore)
2. Fetch recipient's public certificate via EncryptionSuite API
3. Encrypt using `rsaEncrypt()` from `src/crypto/rsa.js`
4. POST encrypted blob to share API

**UI Components:**

| Component | Library Components | Purpose |
|-----------|-------------------|---------|
| `ShareDialog.vue` | NcDialog, NcSelect (user picker) | Share a secret with a user or group |
| `RecipientList.vue` | NcListItem, NcActions | Owner view: list of recipients with revoke action |
| `ShareRequestForm.vue` | NcDialog, NcSelect | Recipient: request owner to share with third party |
| `DelegationManager.vue` | NcListItem, NcActions | Owner view: manage delegations with reclaim button |
| `GroupShareList.vue` | NcListItem, NcActions | Owner view: list of group shares with revoke action |

These components are integrated into the SecretDetail.vue sidebar (CnObjectSidebar) as a "Sharing" tab, visible only to the owner and delegates.

### D9: API Endpoints

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| GET | `/api/v1/shares` | List shares for a secret (owner only) | Owner/delegate |
| POST | `/api/v1/shares` | Create a share (encrypted copy) | Owner/delegate |
| POST | `/api/v1/shares/batch` | Create multiple shares (group expansion) | Owner/delegate |
| DELETE | `/api/v1/shares/{id}` | Revoke a share | Owner/delegate |
| PUT | `/api/v1/secrets/{id}/sync` | Sync updated values to all copies | Owner/recipient |
| GET | `/api/v1/group-shares` | List group shares for a secret | Owner/delegate |
| POST | `/api/v1/group-shares` | Create a group share | Owner/delegate |
| DELETE | `/api/v1/group-shares/{id}` | Revoke a group share (cascade) | Owner/delegate |
| POST | `/api/v1/share-requests` | Submit a share request | Recipient |
| POST | `/api/v1/share-requests/{id}/approve` | Approve a share request | Owner |
| POST | `/api/v1/share-requests/{id}/deny` | Deny a share request | Owner |
| GET | `/api/v1/delegations` | List delegations for a secret | Owner/delegate |
| POST | `/api/v1/delegations` | Create a delegation | Owner/admin |
| DELETE | `/api/v1/delegations/{secretId}` | Reclaim (delete all delegations) | Owner |

Query parameters for `GET /shares`:
- `source_secret_id` -- filter by source secret (required)

### D10: EncryptionSuite Revocation Cascade

When `EncryptionSuiteService.revokeSuite()` is called, it must now also:

1. Query all SecretShares where `target_user_id` = suite owner
2. For each SecretShare: delete the recipient's Secret copy, then delete the SecretShare record
3. Query all SecretDelegations where `original_owner_id` = suite owner and `is_permanent = false`
4. For each delegation: set `is_permanent = true` and `made_permanent_at = now`
5. Delete the original owner's Secret copies for delegated secrets (they are now inaccessible)

This is implemented as a listener (`EncryptionSuiteRevokedListener`) that fires after the suite status is updated, keeping the EncryptionSuiteService itself clean.

### D11: Delegation Authorization Model

Co-owner rights granted by delegation include:
- View full recipient list (share visibility)
- Create new shares (same as owner)
- Revoke existing shares
- Update the secret value (sync-on-update propagates to all)
- Create further delegations (only owner self-delegation, not admin power grab)

Co-owner rights do NOT include:
- Delete the original secret (only the original owner can)
- Reclaim delegations (only the original owner can)

Authorization checks in ShareService, GroupShareService, and DelegationService must accept both the original owner and active delegates (checked via `SecretDelegationMapper.findActiveBySecretAndUser()`).

### D12: Vue Router Integration

No new routes needed. Sharing UI is integrated into the existing SecretDetail view via the CnObjectSidebar. The sidebar shows a "Sharing" tab (visible only to owner/delegates) with the ShareDialog trigger, RecipientList, GroupShareList, and DelegationManager.

Share request actions (approve/deny) are handled via Nextcloud notification actions, which use the notification API endpoint pattern. No separate route is needed for share request resolution.

## Risks / Trade-offs

- **[Risk] Sync-on-update performance for widely-shared secrets** -- O(N) RSA operations in the browser for each update. For 20 recipients, this means 20 RSA-OAEP encryptions (~50ms each on modern hardware = ~1 second). For 100+ recipients (large group shares), this could take 5+ seconds. Mitigated by showing a progress indicator during sync. Future: async background sync via server-side temporary key (deferred, violates E2E).

- **[Risk] Race condition on concurrent updates** -- If Alice and Bob both update a shared secret simultaneously, their sync-on-update operations could overwrite each other. Mitigated by using `updated_at` as an optimistic lock: the sync endpoint rejects writes if the target copy's `updated_at` has changed since the sync was initiated. The browser retries by re-reading and re-encrypting.

- **[Risk] Group member notification spam** -- If a group has many GroupShares, a new member joining triggers one notification per secret per owner. For a group with 50 shared secrets across 10 owners, that is 50 notifications. Mitigated by batching: the listener groups notifications by owner and sends a single notification summarizing all affected secrets ("User X joined group G -- 5 secrets need your approval").

- **[Risk] Orphaned shares on notification dismissal** -- If an owner dismisses a group_member_added notification without approving or denying, the new member never gets access. This is by design: the default is "no access." The notification can be re-triggered by the admin if needed (future feature).

- **[Trade-off] Share request via notifications only** -- No persistent ShareRequest table means no audit trail for requests. Acceptable for MVP; a dedicated table can be added in V1 if needed for compliance.

- **[Trade-off] Static group expansion** -- Group shares are expanded at share time, not dynamically. This means removing a secret from a group requires explicit revocation, not just removing the GroupShare. The GroupShare record is used only for tracking and auto-revocation on member leave.

- **[Trade-off] Delegation requires pre-existing share** -- An admin can only take over a secret that was proactively shared with them. This is a cryptographic constraint: without a copy encrypted with their key, they cannot decrypt it. Mitigation: organizational policy to share critical secrets with a designated admin.

## Migration Plan

1. **Database migrations**: Run `occ upgrade` to execute ISchemaWrapper migrations creating `doriath_secret_shares`, `doriath_group_shares`, and `doriath_secret_delegations` tables
2. **Event listener registration**: Register UserAddedToGroupListener, UserRemovedFromGroupListener, and EncryptionSuiteRevokedListener in `info.xml`
3. **Notification registration**: Register DoriathNotifier as an INotifier in `info.xml`
4. **No data migration**: Greenfield -- no existing share data to migrate
5. **Rollback**: Disable the app via `occ app:disable doriath`. Tables remain but are inert. Re-enable to resume.

## Seed Data

Since Doriath uses its own database (not OpenRegister), seed data is handled through:

### 1. Database migrations (always run)
The three new tables (`doriath_secret_shares`, `doriath_group_shares`, `doriath_secret_delegations`) are created via ISchemaWrapper migrations. No static data is seeded into these tables on install -- shares are created dynamically by users.

### 2. Development seed data (repair step -- debug mode only)
A `SeedDevelopmentShares` repair step (registered only when `debug=true`) creates example shares between the development test users for the existing dev secrets. This depends on:
- `SeedDevelopmentData` from implement-encryption-suites (creates test user EncryptionSuites)
- `SeedDevelopmentSecrets` from implement-secrets (creates example secrets)

Example shares seeded:

| Source Secret | Owner | Shared With | Type |
|---------------|-------|-------------|------|
| GitHub (login) | dev-user-1 | dev-user-2 | Direct share |
| AWS Console (api_key) | dev-user-1 | dev-user-2, dev-user-3 | Direct shares |
| Production Database | dev-user-1 | dev-group-1 (containing dev-user-2, dev-user-3) | Group share |

The repair step:
1. Looks up existing dev user EncryptionSuites and dev secrets
2. For each share: encrypts the secret value with the recipient's public certificate (server-side, using EncryptService -- acceptable for seed data since these are known test values, not real secrets)
3. Creates SecretShare and GroupShare records
4. Creates one SecretDelegation: dev-user-2 as delegate for the GitHub secret (demonstrating delegation)

### 3. Default user settings
New user settings seeded by `InitializeSettings` (update existing repair step):
- `notify_shares`: true (default)
- `notify_group_shares`: true (default)
- `notify_security`: true (default)

## Open Questions

- Should the batch notification for new group members (D3 risk mitigation) be implemented in MVP or deferred? Current decision: implement batching in MVP to avoid notification spam from day one.
- Should share request history be queryable after the notification is dismissed? Current decision: no persistent history in MVP. The notification is the record. A ShareRequest table can be added in V1 for audit purposes.
