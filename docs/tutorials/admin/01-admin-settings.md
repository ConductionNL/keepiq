---
sidebar_position: 1
title: Manage Doriath settings
description: Configure encryption, group-level sharing policies, and the audit log from the Nextcloud admin settings panel.
---

# Manage Doriath settings

The admin-side configuration for Doriath — encryption back end,
group-level sharing policy, audit retention.

> **This guide is being written as Doriath approaches feature-completeness.**
> The structure below mirrors the journeydoc shape the rest of the
> fleet uses; bodies and screenshots fill in once the admin UI lands.
> Follow the [GitHub repository](https://github.com/ConductionNL/doriath)
> for milestones.

## Goal

By the end of this guide you will have opened the Doriath admin panel
under **Settings → Administration → Doriath**, verified that the
encryption back end is wired up, set a default sharing policy for new
team vaults, and confirmed that the audit log is being written.

## Prerequisites

- A Nextcloud admin account on an instance where Doriath is installed
  and enabled.
- The **OpenRegister** app installed and enabled.
- Nextcloud server-side encryption configured at the platform level
  (Doriath does not enable it for you — it relies on it).

## Steps

The numbered steps land here once the admin UI is built. The journeydoc
capture spec (see ADR-030) ships a placeholder; the real shoot() calls
are added together with the implementation.

## Verification

You are set up correctly when: the Doriath settings panel renders
without an error banner, the encryption-back-end indicator is green,
saving a sharing policy persists across a reload, and a freshly created
entry appears in the audit log within a few seconds.

## Common issues

| Symptom | Fix |
|---|---|
| Settings panel not visible | The app is not enabled, or OpenRegister is missing. |
| Encryption indicator stays red | Nextcloud server-side encryption is not configured at the platform level; configure it first, then reload Doriath. |
| Audit log is empty after creating an entry | OpenRegister's audit trail is not enabled for the Doriath register — re-import the configuration from **Settings → Registers**. |

## Reference

- [Open Doriath for the first time](../user/01-first-launch.md) — the user-side journey.
- Doriath on GitHub: [ConductionNL/doriath](https://github.com/ConductionNL/doriath).
