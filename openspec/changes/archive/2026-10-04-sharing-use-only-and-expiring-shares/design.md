# Design: use-only shares and expiring shares

## Context

Code at development `4c214a9d`:

- A share is a recipient-owned `Secret` copy plus a `ShareTarget` row (`share_targets`: source, target user, copy id, optional group share id and team folder id; `lib/Migration/Version001000Date20260908000000.php:644`). Copies are created by `lib/Service/RecipientSecretCopyService.php:75` (owner type `user`, owner id the recipient, `:112` to `:113`).
- Direct shares from the web app go through `POST /api/v1/shares/register-batch` (`lib/Controller/ShareController.php:278`, `lib/Service/DirectShareRegistrar.php:88`); single and batch creates are `ShareController::create` (`:120`) and `createBatch` (`:193`). Group shares are `lib/Controller/GroupShareController.php:103` over `group_shares` (`:366` in the migration, no expiry column). Team-folder members are `TeamFolderMemberController::addMember` (`:119`) and `setMemberGrade` (`:236`, `PATCH /api/v1/team-folders/{id}/members/{memberId}`), over `team_folder_members` (`:724`).
- Revocation deletes the copy and the target row (`lib/Service/ShareRevocationService.php:92`); team-folder member removal revokes derived shares for users no longer covered (`lib/Service/TeamFolderService.php:313`).
- `secrets.expires_at` already exists and means credential expiry for rotation (`:632`); it is not access expiry.
- Read paths a recipient uses: `SecretMapper::findByOwner` (`lib/Db/SecretMapper.php:115`), `findById` (`:76`), `searchByNameOrUrl` (`:396`, the extension match), `findForUnifiedSearch` (`:425`), and the offline manifest (`lib/Service/OfflineManifestService.php:88`).
- Reveal and copy in the web app: `src/components/PasswordField.vue` (reveal toggle), `src/components/CopyButton.vue`, `src/components/SecretDetailSidebar.vue`, `src/components/VersionHistoryPanel.vue`; exports under `src/export/` and `src/cxf/`. The extension decrypts in `browser-extension/src/background/service-worker.js:113` and copies codes in the popup; the CLI reveals in `cli/main.go` (`show` `:180`, `get` `:203`, `copy`).
- Background job pattern: `lib/BackgroundJob/ExpireSecretRequestsJob.php:51` (`TimedJob`, hourly).

## Goals / Non-Goals

**Goals:**

- An owner can let a colleague sign in with a shared login through the extension without Keepiq showing or copying the password to them.
- An owner can give access until a date, after which the server stops serving the copy and removes it.
- Neither flag can be escaped by sharing the copy onward.
- The product tells the owner honestly what use-only does and does not stop.

**Non-Goals:**

- Cryptographic use-only. It cannot exist in a vault where the recipient's device fills the password; see the security section.
- Use-only for `write` or `manage` team-folder grades. Editing a value you cannot see is not offered.
- Expiry on public links and sends, which already have their own.
- Hiding the name, URL or login name of a use-only secret. Only the secret value and the additional fields are hidden.

## Decisions

### D1: Two flags, set by the sharer, materialised on the copy

`use_only` (boolean) and `expires_at` (datetime, nullable) are stored on `share_targets` for direct shares, on `group_shares` (inherited by the group's derived targets), and on `team_folder_members`. A `ShareRestrictionResolver` materialises the effective values onto the recipient copy as `secrets.use_only` and `secrets.access_expires_at`, so every client and read path sees them without a join. It runs on share create and change, group share create and change, membership add, change and removal, and in the expiry job.

For a copy reached through several grants (a direct share and a team folder, or two memberships), the copy is use-only only when every grant is use-only, and the access end is the latest end date, with no end date winning. The most generous grant wins, as it does for grades.

Alternative considered: computing the flags at read time with joins. Rejected: the extension match, the offline manifest and the CLI list all read owned rows directly; a column keeps each of them one query.

### D2: The owner sets both in the dialogs; only the owner changes them

The share dialog and the team-folder dialog get a "Use only (can sign in, cannot view or copy)" checkbox and an optional "Access ends on" date. `use_only` on a membership is accepted only with grade `read`. A date in the past is refused. The owner, and a team-folder manager where `sharing-team-folder-manager-role` applies, can change both later through `PATCH /api/v1/shares/{id}` and the existing membership `PATCH`. The recipient cannot change either: they are not in the recipient-updatable secret fields.

### D3: What the clients must refuse for a use-only copy

Web app: no reveal toggle in `PasswordField.vue`, no copy in `CopyButton.vue` for the value and additional fields, no value in the detail sidebar, no edit dialog, no reveal in version history, no value in any export (backup, CXF, CXP transfer, print), and exclusion from bulk export and bulk share. The name, URL and login name stay visible. A current TOTP code may be shown because signing in needs it; the seed is never shown.

Extension: fill on a site whose registrable domain matches the copy's URL, with no "fill anyway" on a mismatch; fill only into a field of type `password`; never show or copy the value; never offer the save-or-update prompt for that copy; report each fill to `POST /api/v1/secrets/{id}/used`.

CLI: `show`, `get key` and `copy key` refuse with "This secret is use-only. Sign in through the Keepiq browser extension." `list` shows it with a use-only marker.

### D4: What the server refuses for a use-only or expiring copy

- Any share whose source is such a copy: direct, batch, register-batch, group, team-folder fan-out (the copy is excluded from subtree refs), link share, delegation and handover.
- Recipient-side updates and sync of a use-only copy.
- Version history ciphertext of a use-only copy for its recipient.
- The value ciphertext of a use-only copy in the recipient's GDPR export (metadata stays).

These refusals are real: they hold even against a modified client.

### D5: Expiry is enforced on every read, then cleaned up

Every recipient read path adds `access_expires_at IS NULL OR access_expires_at > now`, so a copy stops being served at its end date, not at the next job run. `ExpireSharesJob` (a `TimedJob` every 15 minutes) revokes expired share targets through `ShareRevocationService`, removes expired memberships through the team-folder removal path, and re-runs the resolver for copies still covered by another grant. The offline manifest carries `accessExpiresAt`; the offline client refuses to decrypt a copy past it and drops it at the next sync.

### D6: Notifications and honesty

A day before the end date the recipient gets `share_access_ending`. When access ends, the recipient gets `share_access_ended` and the owner gets a notification that says, for a share that was not use-only, "Bob could see this password. Rotate it if Bob should no longer know it", and for a use-only share, "Bob could not view this password in Keepiq". The share dialog shows, next to the use-only checkbox: "Keepiq's apps will not show or copy the password. Someone with technical skill can still read it from their own device. Rotate it when their access ends."

## Security and zero-knowledge

Use-only does not change what the server or the recipient's device holds. The recipient's copy is still encrypted to the recipient's certificate, because the recipient's device must decrypt the password to fill it (ADR-003). A recipient who uses a modified client, a debugger, or their own private key against the API can read it. This is the same limit Bitwarden and 1Password document for their equivalent permission. What use-only guarantees is narrower and real: Keepiq's own apps never display or copy the value, the server refuses every onward path it can see (D4), and each fill is recorded.

Expiry is server-enforced: after the end date the server never serves the copy's ciphertext to the recipient, then deletes it. It cannot remove what the recipient already learned or what an offline snapshot on their device already holds until that device syncs; D5 and D6 handle both honestly.

Stored in plain: the two flags on share targets, group shares, memberships and copies. They are access metadata, not secrets. Nothing new is stored encrypted.

## Risks / Trade-offs

- **Owners may over-trust use-only.** D6 puts the limit in the dialog and in the end-of-access notice.
- **A use-only login on a site with a non-standard login flow** may not fill; the recipient then cannot sign in and asks the owner. Acceptable for the purpose.
- **Clock skew** between the database and PHP. Comparisons use the database time in queries and the job, so one clock decides.
- **The fifteen minute job interval** does not delay the end of access, because reads already filter (D5); it only delays the cleanup.

## Seed data

None. Keepiq owns its tables (ADR-001) and has no OpenRegister register. Tests create an owner, a recipient, a group and a team folder in the PHPUnit and Playwright setups.

## Migration

A new migration step adds `use_only` (boolean, default false) and `expires_at` (datetime, nullable) to `keepiq_share_targets`, `keepiq_group_shares` and `keepiq_team_folder_members`, and `use_only` (boolean, default false) and `access_expires_at` (datetime, nullable, indexed) to `keepiq_secrets`. Existing rows stay unrestricted. The `<version>` in `appinfo/info.xml` must bump.
