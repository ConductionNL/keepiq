## MODIFIED Requirements

### Requirement: Revocation
The system MUST allow a user or administrator to revoke an EncryptionSuite. Revocation assumes the private key is intact but access should be blocked — it is an administrative action, not a key compromise. When a suite is revoked, all secrets encrypted with it become immediately inaccessible. The private key remains in the database, AES-encrypted, unchanged.

Additionally, when a suite is revoked:
- All SecretShare records where the suite owner is the target_user_id MUST be cascade-deleted, along with the encrypted copies in the owner's vault originating from shares.
- All temporary SecretDelegation records where the suite owner is the original_owner_id MUST be made permanent (is_permanent = true, made_permanent_at = now), and the original owner's inaccessible copies for those delegated secrets MUST be deleted.

#### Scenario: Revoke suite
- **GIVEN** an EncryptionSuite is active
- **WHEN** it is revoked by a user or admin with a reason
- **THEN** its status MUST be set to `revoked` with `revoked_at`, `revoked_reason`, and `revoked_by`
- **AND** it MUST NOT be used for new encryption operations
- **AND** the API MUST refuse to decrypt any secret associated with this suite

#### Scenario: Revoke suite cascades to shares received
- **GIVEN** user B has an active EncryptionSuite and holds shared copies of secrets from other users
- **WHEN** B's suite is revoked
- **THEN** all SecretShare records where B is the target_user_id MUST be deleted
- **AND** all encrypted copies in B's vault originating from shares MUST be deleted

#### Scenario: Revoke suite makes delegations permanent
- **GIVEN** user A has an active EncryptionSuite and has delegated secrets to other users (temporary delegations)
- **WHEN** A's suite is revoked
- **THEN** all temporary SecretDelegation records where A is the original_owner_id MUST have is_permanent set to true and made_permanent_at set to now
- **AND** A's copies for those delegated secrets MUST be deleted
- **AND** the delegates retain co-owner rights permanently
