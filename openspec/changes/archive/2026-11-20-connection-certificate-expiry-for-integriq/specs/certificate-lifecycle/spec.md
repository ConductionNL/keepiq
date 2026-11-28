## ADDED Requirements

### Requirement: A certificate minted for a connection carries its expiry
When a certificate secret is minted through the credential broker with a `notAfter`, keepiq SHALL store that date as the secret's `expires_at` without decrypting or changing the ciphertext, so the existing expiry scan reminds its owners. Replacing the certificate's value through the renewal flow SHALL update `expires_at` from the new certificate, and integriq SHALL see the new date on its next request.

#### Scenario: A renewed connection certificate shows its new expiry in integriq
- **GIVEN** a connection certificate in keepiq expiring in 54 days, referenced by an integriq source
- **WHEN** an administrator follows integriq's renew link and replaces the certificate with one valid for a year
- **THEN** the secret's `expires_at` SHALL hold the new date
- **AND** integriq's connection monitor SHALL show the new date
