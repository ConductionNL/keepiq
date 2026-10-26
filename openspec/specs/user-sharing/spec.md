# User Sharing Specification

**Status**: in-progress

**OpenSpec changes:**
- `implement-user-sharing` (2026-03-31) — Full implementation: user/group sharing, sync-on-update, notifications, share requests, delegation

## Purpose

@e2e exclude No user-sharing UI is built in v0.1; all sharing scenarios require the encrypted secret CRUD surface (itself unbuilt) — covered by integration tests, not Playwright UI flows.

A user can share a secret with another Nextcloud user or with a Nextcloud user group. Because encryption is asymmetric, sharing works by creating an encrypted copy of the secret using the recipient's public certificate. Both the original and all shared copies stay in sync: when the owner, a delegate or a recipient who holds a write grade on it updates the secret, the change is written back to all copies.

Group sharing expands statically to individual shares at share time. When new members join a group, the owner is notified and asked to approve adding those who don't hold a copy yet. When members leave a group, their group-derived shares are automatically revoked. A direct share is never created or removed by a change in group membership. What its holder may do with their copy does follow membership, though: a holder who is in a group the secret is shared with in full saves for everyone, also when they joined that group after their copy was made. The owner is then told, with nothing to approve (see `sharing-group`).

## Data Model

### SecretShare (user-to-user)

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `source_secret_id` | FK | No | The original secret being shared |
| `target_user_id` | string | No | Nextcloud user ID of the recipient |
| `secret_id` | FK | No | The encrypted copy in the recipient's vault — server-minted, owned by the recipient, and unique across shares |
| `group_share_id` | FK | No | Nullable — set if this share was created from a GroupShare; null for direct shares |
| `created_at` | datetime | No | |

The recipient's copy is itself a full `Secret` row, encrypted with the recipient's EncryptionSuite. The `SecretShare` links the two. The copy is minted by the server when the share is registered — its owner is always the recipient — so `secret_id` is never a client-supplied value, and a unique index on the column keeps any two shares from pointing at the same copy.

A `group_share_id` allows the system to identify which shares originated from a group share, enabling automatic revocation when a user leaves the group and targeted notifications when a new member joins.

### GroupShare

Tracks that a secret has been shared with a Nextcloud user group. One record per secret-group combination.

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `secret_id` | FK | No | The secret being shared with the group |
| `group_id` | string | No | Nextcloud group ID |
| `created_by` | string | No | Nextcloud user ID of the owner who created the share |
| `created_at` | datetime | No | |

### SecretDelegation

Tracks ownership delegation of a secret. Multiple active delegations per secret are allowed; all delegates hold co-owner rights simultaneously.

| Field | Type | Encrypted | Notes |
|-------|------|-----------|-------|
| `id` | UUID | No | Primary key |
| `secret_id` | FK | No | The secret being delegated |
| `original_owner_id` | string | No | Nextcloud user ID of the original owner |
| `delegated_to` | string | No | Nextcloud user ID of the delegate |
| `delegated_at` | datetime | No | When delegation was created |
| `initiated_by` | string | No | User ID of who created the delegation (may differ from original_owner_id for admin power grabs) |
| `is_permanent` | bool | No | false = temporary (owner can reclaim); true = permanent (owner's suite was revoked/deleted) |
| `made_permanent_at` | datetime | No | Null while temporary |

## Requirements

### Requirement: Share a Secret
The system MUST allow a user to share a secret they own with another Nextcloud user who has an active EncryptionSuite. The secret's owner or an ACTIVE delegate of it (SecretDelegation) MAY create the share; no one else can. `register-batch` and group-share create apply the same owner-or-delegate check, so a delegate can create both a direct share and a group share, and a plain recipient can create neither. Delegation does not widen who may share: only an active delegate qualifies.

Both paths share only an ORIGINAL secret as the source. A received copy — a secret that is itself some share target's recipient secret — MUST be refused as a source, which enforces at creation the "Recipients MUST NOT be able to share a secret directly" rule below: a recipient who owns their copy still cannot re-share it. The one exception is a caller who holds the copy and is an active delegate of its source: the copy resolves to the source, and every minted row hangs on the source. A delegate shares with the owner's authority but keeps their own copy's limits, whether they name their copy or the source: when that copy is use-only, expiring or read-only, or when they hold no copy of the source (revoked or expired) or more than one, the direct, group and link paths MUST refuse, so a restricted or ended grant can never mint a full, unrestricted copy.

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
- WHEN B uses that copy as the source of a direct share or a group share
- THEN both paths MUST refuse it, because a received copy is never a share source

#### Scenario: A delegate's restricted copy cannot mint a full copy
- GIVEN user D holds an active delegation on A's secret but D's own copy of it is use-only, expiring or read-only, or D holds no copy of it, or two
- WHEN D creates a direct share, a group share or a link share of the secret
- THEN all three paths MUST refuse the share as restricted, so a restricted or ended grant cannot mint a full copy

#### Scenario: Share with user without EncryptionSuite
- GIVEN user B has never opened Keepiq and has no EncryptionSuite
- WHEN user A attempts to share a secret with user B
- THEN the system MUST return an error indicating the recipient has no encryption suite

### Requirement: Sharing With A New Party Requires A Verified Key Proof
A session alone MUST NOT be able to add a new party who then receives a secret and every later value of it through sync (keepiq#818). The risky sharing shapes MUST carry a verified vault-key proof (a signature with the caller's active suite key over a single-use, purpose-bound challenge), enforced by `#[VaultKeyProofRequired]` and VaultKeyProofMiddleware:

- `POST /api/v1/shares/register-batch` (purpose `share-register-batch`), on every call. This is the only route that creates a user-to-user share: it mints the recipient's copy server-side, so there is no per-recipient exemption — every share carries a proof.
- `POST /api/v1/secrets/{secretId}/delegations` (purpose `delegation-create`, bound to `secretId` and `delegatedTo`) and `POST /api/v1/secrets/{secretId}/delegations/handover` (purpose `delegation-handover`, bound to `secretId`).

A missing or invalid proof MUST be refused with `428` and `error: key_proof_required` before the share or delegation is written (not `403`: Nextcloud's OCS layer turns a `403` of an OCS controller into an HTTP `200` envelope, so a refused first share read as a success), and audited as `KEY_PROOF_REFUSED`. The web client asks for the master password through one app-wide prompt, checks it against the session's key envelope, and asks once per bulk run.

#### Scenario: Batch registration and delegations always need a proof
@e2e exclude Covered by PHPUnit SharingKeyProofGuardTest and vitest on BulkShareDialog and the delegation store.
- GIVEN any user with an unlocked vault
- WHEN they register a batch of shares, create a delegation or take over a secret as vault admin without a vault-key proof
- THEN the request MUST be refused with `428` and `error: key_proof_required`
- AND no share target or delegation MUST be written

### Requirement: Recipient Shareability Lookup
Sharing requires the recipient's public certificate, so a client MUST be able to
learn, before it attempts a share, which of a set of candidate users can receive
one. The system MUST expose a lookup taking a caller-supplied list of user ids
and returning, for each, whether that user has an active EncryptionSuite and if
so their certificate.

The lookup MUST be a probe, never an enumeration: it MUST answer only about ids
the caller names, and the system MUST NOT expose any endpoint listing the users
who hold a suite. Certificates are public keys and safe to distribute, but the
set of users holding one is a membership disclosure gated by no sharing
permission.

A user who does not exist and a user with no active EncryptionSuite MUST be
reported identically, so the lookup cannot be used as a user-existence oracle.

Where a user has more than one active suite — which is normal for the duration
of a compromise-recovery migration — the lookup MUST return the NEWEST, matching
single-recipient resolution. Returning the older one would hand back the
certificate the owner is migrating away from, producing a copy the recipient
cannot open.

The number of DISTINCT recipients one lookup may cover MUST be bounded, and a
request exceeding the bound MUST be refused rather than truncated. The bound
counts recipients, not submitted entries: a list naming the same person twice
asks about one person. Deduplication MUST NOT be quadratic in the number of
entries, so that reaching the bound check costs work proportional to what was
actually asked.

Results MUST carry the user id they describe, and callers MUST correlate by
that id rather than by position: duplicate and malformed entries are dropped,
so the result may be shorter than the request. Returning the surviving ids in
first-seen order is a convenience, not a positional guarantee.

#### Scenario: Mixed candidates answered in one request
@e2e exclude Machine-to-machine lookup with no UI surface of its own; covered by ShareControllerTest.
- GIVEN user B has an active EncryptionSuite and user C does not
- WHEN a client looks up both B and C
- THEN the response MUST report B as shareable with their certificate
- AND report C as not shareable, without a certificate

#### Scenario: Unknown user is indistinguishable from one without a suite
@e2e exclude Machine-to-machine lookup with no UI surface of its own; covered by ShareControllerTest.
- GIVEN no user "nobody" exists and user C exists without an EncryptionSuite
- WHEN a client looks up both
- THEN both MUST be reported not shareable with the same reason

#### Scenario: Newest suite wins during a migration
@e2e exclude Machine-to-machine lookup with no UI surface of its own; covered by EncryptionSuiteMapperTest.
- GIVEN user B has two active EncryptionSuites from an in-flight compromise recovery
- WHEN a client looks up B
- THEN the certificate returned MUST be that of the most recently created suite

#### Scenario: Results are correlated by id, not position
@e2e exclude Machine-to-machine lookup with no UI surface of its own; covered by ShareControllerTest.
- WHEN a client submits a list containing duplicate and empty entries
- THEN each result MUST name the user it describes
- AND the result list MAY be shorter than the submitted list

#### Scenario: Oversized request refused
@e2e exclude Machine-to-machine lookup with no UI surface of its own; covered by ShareControllerTest.
- WHEN a client submits more ids than the bound allows
- THEN the request MUST be refused, and no lookup performed


### Requirement: Recipient Search Marks Who Cannot Receive a Share
The share dialog's recipient field MUST search Nextcloud users through Nextcloud's own sharee search, so it offers exactly the users the instance lets the owner share with. Each result MUST be marked when that user cannot receive a share yet because they hold no active EncryptionSuite (keepiq#37).

The marks MUST come from an endpoint that takes the search term and the user ids of one result page (at most 25) and answers, per id, whether that user holds an active suite. The endpoint MUST rerun the sharee search for the term as the caller and MUST answer only about ids that search returns: an id the caller could not have found through sharee search is left out of the answer and is never looked up. There MUST NOT be an endpoint that lists the users who hold a suite.

#### Scenario: Users without a vault are marked
@e2e exclude The marks need two seeded users with and without a suite and Nextcloud's sharee search; covered by RecipientStatusControllerTest and tests/components/recipientPicker.spec.js.
- GIVEN user A searches "al" and the sharee search returns alice, who has a suite, and albert, who does not
- WHEN the share dialog shows the results
- THEN alice MUST be selectable
- AND albert MUST be shown as not able to receive a share yet, and MUST NOT be selectable

#### Scenario: No answer about a user the search does not return
@e2e exclude Refusal of a crafted request; covered by RecipientStatusControllerTest::testAnswersNothingForUsersTheSearchDoesNotReturn.
- GIVEN user carol holds a suite but the sharee search for "al" does not return her for user A
- WHEN user A asks the endpoint about "carol" with the term "al"
- THEN the answer MUST NOT contain carol
- AND the system MUST NOT look up carol's suite

#### Scenario: More than one page is refused
@e2e exclude Input bound; covered by RecipientStatusControllerTest::testRefusesMoreThanOnePageOfUsers.
- GIVEN a request naming 26 distinct user ids
- WHEN it reaches the endpoint
- THEN the system MUST refuse it with 400
### Requirement: Sync on Update
When the owner, a delegate, or a recipient who holds a write grade on it updates a shared secret, the change MUST be propagated to all copies.

#### Scenario: Owner updates shared secret
- GIVEN a secret is shared with one or more users
- WHEN the owner updates the secret's value
- THEN the system MUST re-encrypt the updated value for each recipient and write it to their copy

#### Scenario: Recipient updates shared secret
@e2e tests/e2e/workflows/share-save.spec.ts
- GIVEN a secret has been shared with user B, who holds a write grade on it: a `write` or `manage` team-folder grade, or membership of a group the secret is shared with in full (not use-only)
- WHEN user B updates their copy of the secret
- THEN the system MUST re-encrypt the updated value for the original owner and all other recipients
- AND a recipient who holds the secret through a direct share only, without a write grade, MUST change only their own copy

### Requirement: Share with Group (Static Expansion)
The system MUST allow a secret owner to share a secret with a Nextcloud user group. At the moment of sharing, the system MUST create an individual SecretShare (with `group_share_id` set) for each current member of the group who has an active EncryptionSuite. A GroupShare record MUST be created to track the relationship.

Members without an active EncryptionSuite are skipped at expansion time. No error is returned for skipped members.

#### Scenario: Share with group
- GIVEN a user owns a secret and a Nextcloud group G has N members with active EncryptionSuites
- WHEN the owner shares the secret with group G
- THEN N SecretShares MUST be created (one per eligible member), each with `group_share_id` referencing the GroupShare
- AND a GroupShare record MUST be created for the secret-group pair

### Requirement: New Group Member — Owner Notification
When a new user joins a Nextcloud group that has a GroupShare for one or more secrets, the secret owner MUST be notified for each affected secret and asked to approve adding the new member, unless the new member already holds a copy of that secret (see below).

This follows the same pattern as the share request mechanism: the owner approves or denies; the system acts on their decision.

Notification content:
- Body: "User *{X}* joined group *{G}* — share *{secret}* with them?"
- Actions: Approve / Deny

On approval: a new SecretShare is created for X (with `group_share_id` set), and X is notified of the new share.
On denial: no share is created. The GroupShare remains active for future members.

A user who already holds a copy of the secret when they join, for example through a direct share, gets no second share and the owner is not asked to approve anything. When joining lets their copy save for everyone, as `sharing-group` requires from the moment they are in the group, the owner MUST instead get the `group_member_gained_write` notification, without Approve or Deny: "*{X}* joined the group *{G}* and can now change *{secret}* for everyone it is shared with. Revoke their share to stop this." It follows the owner's `notify_security` setting, not `notify_group_shares`. When joining gives them nothing, for example because the group share or their copy is use-only, or when they already save for everyone as a delegate or through a team-folder write grade, the owner MUST NOT be notified. A team-folder write grade that comes through the group just joined counts as already granting too. Another group's share MUST NOT count as already granting, even a full, live one, so when one directory sync or a nested group adds the holder to several shared groups at once, the owner MUST be notified for each of them unless an exemption above applies.

#### Scenario: A copy holder who joins a shared group can now change the secret

@e2e exclude Needs a group membership change on the test instance after the share; covered by PHPUnit GroupMemberWritesForEveryoneTest::testACopyHolderWhoJoinsASharedGroupLaterSavesForEveryone.

- GIVEN group G has a full GroupShare for a secret owned by A, and A shared the secret directly with X
- WHEN user X joins group G
- THEN A MUST receive a `group_member_gained_write` notification saying X can now change the secret for everyone, with no actions
- AND no second SecretShare MUST be created for X

#### Scenario: A copy holder who already saves through another group joins a second shared group

@e2e exclude Needs a group membership change on the test instance after the share; covered by PHPUnit GroupMemberWritesForEveryoneTest::testACopyHolderWhoAlreadySavesThroughAnotherGroupTellsTheOwner and ::testACopyHolderAddedToTwoSharedGroupsInOneSyncTellsTheOwnerForEach.

- GIVEN groups G and H both have a full GroupShare for a secret owned by A, and X holds a copy and is in group H
- WHEN user X joins group G
- THEN A MUST receive a `group_member_gained_write` notification for group G

#### Scenario: A team-folder writer or delegate who holds a copy joins a shared group

@e2e exclude Needs a group membership change on the test instance after the share; covered by PHPUnit GroupMemberWritesForEveryoneTest::testATeamFolderWriterWithACopyWhoJoinsASharedGroupTellsTheOwnerNothing, ::testADelegateWithACopyWhoJoinsASharedGroupTellsTheOwnerNothing and ::testATeamFolderReaderWithACopyWhoJoinsASharedGroupTellsTheOwner.

- GIVEN group G has a full GroupShare for a secret owned by A, and X holds a copy and is a delegate of A or holds a team-folder write grade on the secret
- WHEN user X joins group G
- THEN A MUST NOT receive a notification
- AND when X holds only a team-folder read grade instead, A MUST receive the `group_member_gained_write` notification

#### Scenario: New member joins group
- GIVEN group G has a GroupShare for a secret owned by A
- WHEN user X joins group G
- THEN A MUST receive a Nextcloud notification for each affected secret asking to approve the share
- AND on approval, a SecretShare MUST be created for X referencing the GroupShare
- AND X MUST receive a notification that the secret was shared with them

### Requirement: Member Leaves Group — Auto-Revocation
When a user leaves a Nextcloud group, all SecretShares for that user originating from a GroupShare for that group MUST be automatically revoked (deleted), along with the encrypted copies in the user's vault.

Direct shares (where `group_share_id` is null) are not removed: leaving a group does not remove access granted independently of the group, though the holder loses the right to save for everyone that the group gave them (see `sharing-group`).

#### Scenario: Member leaves group
- GIVEN user Y has a SecretShare for secret S with `group_share_id` referencing GroupShare for group G
- WHEN Y leaves group G
- THEN Y's SecretShare and encrypted copy MUST be automatically deleted
- AND if Y also has a direct SecretShare for S (group_share_id = null), that share MUST remain intact

### Requirement: Revoke Group Share
The secret owner MUST be able to revoke a GroupShare. On revocation, all SecretShares originating from that GroupShare MUST be cascade-deleted.

#### Scenario: Owner revokes group share
- GIVEN a GroupShare exists for group G and secret S, with M derived SecretShares
- WHEN the owner revokes the GroupShare
- THEN all M SecretShares with that `group_share_id` MUST be deleted
- AND the GroupShare record MUST be removed
- AND direct shares for the same secret (group_share_id = null) are unaffected

### Requirement: Notification on Share Received
When a secret is shared with a user, the recipient MUST receive a Nextcloud notification.

Notification content:
- Body: "*{User A}* shared a secret with you"
- Action link: opens the shared secret in the recipient's vault

#### Scenario: Secret shared with user
- GIVEN user A shares a secret with user B
- WHEN the share is created
- THEN B MUST receive a Nextcloud notification

### Requirement: Share Request (Recipient-Initiated)
Recipients MUST NOT be able to share a secret directly with a third party. Instead, a recipient MAY submit a share request to the original owner, asking them to share the secret with another Nextcloud user.

The share request flow:
1. Recipient B submits a request: "Please share *{secret}* with user C"
2. The original owner A receives a Nextcloud notification
3. A approves → the system creates a new `SecretShare` directly from A to C (identical to A having shared it themselves)
4. A denies → the request is dropped
5. B receives a Nextcloud notification of the outcome (accepted or denied) — no further detail

This keeps all shares flat (A→B, A→C, A→D, …), preserves owner control, and avoids sync and revocation complexity from re-share trees.

#### Scenario: Recipient requests a share
- GIVEN user B holds a shared copy of A's secret
- WHEN B submits a share request for user C
- THEN a Nextcloud notification MUST be sent to A
- AND B MUST receive a notification when A approves or denies

#### Scenario: Owner approves share request
- GIVEN A receives a share request from B for user C
- WHEN A approves it
- THEN the system MUST create a SecretShare from A to C as if A initiated it directly

#### Scenario: Owner denies share request
- GIVEN A receives a share request from B for user C
- WHEN A denies it
- THEN no share is created and B is notified of the denial

### Requirement: Share Visibility
Only the original owner of a secret MUST be able to see the full list of users the secret is shared with. Recipients MUST NOT see who else has access.

#### Scenario: Owner views share list
- GIVEN user A owns a secret shared with B and C
- WHEN A views the secret
- THEN A MUST see the full list of recipients

#### Scenario: Recipient views shared secret
- GIVEN user B holds a shared copy of A's secret
- WHEN B views the secret
- THEN B MUST NOT see the list of other recipients

### Requirement: EncryptionSuite Revocation — Share Cleanup
When a recipient's EncryptionSuite is **revoked** (deliberate decommissioning), all SecretShare records targeting that suite MUST be automatically cascade-deleted, including the encrypted copies in the recipient's vault. The original owner's copy is unaffected.

#### Scenario: Recipient's suite is revoked
- GIVEN user B holds shared copies of one or more secrets
- WHEN B's EncryptionSuite is revoked
- THEN all SecretShare records where B is the recipient MUST be deleted
- AND all encrypted copies in B's vault originating from a share MUST be deleted
- AND the original secrets owned by their respective owners remain intact

### Requirement: EncryptionSuite Compromise — Shared Copy Migration and Owner Notification
When a recipient's EncryptionSuite is **replaced due to compromise**, the suite migration process (see encryption-suites spec) MUST cover all `Secret` rows encrypted with the old suite — including shared copies held by the recipient. Those copies MUST be re-encrypted with the new suite and flagged `possibly_compromised_at` as part of the standard migration.

The additional responsibility of User Sharing is: when a shared copy is flagged `possibly_compromised_at` during migration, the **original owner of the secret MUST be notified** that the secret may have been compromised and its value should be replaced. The notification MUST point at the SOURCE secret, which the owner can open, not at the recipient's copy.

The SOURCE secret MUST itself be stamped `possibly_compromised_at` (when not already stamped) and carry a `suite_compromise` rotation flag. It is not sealed under the recipient's suite, so nothing else in the migration marks it, and without the stamp it does not appear in the owner's rotation and compliance views. The same holds when the recipient's suite is force-revoked as compromised (see encryption-suites: Administrator Force-Revocation).

A shared copy whose source no longer exists falls back to the copy and its holder. Any other failure to resolve the source MUST be logged, and a failure to mark one secret MUST NOT stop the cascade for the others.

When the owner replaces the secret value, sync-on-update (see Requirement: Sync on Update) propagates the new value to all copies, including the migrated copy in the recipient's new suite. Updating the value MUST unset `possibly_compromised_at` on all copies.

#### Scenario: Shared copy flagged during migration
- GIVEN user B holds a shared copy of a secret owned by A
- WHEN B's EncryptionSuite is replaced due to compromise and the copy is migrated
- THEN the copy MUST be flagged `possibly_compromised_at` (per encryption-suites migration)
- AND A MUST receive a Nextcloud notification: "A secret you shared may have been compromised — please replace its value", pointing at A's source secret
- AND A's source secret MUST be stamped `possibly_compromised_at` and flagged for rotation

#### Scenario: Shared copy on a force-revoked compromised suite
- GIVEN user B holds a shared copy of a secret owned by A
- WHEN an administrator force-revokes B's EncryptionSuite with `markCompromised: true`
- THEN both the copy and A's source secret MUST be stamped `possibly_compromised_at` and flagged for rotation
- AND A MUST be notified about the source secret

#### Scenario: Source secret is gone
- GIVEN a compromised shared copy whose source secret has been deleted
- WHEN the compromise cascade runs
- THEN the copy's holder MUST be notified about the copy
- AND nothing is logged as an error

#### Scenario: Owner replaces possibly-compromised secret value
- GIVEN A's secret (and its shared copies) is flagged `possibly_compromised_at`
- WHEN A updates the secret value
- THEN sync-on-update MUST propagate the new value to all copies
- AND `possibly_compromised_at` MUST be unset on the original and all copies

### Requirement: Ownership Delegation
The system MUST allow a secret's ownership to be delegated to one or more other users, granting them co-owner rights (share management, full recipient visibility, value updates). Multiple active delegations for the same secret MUST be allowed simultaneously.

**Who can create a delegation:**
- **Admin power grab**: a vault administrator can create a delegation for any secret that has already been shared with them. No owner consent is required.
- **User self-delegation**: the secret owner can create a delegation for their own secret to any user who already holds a share of that secret.

In both cases the delegatee MUST already hold a share — the delegation promotes their existing copy to co-owner status.

**Mechanics:** the delegate's copy is promoted to co-owner status; the original owner's copy is demoted to a regular share for the duration of the delegation. The existing sync-on-update mechanism ensures all copies remain consistent regardless of which co-owner updates the value.

#### Scenario: Admin creates delegation (power grab)
- GIVEN a vault administrator holds a share of secret S (owned by A)
- WHEN the admin creates a delegation for S
- THEN a SecretDelegation record MUST be created with `is_permanent = false`
- AND the admin gains co-owner rights: share management, full recipient visibility, value updates

#### Scenario: Owner self-delegates
- GIVEN user A owns secret S and user B holds a share of S
- WHEN A creates a delegation to B
- THEN a SecretDelegation record MUST be created with `is_permanent = false`
- AND B gains co-owner rights for S

#### Scenario: Delegatee does not hold a share
- GIVEN user C does not hold a share of secret S
- WHEN a delegation to C is attempted
- THEN the system MUST return an error — delegation requires a pre-existing share

### Requirement: Reclaim Delegation
The original owner MUST be able to reclaim ownership at any time while delegations are temporary, revoking all active delegations simultaneously.

#### Scenario: Owner reclaims
- GIVEN secret S has one or more active temporary delegations
- WHEN the original owner reclaims
- THEN ALL active SecretDelegation records for S MUST be removed
- AND all delegates are demoted back to regular share recipients
- AND the original owner is sole owner again

### Requirement: Permanent Transfer on Suite Revocation
When the original owner's EncryptionSuite is revoked or deleted, all temporary delegations for their secrets MUST automatically become permanent, except on an administrator's compromise force-revoke, which revokes them (keepiq#817, ADR-005).

#### Scenario: Original owner's suite revoked or deleted
- GIVEN secret S has one or more active temporary delegations (is_permanent = false)
- WHEN the original owner's EncryptionSuite is revoked or deleted
- THEN all SecretDelegation records for S MUST have `is_permanent` set to true and `made_permanent_at` set to now
- AND the original owner's (now inaccessible) copy MUST be deleted
- AND the delegates retain co-owner rights permanently — reclaim is no longer possible

#### Scenario: Original owner's suite force-revoked as compromised
- GIVEN the original owner has created one or more temporary delegations (is_permanent = false)
- WHEN an administrator force-revokes the owner's EncryptionSuite with `markCompromised: true`
- THEN every temporary SecretDelegation the owner created MUST be deleted instead of promoted
- AND each removal MUST be audited as `share.delegation_reclaimed` with the administrator as actor
- AND permanent delegations MUST NOT be touched

### Requirement: Revoke Share
The system MUST allow the original owner to revoke a share, removing the recipient's copy.

#### Scenario: Revoke share
- GIVEN a share exists between user A and user B
- WHEN user A revokes the share
- THEN the recipient's Secret copy MUST be deleted when it is owned by the share's target
- AND a linked copy that is not owned by the target MUST be left untouched, as a defence-in-depth guard against a mis-linked share
- AND the SecretShare record MUST be removed in either case

#### Scenario: Revoking a quarantined share is an explicit error
- GIVEN a share row the GHSA-m5p9 scan quarantined
- WHEN its source's owner or an active delegate revokes it
- THEN the system MUST answer `409` with `error: share_quarantined` and touch neither the row nor its copy
- AND anyone else MUST get the same not-found answer as for a missing share

### Requirement: Planted Share Rows Are Quarantined
Before GHSA-m5p9-6pw7-pf3g was fixed, a client could link a share row to any secret id, including a secret the target already owned. Rows planted that way MUST be quarantined; the scan never deletes a row or a secret. A quarantined row keeps `quarantined_at` and a `quarantine_reason`, and no runtime lookup in `ShareTargetMapper` or `BulkGrantShareTargetMapper` returns it, so it cannot read, write, delete, sync, delegate or alert through its copy. Only the admin tooling, the scan, the revoke refusal, the GDPR export and backups read quarantined rows.

The scan considers only rows whose copy was created in an earlier second than the row or has a `secret.created` audit entry, and quarantines such a row on the first of three signals that holds, checked in this order:
- its copy was the source of a share to anyone, created no later than the row, found in `share_targets` or in a `share.granted` audit entry naming the copy as its object (register-batch grants): the attacker learned the copy's id because it was shared with them or an accomplice, and a server-minted copy cannot have been a share source before its row;
- its copy has a `secret.created` audit entry (object type `secret`), so it was created as a secret of its own, which no server-minted copy is. This applies whatever the timing, so it catches a row planted in the same second as its copy, for example on a secret the attacker contributed to a team folder;
- its copy was created more than 60 seconds before the row. Every first-party client mints a copy in the same request as its row, so the window only covers a slow request or a third-party API client.

The scan runs once from the upgrade migration and then from the `ShareQuarantineScanJob` background job until one full pass completes, recorded in the `share_quarantine_scan_completed_at` app-config key. A failed pass MUST leave the key unset and log an error, so the job retries every 15 minutes and skips rows already quarantined; the upgrade itself MUST NOT fail because of the scan. While the key is unset, the admin overview MUST show a setup-check warning that planted rows are not quarantined yet. A restore whose archive holds share rows MUST clear the key before it writes them and MUST run the scan directly after the tables are written, before the attachment blobs and while maintenance mode is still on; a scan that does not complete MUST NOT fail the restore and stays pending for the job. A released row keeps its reason and is never quarantined again.

An admin manages quarantined rows with `occ keepiq:share-quarantine list|release <id> [--force]|drop <id> [--clear-restrictions]`. `list` shows who holds each copy, the copy's use-only flag and access end date and the row's own end date, since a planted row may have written those restrictions onto the target's own secret. `release` restores the row as a live share and MUST be refused while a live share for the same source and target exists. Without `--force` it MUST only release a row whose copy is gone or is held by the row's creator, as every legacy two-step copy was; a copy held by anyone else may be that person's own secret, which a released row would make look like a received copy again. It MUST also be refused unless `--force` is passed for a row quarantined because its copy was shared before the row, for a row whose copy belongs to the recipient (whatever the reason, since revoking the released share would delete it), for a row whose copy is the source of a live share, which a share copy may no longer be, and for a row whose share, linked group share or copy carries an access end date, because the expiry job revokes an ended share and deletes its copy; the refusal lists every reason at once, with the earliest end date. `drop` removes the row and keeps its copy; with `--clear-restrictions` it also clears the copy's `use_only` and `access_expires_at` when the row's recipient holds the copy. A legitimately shared use-only copy looks the same, so on a row quarantined by mistake this leaves the recipient a full copy nobody can revoke. Each quarantine is audited as `share.quarantined`, with the system as actor and the row's source, recipient, copy, reason and the copy's restrictions, and forwarded to the SIEM sinks; each release as `share.quarantine_released`, recording whether it was forced; each drop as `share.quarantine_dropped`, recording whether the copy's restrictions were cleared. A cascade that removes rows by source, target or group share removes quarantined rows too, without touching their copies, and the account-deletion report counts the received rows it removes, quarantined ones included.

#### Scenario: A planted row is inert after the upgrade
- GIVEN a quarantined row `{source: S_a, target: victim, secret: V}` where V is the victim's own secret
- WHEN the owner of S_a revokes, syncs, delegates or flags S_a as honey, or the victim edits, reads or shares V
- THEN V MUST be neither deleted nor written through the row, the victim's edits MUST NOT reach S_a, no alert MUST be raised on S_a, and the victim MUST be able to share V as an original

#### Scenario: A failed scan is retried
- GIVEN the quarantine scan throws during the upgrade
- WHEN the next cron run executes `ShareQuarantineScanJob`
- THEN the scan MUST run again over the rows not yet quarantined and set the completion key only after a full pass

#### Scenario: Releasing a row caught on the strong signal needs --force
- GIVEN a row quarantined because its copy was shared before the row
- WHEN an admin runs `occ keepiq:share-quarantine release <id>` without `--force`
- THEN the release MUST be refused, the row MUST stay quarantined and nothing MUST be audited
- AND with `--force` the row MUST be released and the audit entry MUST record `forced: true`

#### Scenario: A restore sets the scan pending again
- GIVEN the quarantine scan has completed
- WHEN an admin restores an archive that holds share rows
- THEN the completion key MUST be cleared before the rows are written and the scan MUST run directly after them, while maintenance mode is still on, also when replacing the attachment blobs fails afterwards

#### Scenario: Releasing a row whose copy anyone but its creator holds needs --force
- GIVEN a quarantined row whose copy belongs to its recipient, to a third party, or is the source of a live share, whatever its quarantine reason
- WHEN an admin releases it without `--force`
- THEN the release MUST be refused, because the copy may be the holder's own secret, which the released row would delete or link to the attacker's source
- AND a legacy row whose copy is still held by the row's creator, or whose copy is gone, with no other reason, MUST release without `--force`

#### Scenario: A same-second plant on a secret of its own is quarantined
- GIVEN member A contributed secret T to a team folder owned by B, and a row `{source: S_a, target: B, secret: T}` was created in the same second
- WHEN the quarantine scan runs
- THEN the row MUST be quarantined, on the first signal that holds: a member's copy minted from T in that second makes it the first, T's `secret.created` audit entry the second

#### Scenario: A release that would let the expiry job delete the copy needs --force
- GIVEN a quarantined row whose share, linked group share or copy carries an access end date
- WHEN an admin releases it without `--force`
- THEN the release MUST be refused with a message that names the date and points at `drop --clear-restrictions`

#### Scenario: Dropping a planted row can clear the restrictions it wrote
- GIVEN a quarantined row whose recipient holds the copy, and the copy is use-only or has an access end date
- WHEN an admin runs `occ keepiq:share-quarantine drop <id> --clear-restrictions`
- THEN the row MUST be removed, the copy MUST be kept with `use_only` and `access_expires_at` cleared, and the drop audit MUST record that the restrictions were cleared

## User Stories

- As a user, I want to share a password with a colleague so that we both have access to a shared account
- As a user, I want changes I make to a shared secret to be visible to my colleagues immediately
- As a user, I want to revoke a share when a colleague leaves the team
- As a user, I want to share a secret with an entire team group so that all current members get access at once
- As a user, I want to be notified when a new member joins a group I shared a secret with, so that I can decide whether to give them a copy
- As a user, I want shares derived from a group to be automatically cleaned up when a member leaves the group
- As an administrator, I want to take over management of a secret shared with me so that I can handle sharing when the owner is unavailable
- As a user, I want to delegate ownership of my secret to a trusted colleague so they can manage sharing on my behalf
- As a user, I want to reclaim sole ownership of my secret when I return, revoking all delegations at once

## Acceptance Criteria

- [ ] A secret can be shared with any Nextcloud user who has an active EncryptionSuite
- [ ] The recipient receives an encrypted copy in their own vault
- [ ] The copy is encrypted with the recipient's public certificate — the sender cannot read it after sharing
- [ ] The recipient receives a Nextcloud notification when a secret is shared with them
- [ ] Updates by the owner, a delegate or a recipient who holds a write grade on it propagate to all copies of the shared secret
- [ ] The original owner can revoke a share at any time
- [ ] Revoking a share deletes the recipient's copy
- [ ] Sharing fails with a clear error if the recipient has no EncryptionSuite
- [ ] Revoking a recipient's EncryptionSuite cascade-deletes all their SecretShare records and encrypted copies
- [ ] When a shared copy is flagged `possibly_compromised_at` during suite migration, the original owner receives a Nextcloud notification
- [ ] When the owner updates a possibly-compromised secret, sync-on-update propagates the new value to all copies and unsets the flag
- [ ] Recipients cannot share directly — they can only submit a share request to the original owner
- [ ] The original owner receives a Nextcloud notification for incoming share requests
- [ ] Approving a share request creates a new direct share from owner to the requested user
- [ ] The requester (B) is notified of the outcome (accepted or denied), with no further detail
- [ ] Only the original owner can see the full list of recipients; recipients cannot see who else has access
- [ ] A secret can be shared with a Nextcloud user group, creating individual SecretShares for all current eligible members
- [ ] A GroupShare record tracks each secret-group relationship
- [ ] Each group-derived SecretShare references its GroupShare via `group_share_id`
- [ ] When a new user joins a group, the secret owner receives a notification and must approve before the share is created
- [ ] When a user leaves a group, all their group-derived SecretShares are automatically revoked
- [ ] Direct shares (group_share_id = null) are not removed by group membership changes
- [ ] The owner can revoke a GroupShare, cascade-deleting all derived SecretShares
- [ ] A vault administrator can create a delegation for any secret already shared with them (no owner consent required)
- [ ] A secret owner can create a delegation for their own secret to any user who already holds a share
- [ ] Delegation fails if the delegatee does not already hold a share
- [ ] Multiple active delegations per secret are allowed; all delegates hold co-owner rights simultaneously
- [ ] Delegates can manage shares, see the full recipient list, and update values
- [ ] The original owner can reclaim at any time, revoking all temporary delegations simultaneously
- [ ] When the original owner's EncryptionSuite is revoked or deleted, all temporary delegations automatically become permanent
- [ ] On permanent transfer, the original owner's inaccessible copy is deleted and reclaim is no longer possible

## Notes

- The sync-on-update requirement means that updating a widely-shared secret triggers multiple re-encryption operations. For the initial implementation, this can be synchronous. Async fanout can be explored if performance becomes an issue.
- **Future consideration (flagged for later):** delegation currently requires the delegatee to already hold a share, due to the cryptographic constraint that no one can decrypt a secret they were never given a copy of. This means an admin can only take over a secret if it was proactively shared with them. A team policy should ensure that secrets vital to operations are always shared with a designated admin. Whether a mechanism to enforce this policy (e.g. mandatory admin share on creation) should be added to Keepiq is left for a future exploration session.
- Related ADRs: ADR-003 (encryption architecture — write-without-read property)
