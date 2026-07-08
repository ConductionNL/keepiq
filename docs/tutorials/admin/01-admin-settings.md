---
sidebar_position: 1
title: Manage Doriath settings
description: Monitor the Certificate Authority, set password policy, and approve registered applications from the Nextcloud admin settings panel.
---

# Manage Doriath settings

The admin-side configuration for Doriath — Certificate Authority health,
master-password policy, and the application approval queue.

> **Screenshots for this guide are still being captured.** The steps
> below describe the real, shipped admin panel; the numbered walkthrough
> with screenshots lands with the next journeydoc capture pass. Follow
> the [repository](https://codeberg.org/Conduction/doriath) for progress.

## Goal

By the end of this guide you will have opened the Doriath admin panel
under **Settings → Administration → Doriath**, verified the Certificate
Authority is healthy, set the minimum master-password length and
strength score, and approved a pending application registration.

## Prerequisites

- A Nextcloud admin account on an instance where Doriath is installed
  and enabled.
- Nothing else. Doriath bootstraps its own private root and intermediate
  Certificate Authority on install — there is no external PKI or
  OpenRegister dependency to configure first.

## Steps

The numbered steps land here once the capture pass runs, but the flow
is: open **Settings → Administration → Doriath**, confirm the CA health
card is green (root and intermediate certificate expiry), set
`min_password_length` / `min_password_score`, and act on any pending
application in the approval queue.

## Verification

You are set up correctly when: the Doriath settings panel renders
without an error banner, the CA health card shows valid root and
intermediate certificates, saving the password policy persists across a
reload, and approving an application moves it out of the pending queue.

## Common issues

| Symptom | Fix |
|---|---|
| Settings panel not visible | The app is not enabled for your instance. |
| CA health card shows an error | The certificate authority failed to bootstrap; use the CA bootstrap-retry action, or check the server log for the `BootstrapCertificateAuthority` repair step. |
| Intermediate certificate nearing expiry | Doriath renews the intermediate certificate automatically via a background job; force a renewal from the admin panel if the automatic renewal hasn't run yet. |

## Reference

- [Open Doriath for the first time](../user/01-first-launch.md) — the user-side journey.
- Doriath repository: [codeberg.org/Conduction/doriath](https://codeberg.org/Conduction/doriath).
