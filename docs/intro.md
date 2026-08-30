---
sidebar_position: 1
---

# Keepiq

A zero-knowledge encrypted secrets manager for Nextcloud — a personal vault for users and a write-without-read credential store for applications.

> **Status: public beta.** Keepiq is functionally complete for its MVP
> feature set (encryption, vault, sharing, link sharing, secret
> requests, application management) and is not yet listed on the
> Nextcloud app store. Tutorial screenshots are still being captured —
> watch the [repository](https://github.com/ConductionNL/keepiq) for
> milestones.

## What is Keepiq?

Keepiq is an encrypted vault built natively into Nextcloud. It stores secrets — passwords, API keys, tokens, SSH keys, certificates, and database credentials — encrypted at rest using RSA-4096 public-key cryptography. Private keys are protected by AES-256 encryption derived from a user's master password, ensuring zero-knowledge security: not even the server administrator can read your secrets. Keepiq keeps its own database tables (it does not store vault data in OpenRegister) so the encryption boundary never crosses an intermediary service.

Unlike standalone password managers (Bitwarden, 1Password) or infrastructure secret engines (HashiCorp Vault), Keepiq lives where your team already works. It leverages Nextcloud's identity layer, group management, unified search, and notification system — so sharing a secret is as natural as sharing a file. A built-in private Certificate Authority (root + intermediate, with automatic renewal) signs all user and application certificates, enabling enterprise patterns like write-without-read secret requests, temporary ownership delegation, and CSR-based application onboarding. Keepiq also serves as the storage leaf behind OpenRegister's credential broker, so applications that reach external systems through OpenRegister keep their credentials in Keepiq's custody.

## Getting Started

- [Architecture & Data Model](./ARCHITECTURE) — Standards research, encryption architecture, entity definitions
- [Feature Analysis](./FEATURES) — Competitive landscape, 90-feature roadmap, and strategic positioning
- [Design References](./DESIGN-REFERENCES) — Wireframes, UX patterns, and design inspiration

Free and open source under the EUPL-1.2 license. For support, contact support@conduction.nl.
