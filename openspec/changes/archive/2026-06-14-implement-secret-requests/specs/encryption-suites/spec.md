## MODIFIED Requirements

### Requirement: Compromise Recovery Migration
When a user indicates their master password has been compromised, the system MUST initiate a full key rotation: a new RSA key pair is generated, all secrets are re-encrypted, and the old EncryptionSuite is flagged as compromised.

During compromise recovery, the system MUST also handle SecretRequests:

1. At migration start: all pending SecretRequests whose `encryption_suite_id` references the old suite MUST be set to `locked` status
2. While locked: the public fill-in page MUST reject submissions and display "temporarily unavailable"
3. At migration completion: locked SecretRequests MUST be set back to `pending` and their `encryption_suite_id` MUST be updated to the new suite
4. After unlock: the fill-in page MUST encrypt with the new suite's public certificate

This ensures no external party can submit values encrypted with the old (compromised) public certificate during the migration window.

During compromise recovery, the system MUST migrate all secrets from the old EncryptionSuite to the new one. Migration is performed in the user's browser (the decrypted private keys -- old for decrypt, new for encrypt -- are held as WebCrypto `CryptoKey` objects in JS memory only). Migration STATE is persisted server-side so that a closed tab does not lose progress.

Per-secret migration steps (all in browser):
1. Decrypt encrypted fields with old private key
2. Encrypt with new public key
3. POST updated encrypted fields to server
4. Server updates the secret's `encryption_suite_id` to the new suite and sets `possibly_compromised_at`

If re-encryption of a specific secret fails, the error MUST be recorded on the secret (`migration_error`) and migration MUST continue with the remaining secrets. The user MUST be informed of failures.

On migration completion:
- Locked SecretRequests MUST be unlocked and re-pointed to the new suite
- Link shares MUST be revoked during compromise recovery
- The old EncryptionSuite status MUST be set to `compromised`

#### Scenario: Pending requests locked during migration
- **WHEN** a user initiates compromise recovery
- **THEN** all pending SecretRequests for the old EncryptionSuite MUST be set to `locked` status
- **THEN** the fill-in page for those requests MUST return "temporarily unavailable"

#### Scenario: Requests unlocked after migration completion
- **WHEN** compromise recovery migration completes successfully
- **THEN** all locked SecretRequests MUST be set to `pending` status
- **THEN** the `encryption_suite_id` on those requests MUST be updated to the new suite
- **THEN** subsequent fill-in submissions MUST encrypt with the new suite's public certificate

#### Scenario: Tab closed mid-migration
- GIVEN migration is in progress and some secrets have been re-encrypted
- WHEN the user closes the browser tab
- THEN migration state MUST be persisted
- WHEN the user opens Doriath again
- THEN they MUST see a "migration paused" screen showing how many secrets remain

#### Scenario: Secret migration fails
- GIVEN a secret fails re-encryption during migration
- WHEN the migration run completes
- THEN the failed secret MUST have `migration_error` set
- AND the migration MUST continue with remaining secrets
- AND retrying requires providing the old (compromised) master password again

#### Scenario: Retry failed migration
- GIVEN one or more secrets failed migration in a previous session
- WHEN the user retries
- THEN the system MUST warn that secrets remaining on the compromised key are at increased risk
- AND MUST require the old master password to decrypt the still-compromised secrets
- AND on success MUST clear `migration_error` and set `possibly_compromised_at`
