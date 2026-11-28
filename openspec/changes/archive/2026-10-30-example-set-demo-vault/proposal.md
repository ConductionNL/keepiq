---
kind: code
---

# A demo vault for the example set's demo employee

## Why

Keepiq opens on its lock screen until the vault is unlocked, and a user without a vault sees only the first-time setup. On a demo instance that hides the whole app: the demo employee of the Zuiddrecht example set (Pieter) has no vault, so every Keepiq screen apart from the setup redirects to `/lock`. Setting a vault up through the screen is a write a measuring run cannot make, and the example-set loader in OpenRegister writes only registers, app config and preferences, never Keepiq's own tables.

Keepiq already mints a vault with a known master password for its development user (`SeedDevelopmentData`, `SeedDevelopmentSecrets`), gated on `debug`. A demo needs the same thing for a named demo account on an instance that is not in debug mode.

## What changes

- `SeedDevelopmentData` exposes the suite creation as `mintSuite(userId, masterPassword)`; `SeedDevelopmentSecrets` exposes the folder and secret creation as `seedSecrets(ownerId, suite, folders, secrets)`. The development seed itself is unchanged.
- A new repair step `SeedDemoVaults` reads two app config keys, `demo_vault_owners` and `demo_vault_master_password`, and mints a vault with the demo folders and secrets of `lib/Settings/demo-vault.json` for each named account.
- Keepiq ships its part of the Zuiddrecht example set: it writes those two keys for the cast's Pieter.

## Safety

The demo master password is public: it is in the example-set file. That is acceptable only because a demo vault holds made-up values, so the step refuses:

- an administrator;
- an account that does not exist;
- an account that already has a suite of any status, which is never replaced, extended or checked against the demo password.

Nothing happens unless an administrator installs the example set (or sets the two keys) and a repair runs.
