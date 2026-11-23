## MODIFIED Requirements

### Requirement: Share a Secret
The system MUST allow a user to share a secret they own with another Nextcloud user who has an active EncryptionSuite. The secret's owner or an ACTIVE delegate of it (SecretDelegation) MAY create the share; no one else can. `register-batch` and group-share create apply the same owner-or-delegate check, so a delegate can create both a direct share and a group share, and a plain recipient can create neither. Delegation does not widen who may share: only an active delegate qualifies.

Both paths share only an ORIGINAL secret as the source. A received copy — a secret that is itself some share target's recipient secret — MUST be refused as a source, which enforces at creation the "Recipients MUST NOT be able to share a secret directly" rule below: a recipient who owns their copy still cannot re-share it. The one exception is a caller who holds the copy and is an active delegate of its source: the copy resolves to the source, and every minted row hangs on the source. A delegate shares with the owner's authority but keeps their own copy's limits, whether they name their copy or the source: when that copy is use-only, expiring or read-only, or when they hold no copy of the source (revoked or expired) or more than one, the direct, group and link paths MUST refuse, so a restricted or ended grant can never mint a full, unrestricted copy. A delegate other than the owner needs at least one grant on the copy that is on its own neither use-only nor expiring; grants are not combined for this, so a full grant that ends plus a permanent use-only one cannot mint a full copy, and a grant past its end date counts for nothing even before the expiry job removes it. The same rules guard changing a restriction: a delegate whose own copy may not be shared onward MUST NOT change any share's restriction (`PATCH /shares/{id}`), and a delegate MUST NOT change a share made to themselves or the restriction of a group share that reaches them. On the link path a delegate may name only their own copy; another recipient's copy is not found. This creation-time refusal covers `register-batch`, group shares, link shares and partner shares; on the partner path it has no delegate exception and answers 400 `not_shareable` (see `federated-sharing`). The team-folder fan-out does not apply it yet, so a plain recipient can still send their full copy out through it (a follow-up).

#### Scenario: Share with valid recipient
- GIVEN user A owns a secret and user B has an active EncryptionSuite
- WHEN user A shares the secret with user B
- THEN the system MUST create an encrypted copy of the secret in user B's vault using user B's public certificate
- AND create a SecretShare linking the original to the copy

#### Scenario: Active delegate creates a share
- GIVEN user A owns a secret and user D holds an active delegation on it
- WHEN user D registers a direct share or a group share of the secret
- THEN the system MUST create the share exactly as it would for the owner, minting each recipient's copy server-side
- AND when D names their own received copy of the secret, the share MUST be created on A's secret, so the minted row's source is A's secret
- AND a user who is neither the owner nor an active delegate MUST be refused on both paths

#### Scenario: A recipient may not re-share their copy
- GIVEN user B holds a shared copy of A's secret and is neither the owner nor an active delegate
- WHEN B uses that copy as the source of a direct share, a group share or a partner share
- THEN every path MUST refuse it, because a received copy is never a share source

#### Scenario: A delegate's restricted copy cannot mint a full copy
- GIVEN user D holds an active delegation on A's secret but D's own copy of it is use-only, expiring or read-only, or D holds no copy of it, or two, or none of the grants reaching D's copy is unrestricted on its own (for example a full share that ends plus a permanent use-only group share)
- WHEN D creates a direct share, a group share or a link share of the secret
- THEN all three paths MUST refuse the share as restricted, so a restricted or ended grant cannot mint a full copy

#### Scenario: A delegate cannot lift a restriction to escape their own
- GIVEN user D holds an active delegation on A's secret and a use-only copy of it, and user B holds a use-only share of it
- WHEN D changes the restriction of their own share, or of B's share, through `PATCH /shares/{id}`
- THEN both changes MUST be refused and both copies MUST stay use-only
- AND when D's copy is full, D MAY change B's restriction but still MUST NOT change their own

#### Scenario: Share with user without EncryptionSuite
- GIVEN user B has never opened Keepiq and has no EncryptionSuite
- WHEN user A attempts to share a secret with user B
- THEN the system MUST return an error indicating the recipient has no encryption suite

#### Scenario: A copy must carry the source's TOTP seed and passkeys
@e2e exclude A server-side refusal of a register-batch row; covered by PHPUnit (DirectShareRegistrarTest).
- GIVEN user A's secret holds a `totp` value and no `passkeys`
- WHEN a direct share or a group share row for user B leaves out `encryptedTotp`, or carries an `encryptedPasskeys`
- THEN that row MUST be reported with status `copy_fields_mismatch` and no copy or share row is written
- AND the other rows of the batch are handled as usual, as with any other per-row status

### Requirement: A share-save names every copy or is refused
The server MUST answer 409 `incomplete` with the required lists, and change nothing, unless `copies` plus `skipped` name exactly the copies the caller must write and `federated` plus `suspend` name exactly the active partner shares of the source. On the owner path the required copies MUST be every copy of the source that has not expired; on the copy-editor path they MUST be the source and every other copy. Partner shares MUST be required only when the caller owns the source, on the owner path or the copy-editor path; any other copy editor MUST NOT send any, and the response MUST say whether partner shares exist that the save couldn't reach. A `no_vault_key` skip MUST be refused when the holder has an active vault key. The check MUST be repeated inside the transaction.

#### Scenario: A recipient added during the save is caught
- GIVEN an owner who encrypted a change for one recipient
- WHEN a second recipient was added before the owner's share-save arrived
- THEN the server MUST answer 409 `incomplete` naming the second recipient's copy
- AND the browser MUST encrypt for that copy and send the save again

#### Scenario: A skipped holder who has a vault key is refused
- GIVEN a recipient with an active vault key
- WHEN the share-save lists that recipient's copy as skipped with `no_vault_key`
- THEN the server MUST answer 409 `incomplete` with that copy under `skipRefused`

#### Scenario: The owner saving through a copy must name the partner shares
- GIVEN Dave owns a secret, saves it through his own copy, and the secret has an active partner share
- WHEN Dave's share-save names no partner shares
- THEN the server MUST answer 409 `incomplete` naming that partner share
- AND a delegate who does not own the secret MUST still be refused with `federated_owner_only` when their save names it
