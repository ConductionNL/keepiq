## MODIFIED Requirements

### Requirement: Update Secret
The system MUST allow a user to update any field of a secret they own, including moving it to a different folder. Updated encrypted fields MUST be re-encrypted before storage. If the secret has active SecretShares, the system MUST trigger sync-on-update: the browser re-encrypts the updated value for each recipient's public certificate and writes the updated blobs to all shared copies. If any copy has possibly_compromised_at set, sync-on-update MUST unset it on the original and all copies after successful propagation.

#### Scenario: Update unshared secret
- **WHEN** a user updates a secret with no active shares
- **THEN** the system MUST re-encrypt the updated fields and store them

#### Scenario: Update shared secret triggers sync
- **WHEN** a user updates a secret that has active SecretShares
- **THEN** the system MUST re-encrypt the updated value for each recipient's public certificate
- **AND** write the updated encrypted blobs to all shared copies

#### Scenario: Update clears compromise flag on all copies
- **WHEN** a user updates a shared secret where any copy has possibly_compromised_at set
- **THEN** after sync-on-update completes, possibly_compromised_at MUST be unset on the original and all copies

### Requirement: Delete Secret
The system MUST allow a user to delete a secret they own. Deletion MUST cascade to all SecretShares derived from this secret (deleting both the SecretShare records and the encrypted copies in recipients' vaults), all GroupShare records for this secret, all SecretDelegation records for this secret, and any SecretRequests linked to it.

#### Scenario: Delete secret with active shares
- **WHEN** a user deletes a secret that has active SecretShares
- **THEN** all SecretShare records for this secret MUST be deleted
- **AND** all encrypted copies in recipients' vaults MUST be deleted
- **AND** all GroupShare records for this secret MUST be deleted
- **AND** all SecretDelegation records for this secret MUST be deleted

#### Scenario: Delete secret without shares
- **WHEN** a user deletes a secret with no shares
- **THEN** the secret is deleted and any SecretRequests linked to it are deleted
