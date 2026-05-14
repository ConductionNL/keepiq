---
sidebar_position: 1
---

# Doriath

Self-hosted password and secrets vault for Nextcloud — per-user vaults,
shared team secrets, and a full audit trail of who created, opened,
shared, or revoked what.

> **Status: in development.** This documentation site is up so the
> brand surface and the eventual journeydoc tutorials have a stable
> home. Real walkthroughs and screenshots land as the UI lands. Watch
> the [GitHub repository](https://github.com/ConductionNL/doriath) for
> milestones.

## What is this going to be?

Doriath is the Conduction password manager for organisations that
already run Nextcloud. It is built on the same OpenRegister data layer
the rest of the fleet uses, so:

- **Your existing users and groups are your identities.** No second
  IdP, no separate sign-in flow.
- **Secrets are encrypted at rest** in the Nextcloud database, behind
  the per-user encryption key the platform already manages.
- **Sharing is group-aware.** A secret shared with `platform-ops` is
  visible to everyone in that group; remove a user from the group and
  they lose access immediately.
- **Every read, share, rotation, and revocation is auditable.** The
  activity feed is queryable and exportable, so a security review
  takes minutes instead of an afternoon of log archaeology.

## What is shipped now?

The Doriath repository carries the appinfo skeleton, the namespace, and
the early specs (see the openspec tracker on GitHub). The UI is still
being scaffolded; this docs site is the public face while the app
matures.

Once the first usable build is tagged, the user and admin tutorials
below will be filled in — they are placeholders today, marked clearly
as such.

- New here? The **[User guide](/docs/category/user-guide)** will cover
  opening Doriath, creating your first vault entry, and sharing a
  secret with a team.
- Setting things up? The **[Admin guide](/docs/category/admin-guide)**
  will cover encryption setup, group-level policies, and audit log
  export.

Free and open source under the EUPL-1.2 license. For support, contact
support@conduction.nl.
