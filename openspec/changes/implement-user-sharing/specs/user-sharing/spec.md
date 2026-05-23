## ADDED Requirements

### Requirement: Share a Secret with a Nextcloud User
The system MUST allow a user to share a secret they own with another Nextcloud user who has an active EncryptionSuite. Sharing MUST create an encrypted copy of the secret in the recipient's vault, encrypted with the recipient's public certificate. The encrypted copy is a full Secret row owned by the recipient. A SecretShare record MUST link the original secret to the copy.

All encryption during sharing is client-side (ADR-003): the owner's browser decrypts the secret with its own private key, fetches the recipient's public certificate from the server, encrypts the plaintext with the recipient's public certificate using RSA-OAEP-SHA256 with chunking, and POSTs the encrypted blob. The server never sees plaintext during sharing.

The SecretShare data model (all fields unencrypted):

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `source_secret_id` | FK (Secret) | The original secret |
| `target_user_id` | string | Recipient's Nextcloud user ID |
| `secret_id` | FK (Secret) | The encrypted copy in recipient's vault |
| `group_share_id` | FK (GroupShare) | Nullable — set if created from a group share; null for direct shares |
| `created_at` | datetime | |

#### Scenario: Share with valid recipient
- **WHEN** user A shares a secret with user B who has an active EncryptionSuite
- **THEN** the system MUST create an encrypted copy of the secret in user B's vault encrypted with B's public certificate
- **AND** create a SecretShare linking source_secret_id to the copy's secret_id with target_user_id = B

#### Scenario: Share with user without EncryptionSuite
- **WHEN** user A attempts to share a secret with user B who has no EncryptionSuite
- **THEN** the system MUST return an error indicating the recipient has no encryption suite

#### Scenario: Share with self
- **WHEN** user A attempts to share a secret with themselves
- **THEN** the system MUST return an error — a user cannot share a secret with themselves

### Requirement: Sync on Update
When either party updates a shared secret, the change MUST be propagated to all copies. The updating user's browser MUST re-encrypt the new value for each recipient's public certificate and write the updated blobs to all copies. This is O(N) RSA operations in the browser where N is the number of recipients.

Sync-on-update applies to encrypted fields (key, login, additional_fields) and unencrypted metadata (name, url, type_id, folder_id). Metadata changes are propagated server-side without re-encryption.

When updating a possibly-compromised secret (possibly_compromised_at is set), sync-on-update MUST unset possibly_compromised_at on the original and all copies after successful propagation.

#### Scenario: Owner updates shared secret
- **WHEN** the owner updates a shared secret's value
- **THEN** the system MUST re-encrypt the updated value for each recipient using their public certificate and write to their copy

#### Scenario: Recipient updates shared secret
- **WHEN** a recipient updates their copy of a shared secret
- **THEN** the system MUST re-encrypt the updated value for the original owner and all other recipients

#### Scenario: Update clears compromise flag
- **WHEN** a user updates a shared secret that has possibly_compromised_at set on any copy
- **THEN** sync-on-update MUST propagate the new value to all copies
- **AND** possibly_compromised_at MUST be unset on the original and all copies

### Requirement: Share Revocation
The original owner (or a delegate with co-owner rights) MUST be able to revoke a share, removing the recipient's copy.

#### Scenario: Revoke share
- **WHEN** the owner revokes a share with user B
- **THEN** the recipient's Secret copy MUST be deleted
- **AND** the SecretShare record MUST be removed

### Requirement: Share Request (Recipient-Initiated)
Recipients MUST NOT be able to share a secret directly with a third party. Instead, a recipient MAY submit a share request to the original owner, asking them to share the secret with another Nextcloud user.

The share request flow:
1. Recipient B submits a request: "Please share secret S with user C"
2. The original owner A receives a Nextcloud notification
3. A approves: the system creates a new SecretShare directly from A to C
4. A denies: the request is dropped
5. B receives a Nextcloud notification of the outcome (accepted or denied)

This keeps all shares flat (A to B, A to C, A to D, ...), preserves owner control, and avoids sync and revocation complexity from re-share trees.

#### Scenario: Recipient requests a share
- **WHEN** user B (who holds a shared copy of A's secret) submits a share request for user C
- **THEN** a Nextcloud notification MUST be sent to owner A
- **AND** B MUST receive a notification when A approves or denies

#### Scenario: Owner approves share request
- **WHEN** A approves a share request from B for user C
- **THEN** the system MUST create a SecretShare from A to C as if A initiated it directly

#### Scenario: Owner denies share request
- **WHEN** A denies a share request from B for user C
- **THEN** no share is created and B is notified of the denial

#### Scenario: Non-recipient submits share request
- **WHEN** a user who does not hold a shared copy of the secret attempts to submit a share request
- **THEN** the system MUST return a 403 error

### Requirement: Share Visibility
Only the original owner (and delegates with co-owner rights) of a secret MUST be able to see the full list of users the secret is shared with. Recipients MUST NOT see who else has access.

#### Scenario: Owner views share list
- **WHEN** user A (owner) views a secret shared with B and C
- **THEN** A MUST see the full list of recipients including B and C

#### Scenario: Recipient views shared secret
- **WHEN** user B (recipient) views their shared copy
- **THEN** B MUST NOT see the list of other recipients

### Requirement: EncryptionSuite Revocation Cascade
When a recipient's EncryptionSuite is revoked (deliberate decommissioning), all SecretShare records targeting that recipient MUST be automatically cascade-deleted, including the encrypted copies in the recipient's vault. The original owner's copy is unaffected.

#### Scenario: Recipient's suite is revoked
- **WHEN** user B's EncryptionSuite is revoked
- **THEN** all SecretShare records where B is the target_user_id MUST be deleted
- **AND** all encrypted copies in B's vault originating from a share MUST be deleted
- **AND** the original secrets owned by their respective owners remain intact

### Requirement: EncryptionSuite Compromise Notification for Shared Copies
When a recipient's EncryptionSuite is replaced due to compromise, the suite migration process naturally covers all Secret rows encrypted with the old suite, including shared copies. Those copies are re-encrypted with the new suite and flagged possibly_compromised_at.

When a shared copy is flagged possibly_compromised_at during migration, the original owner of the secret MUST be notified that the secret may have been compromised and its value should be replaced.

#### Scenario: Shared copy flagged during migration
- **WHEN** user B's EncryptionSuite is replaced due to compromise and a shared copy is migrated
- **THEN** the copy MUST be flagged possibly_compromised_at (per encryption-suites migration)
- **AND** the original owner A MUST receive a Nextcloud notification advising them to replace the secret value

#### Scenario: Owner replaces possibly-compromised secret value
- **WHEN** owner A updates the secret value after receiving a compromise notification
- **THEN** sync-on-update MUST propagate the new value to all copies
- **AND** possibly_compromised_at MUST be unset on the original and all copies
