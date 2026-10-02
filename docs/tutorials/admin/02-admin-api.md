---
sidebar_position: 2
title: Script Keepiq administration with the admin API
description: Use the versioned admin API with a Nextcloud app password, scoped to the admin areas a script needs.
---

# Script Keepiq administration with the admin API

Keepiq has a documented, versioned admin API under `/api/v1/admin`. Use it to approve applications, offboard a leaver or export audit events from a script. The full description is the OpenAPI 3.1 document [`docs/api/admin-v1.openapi.json`](https://github.com/ConductionNL/keepiq/blob/development/docs/api/admin-v1.openapi.json).

## Goal

By the end of this guide a script calls the admin API as a service account. That account can do exactly the admin jobs you gave it, and nothing else.

## Prerequisites

- A Nextcloud admin account on an instance where Keepiq is installed.
- A Nextcloud user for the script, for example `svc-keepiq-audit`.

## 1. Give the service account only the areas it needs

Keepiq administration has five areas: General, Policies, Applications and machine access, People and offboarding, and Audit and compliance.

1. Create a group, for example `keepiq-audit-scripts`, and add the service account to it.
2. Open **Administration settings > Administration privileges**.
3. Find **Keepiq - Audit and compliance** and add the group.

The account now holds the Audit area. Every other admin endpoint refuses it.

## 2. Create an app password

Log in as the service account and open **Personal settings > Security**. Create an app password named after the integration. Store it in a secret store, ideally Keepiq's own machine API, not in the script.

To cut the integration off, revoke that app password.

## 3. Call the API

Send the app password over HTTP Basic, with the header `OCS-APIRequest: true`:

```bash
curl -u svc-keepiq-audit:APP_PASSWORD \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  https://cloud.example.com/index.php/apps/keepiq/api/v1/admin
```

The index returns `apiVersion`, the versions the server offers, the areas you hold and every path. Then read audit events:

```bash
curl -u svc-keepiq-audit:APP_PASSWORD -H 'OCS-APIRequest: true' \
  'https://cloud.example.com/index.php/apps/keepiq/api/v1/admin/audit?eventType=share.granted&limit=50'
```

## What v1 offers

| Area | Endpoints |
|---|---|
| Any area | `GET /api/v1/admin` |
| Policies | `GET`, `PUT /policies` |
| People and offboarding | `GET /suites`, `POST /offboarding` |
| Applications and machine access | `GET`, `POST /applications`; `GET`, `DELETE /applications/{id}`; `POST /applications/{id}/approve` and `/reject`; `GET`, `PUT /applications/{id}/lease-policy` |
| Audit and compliance | `GET /audit`; `GET`, `POST /compliance/reports`; `GET /compliance/reports/{id}`; `GET`, `POST /siem/sinks`; `PUT`, `DELETE /siem/sinks/{id}` |

Every response is metadata: ids, statuses, counts, dates and settings. No response carries a private key, a secret value, ciphertext or a SIEM credential.

Two jobs stay in the web interface. Force revocation and reinstatement of an encryption suite ask you to confirm your own password at that moment, and a stored app password cannot do that.

## Versioning

Version 1 only grows. New endpoints and new fields can appear in it. A removed or renamed field, or a changed status code, ships as `/api/v2/admin` next to v1, and v1 stays for at least one more minor release.

## Next step

Give the Terraform provider an app password with the Applications area, and manage your applications as code.
