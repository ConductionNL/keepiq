# Changelog

All notable changes to Keepiq are recorded in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Keepiq uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Each entry names the OpenSpec change it comes from.

## [Unreleased]

### Added

- AI assistants can read vault metadata through three MCP tools: `listEntries`, `expiryReport` and `rotationStatus`. The tools return names, addresses, types, folders and dates, never a password, login or other secret value. They act only for the signed-in user and change nothing. Every call is written to the audit log with the tool name and the number of results. The tools appear only when OpenRegister is installed. (hermiq-ai-tooling)

### Removed

- The `vault_admin` group no longer counts as the People and offboarding admin area, and the General area no longer shows a notice about it. A member of that group who holds no delegation is now refused the admin handover and team offboarding. To keep those actions for them, delegate the People and offboarding area to their group on Nextcloud's administration privileges page before you upgrade. (admin-scoped-roles, #1043)

### Security

- Keepiq records its decision not to take part in OpenRegister integration leaves: secret material and vault structure stay inside the vault's own access rules. A test now fails when a register schema declares `linkedTypes` or `mailObjectTemplate`, so the boundary cannot be crossed by accident. (leaf-integrations)
