---
kind: code
---

# Proposal: Team folder sharing (shared vaults)

## Why

Doriath can share **individual** secrets (with users via `ShareService::createShare`, `lib/Service/ShareService.php:125`, and with groups via `GroupShareService::createGroupShare`, `lib/Service/GroupShareService.php:97`), but it has no concept of a **shared folder / team vault**. Folders today are strictly per-owner organisational metadata — the `Folder` entity carries a single `ownerId` (`lib/Db/Folder.php:135`) and the secrets spec states plainly that "Each user's folder tree is independent … the owner's folder organisation is not visible to or imposed on the recipient" (`openspec/specs/secrets/spec.md:205`). Sharing a team's worth of credentials therefore means sharing every secret one-by-one and re-doing it every time a secret is added.

This is the **#1 unmet wish in the Nextcloud secrets ecosystem**. Nextcloud Passwords (the incumbent, 500K+ installs — `docs/FEATURES.md:15`) has two of its most-upvoted open feature requests on exactly this: issue #582 "share to group" (63 👍) and #583 "share folder / share with folder" (60 👍), both open and unresolved since 2023. Every serious team competitor already ships folder/collection sharing while Doriath does not:

- **Bitwarden** — organization vaults with **collections** (`docs/FEATURES.md:308`); a collection is a shared container whose items are all accessible to the collection's members.
- **1Password** — shared vaults (the primary team-sharing unit).
- **Passbolt** — folders with team sharing (`docs/FEATURES.md:26`).
- **Teampass** — "Team folders" is its headline feature (`docs/FEATURES.md:29`).
- **Psono / Vaultwarden orgs** — folder/collection-level sharing.

Doriath's own roadmap flags group/folder team-sharing at **MVP** tier (`docs/FEATURES.md:133`, `:61`), yet only the flat per-secret group share is built — the folder dimension is missing.

**Crypto constraint (why this is not a symmetric-key "vault key").** Doriath sharing is defined as **per-recipient RSA re-encrypted copies** kept current by sync-on-update (ADR-003 `openspec/architecture/adr-003-rsa-aes-encryption-architecture.md:82`; `ShareService::syncUpdate`, `lib/Service/ShareService.php:384`). There is no shared symmetric key that a folder membership could simply hand out — introducing one would break the write-without-read / zero-knowledge model (ADR-003) and the per-recipient revocation semantics the whole app is built on. Team folder sharing must therefore be defined as **membership-driven fan-out of the existing per-secret share operation**: a folder's recipient set drives a set of per-secret shares, and the machinery to keep those copies in sync already exists. The group-membership propagation seams also already exist — `UserAddedToGroupListener` / `UserRemovedFromGroupListener` drive `GroupShareService::handleNewGroupMember` (`lib/Service/GroupShareService.php:251`) and `handleMemberLeave` (`:359`) — and the owner-transfer machinery for offboarding already exists as `DelegationService::createDelegation` / `makePermanent` (`lib/Service/DelegationService.php:132`, `:356`). This change composes those primitives; it invents no new crypto.

## What Changes

- Introduce a **TeamFolder** (shared folder / vault): a folder that, in addition to the existing per-user `Folder`, carries a recipient set of Nextcloud **users and groups**. Only the folder **owner** may share a folder or change its membership.
- **Inherited access by fan-out**: every secret placed in a team folder is automatically shared to the folder's full recipient set using the existing per-secret share path (`ShareService::createShare`) — one `SecretShare` per (secret, recipient) with a new `team_folder_id` provenance link, exactly mirroring how group shares carry `group_share_id` (`openspec/specs/user-sharing/spec.md:31`).
- **Add-to-folder → auto-share; remove-from-folder → auto-revoke**: moving a secret into a team folder fans out shares to all current recipients; moving it out (or deleting it) cascade-revokes the folder-derived copies, reusing `ShareService::revokeShare` (`lib/Service/ShareService.php:310`) and leaving independent direct shares intact (same isolation rule as group `group_share_id`).
- **Membership propagation** mirrors the existing group-share pattern: a user **joining** a shared group triggers an owner-approval notification before the fan-out share is created (per `openspec/specs/user-sharing/spec.md:100`); a user **leaving** auto-revokes their folder-derived shares (per `:119`). Direct group-member additions to a team folder by the owner require no approval.
- **Nested subfolders inherit** the nearest ancestor team folder's membership — a secret in a subfolder of a shared folder is shared to the ancestor's recipient set. A subfolder MAY additively widen (never narrow below) the inherited set; narrowing is out of scope for v1.
- **Admin offboarding hook**: a single admin action, given a leaving user, that (a) revokes every team-folder-inherited share held by that user and (b) transfers the team secrets that user **owned** to a named successor using the existing delegation/permanent-transfer mechanics (`DelegationService::createDelegation` + `makePermanent`, `lib/Service/DelegationService.php:132`, `:356`) — no bespoke transfer path.
- **Fan-out performance**: sharing a large folder or adding a member to a large team performs O(secrets × recipients) RSA re-encryptions in the owner's browser (WebCrypto, per ADR-003 `:89`); the change specifies a chunked/progress-reported client operation and idempotent server writes so a retry never double-shares.
- **Typed audit events** for team-folder share/unshare/member-add/member-remove/offboarding, dispatched via `OCP\EventDispatcher` for the (separately specced) audit trail, carrying only identifiers — never key material.
- **Explicitly out of scope for v1**: a shared symmetric folder key (breaks ADR-003), cross-owner co-ownership of the folder object itself (owner-only management), per-secret permission grades within a folder (read vs write), and narrowing a subfolder below its ancestor's membership.

## Capabilities

### New Capabilities
- `team-folder-sharing`: Shared folders / team vaults — share a whole folder with Nextcloud users and groups, secrets inside inherit access via membership-driven fan-out of per-secret RSA re-encrypted copies, add/remove propagates, membership changes propagate (join → owner-approved add; leave → auto-revoke), plus an admin offboarding action that revokes a leaver's inherited access and transfers their owned team secrets.

### Modified Capabilities
<!-- No existing capability's REQUIREMENTS change: user-sharing's per-secret share, sync, and revoke requirements are reused unchanged as fan-out primitives; secrets' Folder requirements are additive (a new shared-folder object), not modified. -->

## Impact

- **New DB tables**: `doriath_team_folders`, `doriath_team_folder_members`; new nullable `team_folder_id` provenance column on the existing share record (parallel to `group_share_id`).
- **New/extended services**: `TeamFolderService` (share/unshare/membership/offboarding), extends `ShareService`/`GroupShareService`/`DelegationService` seams.
- **New listeners**: team-folder branches in `UserAddedToGroupListener` / `UserRemovedFromGroupListener` (`lib/Listener/`).
- **New controller + routes**: `TeamFolderController` under `appinfo/routes.php`.
- **Frontend**: share-folder dialog, membership panel, offboarding admin action, fan-out progress UI (Vue 2 + WebCrypto).
- **OpenConnector**: unaffected — team folders are a browser-user collaboration feature; the machine `doriath://` secret-store path (`docs/FEATURES.md:186`) does not consume folders.
- **Security**: no new crypto; all fan-out is the existing per-recipient RSA re-encryption. Zero server-side plaintext exposure preserved (ADR-003).
