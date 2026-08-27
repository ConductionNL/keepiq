---
sidebar_position: 1
title: Open Keepiq for the first time
description: Open Keepiq, unlock your vault with a master password, and add your first secret.
---

# Open Keepiq for the first time

A first look at Keepiq — where the app lives in Nextcloud, how the
lock screen protects your vault, and how to add your first secret.

> **Screenshots for this guide are still being captured.** The steps
> below describe the real, shipped flow; the numbered walkthrough with
> screenshots lands with the next journeydoc capture pass. Follow the
> [repository](https://github.com/ConductionNL/keepiq) for progress.

## Goal

By the end of this guide you will have opened Keepiq from the Nextcloud
app menu, set a master password on first use, added your first vault
entry (a password or an API key), and located it again from the
Nextcloud unified search bar.

## Prerequisites

- A Nextcloud account on an instance where the **Keepiq** app is
  installed and enabled.
- Nothing else. Keepiq keeps its own database tables and its own
  RSA-4096/AES-256 encryption — it does not depend on OpenRegister or on
  Nextcloud's server-side encryption to protect your secrets.

## Steps

The numbered steps land here once the capture pass runs, but the flow
is: open Keepiq, set a master password on the lock screen (this
bootstraps your personal encryption suite and, on first use per
instance, the private Certificate Authority), then use **Vault** to add
a secret and open it again with the reveal toggle.

## Verification

You are set up correctly when: the lock screen accepts your master
password and takes you to the vault, adding an entry returns you to a
list view with the entry present, and reopening the entry shows the
value behind a "reveal" toggle — never inline as plain text.

## Common issues

| Symptom | Fix |
|---|---|
| Keepiq is missing from the app menu | The app is not enabled for your account — ask an administrator to enable it. |
| Vault stays locked after entering the master password | The password does not match the one used to create your encryption suite; use the compromise-recovery flow from the lock screen, or ask an admin. |
| Secrets don't show up in Nextcloud's search bar | Only the secret's name and URL are indexed for search (never the encrypted value) — check the secret's name matches your search term. |

## Reference

- [Manage Keepiq settings](../admin/01-admin-settings.md) — Certificate Authority health, password policy, application approvals.
- Keepiq repository: [github.com/ConductionNL/keepiq](https://github.com/ConductionNL/keepiq).
