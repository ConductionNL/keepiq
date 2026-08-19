## Context

`SecretRequestController::index()` → `listByUser($uid)` → `findByCreatedBy($uid)`, an exact match on `created_by`. Application requests carry `created_by = 'application:<uuid>'`, so no human query matches. Six such rows exist on the development instance today, and the only lister is the application's own Bearer endpoint.

`application-mgmt` already establishes that an application's vault is administratively visible (`Attribute Secrets to Application`), so this change extends an accepted principle rather than introducing one.

## Goals / Non-Goals

**Goals:**
- An administrator can see what credentials an application is currently asking humans for.
- An administrator can revoke a circulating fill link without the application's cooperation.
- The fill link is recoverable for those requests, built by the same helper the user side uses.

**Non-Goals:**
- Changing what the user-side listing returns. `listByUser()` keeps meaning "requests I created".
- Any change to the machine surface, which is correctly scoped to its own vault.
- Showing submitted values. Write-without-read is unaffected: a listing shows metadata only.
- A cross-application overview. Requests are shown per application, where an admin already goes to judge one.

## Decisions

### Admin-scoped, not registrar-scoped

The obvious cheap alternative is to widen the user listing to include applications the user registered (`registered_by`). Rejected: an application's vault belongs to no single user, registration is a historical fact rather than an ongoing responsibility, and the registrar may have left. Tying audit visibility to it would make the answer to "who can see this" depend on who happened to click Register months ago.

Admin-only also matches where the information is useful — the application detail page is already where an administrator decides whether an application should exist at all.

### A separate query, not a widened one

`listByUser()` is left exactly as it is. A single method that sometimes means "mine" and sometimes "this application's" is how scoping bugs happen; the caller's authority differs, so the query does too.

### The listing never carries the full token

Same rule as the user side, for the same reason: a fill link is a bearer credential, and a list row travels into screenshots and over shoulders. Truncated on screen, full value to the clipboard only on an explicit action, via `src/utils/fillLink.js` so that no second URL variant can appear.

### Reuse the existing component

`SecretRequestList` already returns every request when given no `secretId`, and already has the copy action and revoke. What it does not have is a way to fetch for an application rather than the current user, which is the only real addition on the frontend.

## Risks / Trade-offs

- **An admin can now see that a request exists, including its requested field names.** Field names are plaintext metadata by design (the Requestable Fields requirement puts them on the request, deliberately not on the Secret), so this exposes nothing the audit log does not already record — but it is a real widening of who routinely sees them, and worth stating rather than discovering.
- **Revoke from the admin surface can surprise an application.** Its pending request disappears and its placeholder Secret is deleted. That is the intended power — a circulating fill link needs an off switch that does not depend on the application — but it means an admin action can break an integration mid-flow, so it should read as consequential in the UI.
- **Shared component, two authorities.** `SecretRequestList` will serve both a user's own requests and an application's. The scoping must come from the caller's endpoint rather than a prop the component trusts, or the component becomes the place where an authority check is accidentally decided.
- **A lapsed request can still read as pending for up to an hour.** Written when `secret-request-expiry-lifecycle` was unbuilt and nothing swept at all; that change has since landed and this branch stacks on it, so the sweeper does run — hourly. The consequence is narrower but not gone: between sweeps a request whose expiry has passed is still stored as `pending`. So the list judges expiry on `expires_at` rather than on the stored status, exactly as the user-side copy action and the access gate do.
