## 1. Backend

- [x] 1.1 Add a mapper query for requests created by a given application (`created_by = 'application:<id>'`).
  - Covers every status, so a fulfilled or declined request is still auditable
- [x] 1.2 Add an admin-scoped list method to `SecretRequestService`, leaving `listByUser()` untouched.
  - A single method that sometimes means "mine" and sometimes "this application's" is how scoping bugs happen
- [x] 1.3 Add the admin-scoped route and controller method, refusing non-administrators.
  - The refusal must not depend on who registered the application
  - Authorisation is asserted in the method body, not only by annotation (gate-9 semantic-auth)
- [x] 1.4 Allow an administrator to revoke an application's request, reusing the existing revoke path so the placeholder cleanup and audit event are identical.

## 2. Frontend

- [x] 2.1 Give `SecretRequestList` an application-scoped fetch mode, with the scope coming from the endpoint rather than a prop the component trusts.
- [x] 2.2 Add the outstanding-requests section to `ApplicationDetail.vue`, reusing that component.
- [x] 2.3 Make the admin revoke read as consequential, since it can break an integration mid-flow.

## 3. Tests

- [x] 3.1 PHPUnit: the mapper query returns only that application's requests; the admin list method is not reachable by a non-admin; `listByUser()` still returns only the caller's own.
- [x] 3.2 PHPUnit: an admin revoke invalidates the token and deletes an unfilled placeholder, and preserves a filled Secret.
- [x] 3.3 Vitest: the section renders an application's requests, offers copy-link only where the link is still usable, and never renders a full token.
- [x] 3.4 Verify each new spec scenario is driven by a test or carries a reason-bearing `@e2e exclude`.

## 4. Quality

- [x] 4.1 Translate new UI strings into all 36 locales so the parity ratchet stays green.
- [x] 4.2 Run the full sweep — hydra gates, PHPUnit, vitest, phpcs, phpmd, psalm, php-cs-fixer, `openspec validate --strict` — and confirm a `@spec` anchor or reason-bearing exclude on every changed method.

## Acceptance criteria

- An administrator can see what credentials an application is asking humans for, and revoke any of them
- A non-administrator cannot list them, including the user who registered the application
- A user's own request listing is unchanged
- No listing renders a full fill token, and no submitted value is readable from one
- Lapsed requests are judged on `expires_at` rather than on the stored status — the sweeper from `secret-request-expiry-lifecycle` runs hourly, so a request can be past its expiry and still stored as `pending`

## Note on scope

Deliberately excludes a cross-application overview and any change to the machine surface or the user-side listing. The gap is a missing view, and the smallest honest fix is the view plus the admin-scoped read that reaches it.
