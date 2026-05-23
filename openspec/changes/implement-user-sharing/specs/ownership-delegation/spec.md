## ADDED Requirements

### Requirement: Ownership Delegation
A secret's ownership can be delegated to one or more other users, granting them co-owner rights (share management, full recipient visibility, value updates). Multiple active delegations for the same secret are allowed simultaneously.

Who can create a delegation:
- **Admin power grab**: a vault administrator can create a delegation for any secret that has already been shared with them. No owner consent is required.
- **User self-delegation**: the secret owner can create a delegation for their own secret to any user who already holds a share of that secret.

In both cases the delegatee MUST already hold a share. The delegation promotes their existing copy to co-owner status. The original owner's copy is demoted to a regular share for the duration of the delegation. The existing sync-on-update mechanism ensures all copies remain consistent regardless of which co-owner updates the value.

The SecretDelegation data model (all fields unencrypted):

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `secret_id` | FK (Secret) | The secret being delegated |
| `original_owner_id` | string | Nextcloud user ID of the original owner |
| `delegated_to` | string | Nextcloud user ID of the delegate |
| `delegated_at` | datetime | When delegation was created |
| `initiated_by` | string | User ID of who created the delegation (may differ from original_owner_id for admin power grabs) |
| `is_permanent` | bool | false = temporary (owner can reclaim); true = permanent (owner's suite was revoked/deleted) |
| `made_permanent_at` | datetime | Null while temporary |

#### Scenario: Admin creates delegation (power grab)
- **WHEN** a vault administrator who holds a share of secret S creates a delegation for S
- **THEN** a SecretDelegation record MUST be created with is_permanent = false and initiated_by = admin's user ID
- **AND** the admin gains co-owner rights: share management, full recipient visibility, value updates

#### Scenario: Owner self-delegates
- **WHEN** user A (owner of secret S) creates a delegation to user B who holds a share of S
- **THEN** a SecretDelegation record MUST be created with is_permanent = false
- **AND** B gains co-owner rights for S

#### Scenario: Delegatee does not hold a share
- **WHEN** a delegation to user C is attempted but C does not hold a share of the secret
- **THEN** the system MUST return an error indicating delegation requires a pre-existing share

#### Scenario: Non-admin non-owner attempts delegation
- **WHEN** a regular user who is not the owner attempts to create a delegation
- **THEN** the system MUST return a 403 error

### Requirement: Reclaim Delegation
The original owner MUST be able to reclaim ownership at any time while delegations are temporary, revoking all active delegations simultaneously.

#### Scenario: Owner reclaims
- **WHEN** the original owner reclaims secret S which has one or more active temporary delegations
- **THEN** ALL active SecretDelegation records for S MUST be removed
- **AND** all delegates are demoted back to regular share recipients
- **AND** the original owner is sole owner again

#### Scenario: Reclaim fails for permanent delegations
- **WHEN** the original owner attempts to reclaim a secret that only has permanent delegations
- **THEN** the system MUST return an error — permanent delegations cannot be reclaimed

### Requirement: Permanent Transfer on Suite Revocation
When the original owner's EncryptionSuite is revoked or deleted, all temporary delegations for their secrets MUST automatically become permanent.

#### Scenario: Original owner's suite revoked
- **WHEN** the original owner's EncryptionSuite is revoked and secret S has active temporary delegations
- **THEN** all SecretDelegation records for S MUST have is_permanent set to true and made_permanent_at set to now
- **AND** the original owner's (now inaccessible) copy MUST be deleted
- **AND** the delegates retain co-owner rights permanently — reclaim is no longer possible

#### Scenario: No delegations exist when suite is revoked
- **WHEN** the original owner's EncryptionSuite is revoked and their secrets have no active delegations
- **THEN** no delegation records are created — secrets become inaccessible per the revoked suite access block requirement
