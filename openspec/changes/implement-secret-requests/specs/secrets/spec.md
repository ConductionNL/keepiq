## MODIFIED Requirements

### Requirement: Delete Secret
The system MUST allow a user to delete a secret they own. Deletion MUST cascade to any SecretShares derived from this secret, any SecretRequests linked to it, and any GroupShares referencing it.

When a Secret is deleted and has associated SecretRequests:
- All SecretRequest records with `secret_id` matching the deleted Secret MUST be deleted
- The tokens for those requests MUST become invalid (public fill-in returns 404)

#### Scenario: Delete secret with pending request
- **WHEN** a user deletes a Secret that has a pending SecretRequest
- **THEN** the Secret MUST be deleted
- **THEN** the SecretRequest MUST be deleted
- **THEN** the fill-in link for the deleted request MUST return 404

#### Scenario: Delete secret with fulfilled request
- **WHEN** a user deletes a Secret that has a fulfilled SecretRequest
- **THEN** the Secret MUST be deleted
- **THEN** the SecretRequest record MUST be deleted
