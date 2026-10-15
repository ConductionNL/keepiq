# Changelog

All notable changes to Keepiq are recorded in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Keepiq uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Each entry names the OpenSpec change it comes from.

## [Unreleased]

### Added

- AI assistants can read vault metadata through three MCP tools: `listEntries`, `expiryReport` and `rotationStatus`. The tools return names, addresses, types, folders and dates, never a password, login or other secret value. They act only for the signed-in user and change nothing. Every call is written to the audit log with the tool name and the number of results. The tools appear only when OpenRegister is installed. (hermiq-ai-tooling)

### Fixed

- `keepiq ssh-agent` works as a systemd user service. Under systemd the agent moved to the background and left the unit, which systemd then stopped, so the agent never kept running. The new `--foreground` option keeps it in place, and the unit in `cli/README.md` now uses it.

### Removed

- The `vault_admin` group no longer counts as the People and offboarding admin area, and the General area no longer shows a notice about it. A member of that group who holds no delegation is now refused the admin handover and team offboarding. To keep those actions for them, delegate the People and offboarding area to their group on Nextcloud's administration privileges page before you upgrade. (admin-scoped-roles, #1043)

### Security

- Sharing with another organisation verifies the partner's certificate chain more strictly. The chain must be exactly the partner's intermediate and its root. Every certificate above the recipient's must be a CA allowed to sign certificates, within its path length, and every certificate in the chain must be valid now. The owner's server refuses any other chain before it reaches the browser and says the certificate could not be verified. Upgrading every instance that shares with partners is what protects its users. New user and application certificates are marked CA:FALSE, and the root and intermediate carry their CA constraints whatever the server's OpenSSL configuration says. Some user certificates issued before are marked CA:TRUE; an upgraded instance never accepts them as an issuer, and they become CA:FALSE when re-issued. If an unusual OpenSSL configuration issued an instance's root without CA constraints, upgraded partners refuse its chain until that root is renewed, which changes the fingerprint the partners have pinned. (federated-sharing)
- Sharing with someone at another organisation and enrolling in account recovery now encrypt to the public key of the certificate the browser verified. The browser accepts a certificate or key only as exactly one PEM block with nothing but whitespace around it and nothing after its encoded value, and refuses anything else. (federated-sharing, organisation-account-recovery)
- Keepiq records its decision not to take part in OpenRegister integration leaves: secret material and vault structure stay inside the vault's own access rules. A test now fails when a register schema declares `linkedTypes` or `mailObjectTemplate`, so the boundary cannot be crossed by accident. (leaf-integrations)
