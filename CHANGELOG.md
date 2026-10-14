# Changelog

All notable changes to Keepiq are recorded in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Keepiq uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Each entry names the OpenSpec change it comes from.

## [Unreleased]

### Added

- AI assistants can read vault metadata through three MCP tools: `listEntries`, `expiryReport` and `rotationStatus`. The tools return names, addresses, types, folders and dates, never a password, login or other secret value. They act only for the signed-in user and change nothing. Every call is written to the audit log with the tool name and the number of results. The tools appear only when OpenRegister is installed. (hermiq-ai-tooling)
- A change to a shared secret's value, login or additional fields now reaches every copy in one request, `PUT /api/v1/secrets/{id}/share-save`: the editor's row, every recipient's copy and every partner share are saved together or not at all. A save made from an older version, or one that misses a recipient added in the meantime, is refused and nothing changes. Saves for more than 100 copies are sent in chunks and committed at once; the chunks wait in a new table and can be used for 30 minutes. Partner instances are told to pull only after the save is committed. The old `/sync` and `PUT /api/v1/federated-shares/{id}` routes keep working for older web-app tabs and API clients. `POST /api/v1/versions/{id}/restore` takes an optional `baseUpdatedAt` and answers 409 when the secret changed since, and suspending a partner share that is no longer active now answers 404. (sharing-atomic-share-save, #1254)

### Fixed

- Editing a shared secret in the web app updates the recipients' copies again. The copies were never updated, a write-grade member who changed only the password cleared the login and additional fields on the source, and a failed update showed as saved. A failed save now shows an error and changes nothing. (sharing-atomic-share-save, #1253)
- A save through `PUT /api/v1/secrets/{id}`, a sync through `PUT /api/v1/secrets/{id}/sync` and a version restore could overwrite a change that landed between their read and their write, such as a share-save from the web app while the browser extension saved. They now read and write in one step, so a change that lands meanwhile is kept in the version history instead of overwritten. A save or sync with a base version answers 409, as do a sync or restore without one; a save without one, filling a secret request and setting an expiry try once more on the current version, and answer 409 only if it moves again. (sharing-atomic-share-save)
- A delegate or write-grade member who saves a shared secret through their copy is now recorded in the audit log on the owner's secret too, with the holders that were left out. A restore made through a share-save is recorded as the version the client restored from. (sharing-atomic-share-save)
- Revoking a partner share while an update to that partner was still being sent could leave the partner's copy in place: the slower update replaced the pending unshare, so the unshare was never sent. An update now never replaces a pending unshare. (sharing-atomic-share-save)
- Suspending a partner share, also by saving without an unreachable partner or by removing the partner, now drops an update still waiting for its retry. The retry job kept contacting that partner and then showed the suspended share as failed. The retry job also checks each share again just before it sends, so only a send already under way can still arrive once. (sharing-atomic-share-save)
- When the partner deletes its copy of a share whose revocation is still waiting to reach it, or whose revocation was given up, the revocation is now complete and the share is removed. It used to keep waiting for an unshare the partner no longer needed, or stay behind as failed once the retries gave up. (sharing-atomic-share-save)
- A decline and a later accept from the partner no longer make a suspended partner share live again. Only the owner ends a suspension, by revoking the share or sharing again. The decline is still recorded in the owner's audit log. (sharing-atomic-share-save)
- Partner shares now pass to the new owner when a secret is handed over, through a delegation when the owner's account is deleted or through team offboarding. They stayed with the previous owner: the new owner could not revoke or suspend them, while after team offboarding the previous owner still could. The upgrade hands shares left behind this way to their secret's owner, and a partner share that isn't the secret owner's is no longer served. (sharing-atomic-share-save)
- `keepiq ssh-agent` works as a systemd user service. Under systemd the agent moved to the background and left the unit, which systemd then stopped, so the agent never kept running. The new `--foreground` option keeps it in place, and the unit in `cli/README.md` now uses it.

### Removed

- The `vault_admin` group no longer counts as the People and offboarding admin area, and the General area no longer shows a notice about it. A member of that group who holds no delegation is now refused the admin handover and team offboarding. To keep those actions for them, delegate the People and offboarding area to their group on Nextcloud's administration privileges page before you upgrade. (admin-scoped-roles, #1043)

### Security

- Keepiq records its decision not to take part in OpenRegister integration leaves: secret material and vault structure stay inside the vault's own access rules. A test now fails when a register schema declares `linkedTypes` or `mailObjectTemplate`, so the boundary cannot be crossed by accident. (leaf-integrations)
