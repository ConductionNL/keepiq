# Changelog

All notable changes to Keepiq are recorded in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Keepiq uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Each entry names the OpenSpec change it comes from.

## [Unreleased]

### Added

- AI assistants can read vault metadata through three MCP tools: `listEntries`, `expiryReport` and `rotationStatus`. The tools return names, addresses, types, folders and dates, never a password, login or other secret value. They act only for the signed-in user and change nothing. Every call is written to the audit log with the tool name and the number of results. The tools appear only when OpenRegister is installed. (hermiq-ai-tooling)

### Security

- Keepiq records its decision not to take part in OpenRegister integration leaves: secret material and vault structure stay inside the vault's own access rules. A test now fails when a register schema declares `linkedTypes` or `mailObjectTemplate`, so the boundary cannot be crossed by accident. (leaf-integrations)
