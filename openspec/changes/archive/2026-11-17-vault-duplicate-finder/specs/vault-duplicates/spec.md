## ADDED Requirements

### Requirement: Detect duplicates in the browser

The system MUST detect duplicate secrets among the secrets the user owns, in the browser, while the vault is unlocked, as part of the password health analysis. Secrets with the same address host, the same decrypted username and the same decrypted value MUST be grouped as exact duplicates; secrets with the same host and username and a different value MUST be grouped as likely duplicates. Passkey and authenticator secrets and recipient copies of other people's secrets MUST NOT be grouped. No group, digest or count MUST be sent to the server.

#### Scenario: A vault user sees duplicate logins

- **GIVEN** a vault user who owns two logins for `github.com` with the same username and password, saved from two browser imports
- **WHEN** the user opens the password health report at /password-health
- **THEN** the Duplicates section lists the two logins as one exact duplicate group
- **AND** no request to the server carries the group

### Requirement: Merge a duplicate group

The system MUST let the user merge a duplicate group by choosing the secret to keep. The kept secret MUST be saved with the other secrets' additional fields folded in, the kept secret's value winning on a clash and a clashing value being kept under a suffixed key, encrypted in the browser for the owner's active suite. The other secrets MUST then be deleted through the existing delete path. Before confirming, the system MUST say how many people lose access through shares of the secrets being removed. Likely duplicates MUST never be pre-selected for merging.

#### Scenario: A vault user merges an exact duplicate group

- **GIVEN** an exact duplicate group of two logins, one with an additional field `recovery email`
- **WHEN** the user keeps the other login and confirms the merge
- **THEN** one login remains and it carries the `recovery email` field
- **AND** the removed login no longer appears in the vault list

#### Scenario: Merging away a shared item is announced

- **GIVEN** a duplicate group where the item not kept is shared with two colleagues
- **WHEN** the user opens the merge confirmation
- **THEN** the confirmation says that two people lose access
