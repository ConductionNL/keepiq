---
sidebar_position: 1
---

# Doriath

An encrypted secrets manager for Nextcloud — password manager and key store for users and applications.

> **Status: in development.** This documentation site is up so the
> brand surface and the eventual journeydoc tutorials have a stable
> home. Real walkthroughs and screenshots land as the UI lands. Watch
> the [GitHub repository](https://codeberg.org/Conduction/doriath) for
> milestones.

## What is Doriath?

Doriath is an encrypted vault built natively into Nextcloud. It stores secrets — passwords, API keys, tokens, SSH keys, certificates, and database credentials — encrypted at rest using RSA-4096 public-key cryptography. Private keys are protected by AES-256 encryption derived from a user's master password, ensuring zero-knowledge security: not even the server administrator can read your secrets.

Unlike standalone password managers (Bitwarden, 1Password) or infrastructure secret engines (HashiCorp Vault), Doriath lives where your team already works. It leverages Nextcloud's identity layer, group management, unified search, and notification system — so sharing a secret is as natural as sharing a file. A built-in private Certificate Authority (root + intermediate) signs all user and application certificates, enabling enterprise patterns like write-without-read secret requests and CSR-based application onboarding.

## Getting Started

- [Architecture & Data Model](./ARCHITECTURE) — Standards research, encryption architecture, entity definitions
- [Feature Analysis](./FEATURES) — Competitive landscape, 90-feature roadmap, and strategic positioning
- [Design References](./DESIGN-REFERENCES) — Wireframes, UX patterns, and design inspiration

Free and open source under the EUPL-1.2 license. For support, contact support@conduction.nl.
