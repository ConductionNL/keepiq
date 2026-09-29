# Tasks: use-only shares and expiring shares

## 1. Data and resolver

- [ ] 1.1 Add the migration step for the new columns on share targets, group shares, team-folder members and secrets, and bump `<version>`. Verify: a PHPUnit migration test asserts the columns and the `access_expires_at` index.
- [ ] 1.2 Add `ShareRestrictionResolver` that materialises `use_only` (all grants use-only) and `access_expires_at` (latest end, none wins) onto each copy, and call it from every share, group share and membership write. Verify: PHPUnit for single grants, mixed grants and removal of the last restricted grant.

## 2. Setting the flags

- [ ] 2.1 Accept `useOnly` and `expiresAt` on `ShareController::create`, `createBatch`, `registerBatch` and `GroupShareController::create`, and add `PATCH /api/v1/shares/{id}` for the owner. Verify: PHPUnit refuses a past date and a change by the recipient.
- [ ] 2.2 Accept `useOnly` (with grade `read` only) and `expiresAt` on team-folder member add and `PATCH`. Verify: PHPUnit refuses `useOnly` with `write` or `manage`.
- [ ] 2.3 Add the checkbox, the date and the honest use-only text to the share dialog and the team-folder dialog, with the writing skill. Verify: vitest renders both options and the text.

## 3. Server refusals and use recording

- [ ] 3.1 Refuse every share source that is a use-only or expiring copy (direct, batch, register-batch, group, link share, delegation, handover) and exclude such copies from team-folder subtree refs. Verify: PHPUnit for each path.
- [ ] 3.2 Refuse recipient updates and sync of a use-only copy, its version history ciphertext for the recipient, and its value ciphertext in the recipient's GDPR export. Verify: PHPUnit for each refusal.
- [ ] 3.3 Add `POST /api/v1/secrets/{id}/used` (recipient of a use-only copy only) with a whitelisted `secret.used` audit event visible in the owner's activity tab. Verify: PHPUnit for the route guard and the audit metadata.

## 4. Client refusals

- [ ] 4.1 Web app: hide reveal and copy of the value and additional fields, the edit dialog and version reveal for use-only copies, and exclude them from exports and bulk share. Verify: vitest for `PasswordField.vue`, `CopyButton.vue`, `SecretDetailSidebar.vue`, `VersionHistoryPanel.vue` and the export builder.
- [ ] 4.2 Extension: strict site match, password-field-only fill, no display or copy, no save prompt, and a `used` report per fill. Verify: vitest in `tests/extension/` for each rule.
- [ ] 4.3 CLI: refuse `show`, `get key` and `copy key` for use-only copies and mark them in `list`. Verify: a Go test with a use-only row.

## 5. Expiry

- [ ] 5.1 Filter expired copies on every recipient read path (list, get, extension match, unified search, offline manifest). Verify: PHPUnit asserts an expired copy is not returned by any of them one second after its end.
- [ ] 5.2 Add `ExpireSharesJob` (every 15 minutes) that revokes expired targets, removes expired memberships and re-runs the resolver. Verify: PHPUnit for a direct share, a group-derived share and a membership.
- [ ] 5.3 Add the `share_access_ending` and `share_access_ended` notifications and the owner's end-of-access notice with the rotation hint. Verify: PHPUnit for the subjects and the two owner texts.
- [ ] 5.4 Make the offline client refuse to decrypt a copy past `accessExpiresAt`. Verify: vitest with a snapshot holding an expired copy.

## 6. End to end

- [ ] 6.1 Add a Playwright flow: the owner shares a login use-only with a one-day end; the recipient sees no reveal or copy in the web app; after the end date (clock moved in the test) the secret is gone from the recipient's list. Verify: the Playwright spec passes in the E2E job.

## Acceptance criteria

- An owner can mark a user share, group share or read-grade team-folder membership as use-only, and give any of them an end date.
- Keepiq's web app, extension and CLI never show, copy, export or edit the value of a use-only copy; the extension still fills it on the matching site.
- The share dialog states that use-only is enforced by Keepiq's apps and can be bypassed by a technically skilled recipient.
- The server refuses every onward share of a use-only or expiring copy, recipient edits of a use-only copy, and its version reveal.
- From its end date the server serves an expired copy on no read path, and the job removes it within 15 minutes.
- The owner is told when access ended, with a rotation hint when the recipient could see the value.
