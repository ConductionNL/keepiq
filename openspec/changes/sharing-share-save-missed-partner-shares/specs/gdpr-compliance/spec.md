## MODIFIED Requirements

### Requirement: Shared Secret Semantics on Account Deletion
The cascade MUST handle shared and delegated secrets with the following defined semantics:

- **Ownership transfer via delegation**: a secret with an active SecretDelegation transfers to the delegate — the delegation becomes permanent (per the user-sharing spec's `is_permanent` semantics for deleted/revoked owners), the delegate becomes owner, and the delegate's access continues uninterrupted. The delegate's own copy of the secret and its SecretShare link MUST stay, so the delegate's next save of the copy reaches the secret, its other remaining copies and its partner shares. When the link came from a GroupShare or a team folder, it stays as a direct share, so removing the group share, or a later change to the deleted user's team folder, never removes the delegate's copy. When the kept link names the deleted user as its maker, that is replaced with a marker that is not a valid Nextcloud user ID. When the runtime row judge does not trust the delegate's share row, or anything limits the delegate's access (the copy is use-only, has an end date or is read-only, the link carries a limit of its own, or any direct, group or team-folder grant reaching the copy is limited), that copy is detached like any other, so no limit is ever lifted or added
- **Detach with tombstone**: for shares the deleted user granted, each recipient's copy (already a full Secret row encrypted under the recipient's own suite) is kept by the recipient as an independent secret; the SecretShare link is deleted (sync severed) and the copy is marked with `tombstoned_at` and a non-personal `tombstone_reason` — the deleted user's ID, display name, or any other personal data MUST NOT be retained on the recipient's copy. GroupShares created by the user are detached the same way per member. This holds for the other recipients of a secret that passes to a delegate too; only the delegate's own copy is kept
- **Received shares removed**: copies in the deleted user's own vault and their SecretShare links are hard-deleted; the original owners' secrets are untouched

Tombstone fields are display metadata only (the UI shows the copy as shared by a deleted account and no longer synced); they MUST NOT restrict the recipient's access.

#### Scenario: Delegated secret transfers ownership
@e2e exclude Server-side cascade semantics — ownership transfer + permanent-delegation flip; covered by PHPUnit (AccountDeletionServiceTest delegation-transfer test).
- **WHEN** a user with an active delegation on a secret has their account data deleted
- **THEN** the delegate MUST become the owner of that secret
- **AND** the delegation MUST be marked permanent

#### Scenario: The delegate keeps their linked copy
@e2e exclude Server-side cascade semantics — the delegate's share row and copy survive the detach step; covered by PHPUnit (AccountDeletionServiceTest).
- **GIVEN** Alice's secret is shared with Dave and Carol, and Dave holds an active delegation on it
- **WHEN** Alice's account data is deleted
- **THEN** Dave's copy MUST stay linked to the secret and MUST NOT be tombstoned
- **AND** Carol's copy MUST be detached with a tombstone
- **AND** Dave's next share-save through his copy MUST write the secret and its partner shares
- **AND** Dave's kept share row MUST NOT name Alice as its maker

#### Scenario: A delegate's restricted copy is detached
@e2e exclude Server-side cascade semantics; covered by PHPUnit (AccountDeletionServiceTest).
- **GIVEN** Alice's secret reaches Dave through a use-only group share that ends at T, and Dave holds an active delegation on it
- **WHEN** Alice's account data is deleted
- **THEN** Dave MUST become the owner of the secret
- **AND** Dave's copy MUST be detached with a tombstone and stay use-only until T

#### Scenario: Recipient copy detached with tombstone
@e2e exclude Server-side cascade semantics — link severed + recipient copy tombstoned with a non-personal reason; covered by PHPUnit (AccountDeletionServiceTest granted-share detach test asserting no deleted-user identity on the copy).
- **WHEN** a user who shared a secret with a recipient has their account data deleted
- **THEN** the recipient MUST keep their copy as an independent, fully accessible secret
- **AND** the copy MUST be marked tombstoned with a reason that contains no personal data of the deleted user
- **AND** subsequent updates by the recipient MUST NOT attempt to sync anywhere

#### Scenario: Original owner unaffected by recipient deletion
@e2e exclude Server-side cascade semantics — received copies + links hard-deleted, original owner untouched; covered by PHPUnit (AccountDeletionServiceTest received-shares-removed test).
- **WHEN** a user who received a shared secret has their account data deleted
- **THEN** the received copy and its share link MUST be deleted
- **AND** the original owner's secret MUST remain unchanged
