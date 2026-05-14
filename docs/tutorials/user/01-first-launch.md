---
sidebar_position: 1
title: Open Doriath for the first time
description: Open Doriath, create your first vault entry, and confirm the encryption back end is connected.
---

# Open Doriath for the first time

A first look at Doriath — where the app lives in Nextcloud, what the
navigation gives you, and how to add your first secret.

> **This guide is being written as Doriath approaches feature-completeness.**
> The structure below mirrors the journeydoc shape the rest of the
> fleet uses; bodies and screenshots fill in once the UI lands. Follow
> the [GitHub repository](https://github.com/ConductionNL/doriath) for
> milestones.

## Goal

By the end of this guide you will have opened Doriath from the Nextcloud
app menu, added your first vault entry (a password or an API key),
confirmed that the value is encrypted at rest, and located the entry
again from the search bar.

## Prerequisites

- A Nextcloud account on an instance where the **Doriath** app is
  installed and enabled.
- The **OpenRegister** app installed and enabled — Doriath stores its
  vault data through OpenRegister, so it is a hard dependency.
- A Nextcloud master encryption key configured (the platform's own
  server-side encryption; Doriath layers per-entry encryption on top).

## Steps

The numbered steps land here once the UI is built. Each step that
warrants a screenshot will get a matching shoot() call in the
`docs-screenshots` capture spec (see ADR-030 / journeydoc).

## Verification

You are set up correctly when: Doriath renders the empty-vault state on
first launch, adding an entry returns to a list view with the entry
present, and reopening the entry shows the value behind a "Reveal"
gate — never inline as plain text.

## Common issues

| Symptom | Fix |
|---|---|
| Doriath is missing from the app menu | The app is not enabled for your account — ask an administrator to enable it. |
| "OpenRegister is not installed or enabled" banner | Install and enable OpenRegister, then reload Doriath. |
| Vault entries appear as plain text | Nextcloud server-side encryption is not configured; see the [Admin guide](../admin/01-admin-settings.md). |

## Reference

- [Manage Doriath settings](../admin/01-admin-settings.md) — encryption setup, group-level policies.
- Doriath on GitHub: [ConductionNL/doriath](https://github.com/ConductionNL/doriath).
