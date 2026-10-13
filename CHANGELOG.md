# Changelog

All notable changes to Keepiq are recorded in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Keepiq uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Each entry names the OpenSpec change it comes from.

## [Unreleased]

### Added

- AI assistants can read vault metadata through three MCP tools: `listEntries`, `expiryReport` and `rotationStatus`. The tools return names, addresses, types, folders and dates, never a password, login or other secret value. They act only for the signed-in user and change nothing. Every call is written to the audit log with the tool name and the number of results. The tools appear only when OpenRegister is installed. (hermiq-ai-tooling)
- A change to a shared secret's value, login or additional fields now reaches every copy in one request, `PUT /api/v1/secrets/{id}/share-save`: the editor's row, every recipient's copy and every partner share are saved together or not at all. A save made from an older version, or one that misses a recipient added in the meantime, is refused and nothing changes. Saves for more than 100 copies are sent in chunks and committed at once; the chunks wait in a new table and can be used for 30 minutes. Partner instances are told to pull only after the save is committed. The old `/sync` and `PUT /api/v1/federated-shares/{id}` routes keep working until the browser extension has moved. `POST /api/v1/versions/{id}/restore` takes an optional `baseUpdatedAt` and answers 409 when the secret changed since, and suspending a partner share that is no longer active now answers 404. (sharing-atomic-share-save, #1254)

### Fixed

- Editing a shared secret in the web app updates the recipients' copies again. The copies were never updated, a write-grade member who changed only the password cleared the login and additional fields on the source, and a failed update showed as saved. A failed save now shows an error and changes nothing. (sharing-atomic-share-save, #1253)
- Revoking a partner share while an update to that partner was still being sent could leave the partner's copy in place: the slower update replaced the pending unshare, so the unshare was never sent. An update now never replaces a pending unshare. (sharing-atomic-share-save)
- `keepiq ssh-agent` works as a systemd user service. Under systemd the agent moved to the background and left the unit, which systemd then stopped, so the agent never kept running. The new `--foreground` option keeps it in place, and the unit in `cli/README.md` now uses it.

### Removed

- The `vault_admin` group no longer counts as the People and offboarding admin area, and the General area no longer shows a notice about it. A member of that group who holds no delegation is now refused the admin handover and team offboarding. To keep those actions for them, delegate the People and offboarding area to their group on Nextcloud's administration privileges page before you upgrade. (admin-scoped-roles, #1043)

### Security

- Keepiq records its decision not to take part in OpenRegister integration leaves: secret material and vault structure stay inside the vault's own access rules. A test now fails when a register schema declares `linkedTypes` or `mailObjectTemplate`, so the boundary cannot be crossed by accident. (leaf-integrations)
