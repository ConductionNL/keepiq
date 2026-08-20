---
kind: code
---

## Why

An application can ask a human to submit a credential into its own vault, and no human can see that it did.

Measured on a live instance rather than inferred:

```
created_by                                        count
admin                                               9
application:b177cd1a-aa48-41b6-8728-7940a6527181     2
application:446992fb-4e51-426d-adae-a6ff5eab17de     2
application:fe52a684-9d68-4feb-8041-3ea3c57801a7     2
```

Six application-created requests, invisible to every person on the instance. The reason is a one-line scoping decision: `SecretRequestController::index()` calls `listByUser($uid)`, which is `findByCreatedBy($uid)` — an exact match. Application requests store `created_by = 'application:<uuid>'`, a value no Nextcloud user id can ever equal.

The consequences compound:

- **No listing.** The only lister is `GET /api/v1/app/secret-requests`, which requires the application's own Bearer token. The application can enumerate its requests; nobody else can.
- **No vault view either.** The target Secrets are `owner_type = 'application'`, so they appear in no user's secret list.
- **No admin surface.** `ApplicationDetail` and `AdminApplicationsView` have no request section at all.
- **Only an audit trail.** Creation does emit `application.secret_request_created` (6 rows present), so the events are recorded — there is simply nowhere to look at what is outstanding.

That leaves an administrator unable to answer basic questions about an application they approved: what credentials is it currently asking humans for, are any of those requests stale, and is a pending fill-link circulating that should be revoked. A fill link is a bearer credential in a URL; "no one can enumerate them" is not a security property here, it is an audit gap.

## What Changes

- **An admin-scoped listing endpoint** for an application's secret requests, keyed by `created_by = 'application:<id>'`. The existing user endpoint is left alone: widening it would change what "my requests" means for every user.
- **An outstanding-requests section on the application's detail page**, listing that application's requests with their status, requested fields and expiry.
- **Copy-fill-link on those rows**, reusing `src/utils/fillLink.js` so the URL is built in exactly one place, as on the user side.
- **The full token stays out of the listing**, exactly as on the user side: truncated on screen, copied only on an explicit action.
- **Revoke from the admin surface**, so an administrator who finds a circulating fill link can end it without the application's cooperation.

## Capabilities

### New Capabilities

None. This is a missing view over data that already exists, plus the admin-scoped read needed to reach it.

### Modified Capabilities

- `application-mgmt`: one requirement added — an administrator MUST be able to see and revoke the secret requests an application has created, sitting alongside the existing `Attribute Secrets to Application` requirement, which established that an application's vault contents are administratively visible.

## Impact

**Backend**
- `lib/Db/SecretRequestMapper.php` — a query for requests created by a given application.
- `lib/Service/SecretRequestService.php` — an admin-scoped list method; the existing `listByUser()` is untouched.
- `lib/Controller/` — an admin-scoped route. Authorisation is the deciding detail: this must be admin-only, because an application's vault belongs to no single user and the registrar is not necessarily the right auditor.

**Frontend**
- `src/views/ApplicationDetail.vue` — the outstanding-requests section.
- `src/components/secretRequest/SecretRequestList.vue` — already renders all requests when no `secretId` is given and already has the copy action, so it is reusable; it needs an application-scoped fetch rather than the user one.

**Not affected**
- The machine surface. `GET /api/v1/app/secret-requests` keeps its own-vault scoping.
- The encryption boundary. Everything listed here is plaintext metadata; no submitted value is ever readable from a listing (write-without-read).

**Relationship to #267** — independent. #267 fixed the human request flow; this is about visibility of the machine one, which #120 introduced. Both touch `SecretRequestList`, so whichever lands second absorbs a small conflict there.

**Prior art to follow** — `Machine Pending-Request Listing` (secret-store-api) exists so that "a fill-link is retrievable after creation" for an application. This change is the same argument made for the administrator who is accountable for that application.
