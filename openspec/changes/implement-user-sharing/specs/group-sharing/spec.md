## ADDED Requirements

### Requirement: Share with Nextcloud Group (Static Expansion)
The system MUST allow a secret owner to share a secret with a Nextcloud user group. At the moment of sharing, the system MUST create an individual SecretShare (with group_share_id set) for each current member of the group who has an active EncryptionSuite. A GroupShare record MUST be created to track the secret-group relationship.

Members without an active EncryptionSuite are skipped at expansion time. No error is returned for skipped members. The owner themselves is excluded from receiving a share (they already own the secret).

The GroupShare data model (all fields unencrypted):

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `secret_id` | FK (Secret) | The secret shared with the group |
| `group_id` | string | Nextcloud group ID |
| `created_by` | string | Nextcloud user ID of the owner who created the share |
| `created_at` | datetime | |

Group membership is queried via `OCP\IGroupManager`.

#### Scenario: Share with group
- **WHEN** user A shares a secret with group G containing members B, C, D (all with active EncryptionSuites) and member E (without an EncryptionSuite)
- **THEN** 3 SecretShares MUST be created (for B, C, D) each with group_share_id referencing the GroupShare
- **AND** a GroupShare record MUST be created for the secret-group pair
- **AND** member E is skipped without error

#### Scenario: Owner is a group member
- **WHEN** user A shares a secret with group G of which A is a member
- **THEN** no SecretShare is created for A (A already owns the secret)

### Requirement: New Group Member Notification and Approval
When a new user joins a Nextcloud group that has one or more GroupShare records, the secret owner MUST be notified for each affected secret and asked to approve adding the new member. This event is detected via `OCP\Group\Events\UserAddedEvent`.

On approval: a new SecretShare is created for the new member (with group_share_id set), and the new member is notified of the new share.
On denial: no share is created. The GroupShare remains active for future members.

#### Scenario: New member joins group with active group shares
- **WHEN** user X joins group G which has GroupShares for secrets S1 and S2 (owned by A and B respectively)
- **THEN** A MUST receive a Nextcloud notification asking to approve sharing S1 with X
- **AND** B MUST receive a Nextcloud notification asking to approve sharing S2 with X

#### Scenario: Owner approves new group member
- **WHEN** owner A approves the share for new member X
- **THEN** a SecretShare MUST be created for X referencing the GroupShare
- **AND** X MUST receive a notification that the secret was shared with them

#### Scenario: Owner denies new group member
- **WHEN** owner A denies the share for new member X
- **THEN** no SecretShare is created
- **AND** the GroupShare remains active for the group

#### Scenario: New member has no EncryptionSuite
- **WHEN** user X joins group G but has no active EncryptionSuite
- **THEN** the owner is still notified
- **AND** approval MUST fail with an error if X still has no suite when the owner approves

### Requirement: Auto-Revocation on Group Member Leave
When a user leaves a Nextcloud group, all SecretShares for that user originating from a GroupShare for that group MUST be automatically revoked (deleted), along with the encrypted copies in the user's vault. This event is detected via `OCP\Group\Events\UserRemovedEvent`.

Direct shares (where group_share_id is null) are unaffected.

#### Scenario: Member leaves group
- **WHEN** user Y leaves group G and Y has SecretShares derived from GroupShares for group G
- **THEN** all of Y's group-derived SecretShares for group G MUST be deleted along with the encrypted copies
- **AND** direct shares for the same secrets (group_share_id = null) MUST remain intact

### Requirement: Revoke Group Share
The secret owner MUST be able to revoke a GroupShare. On revocation, all SecretShares originating from that GroupShare MUST be cascade-deleted, along with the encrypted copies.

#### Scenario: Owner revokes group share
- **WHEN** the owner revokes a GroupShare for group G
- **THEN** all SecretShares with that group_share_id MUST be deleted along with encrypted copies
- **AND** the GroupShare record MUST be removed
- **AND** direct shares for the same secret (group_share_id = null) are unaffected
