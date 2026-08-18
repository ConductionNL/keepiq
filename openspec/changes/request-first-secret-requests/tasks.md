## 0. Precondition

- [ ] 0.1 Confirm PR #265 is merged so `Version000033` (empty-string default on `doriath_secrets.key`) is present; if it is not, add that migration to this change instead.
  - `git log origin/development --oneline -- lib/Migration/Version000033Date20260817120000.php` returns a commit, OR this change carries an equivalent migration
  - Rationale: without the column default, a keyless insert fails the NOT NULL constraint — the Nextcloud Entity setter never marks an unchanged field dirty, so QBMapper omits the column entirely

## 1. Backend — the placeholder

- [ ] 1.1 Add an explicit `allowUnfilled` opt-in to `SecretService::create()`, defaulting to `false`, permitting an empty `key` only when asserted. Mirror `createByApplication()`; keep the error message unchanged for existing callers.
  - An ordinary `create()` with an empty `key` still throws `A secret requires a name and a key`
  - An empty name is still refused in both modes
- [ ] 1.2 Create the placeholder in `SecretRequestService` for a fresh request — owner = requester, linked to their active suite, optional name/folder — and roll it back if request creation fails, mirroring `createForApplicationVault()`.
  - No orphan Secret remains after a failed request creation; a fresh request derives `secret_id` from the Secret it created
- [ ] 1.3 Enforce the fresh/re-request distinction on both ends: `secret_id` optional for a fresh request and mandatory for a re-request in `SecretRequestController`, and revoke deletes a fresh request's placeholder while preserving a re-request's target Secret.
  - The two flows differ by required input, not only by the `is_re_request` boolean

## 2. Backend — the expiry lifecycle

- [ ] 2.1 Add the terminal `STATUS_EXPIRED` and an expire transition: invalidate the token, delete the placeholder for a fresh request, preserve the Secret for a re-request, and record the audit event with the SYSTEM as actor rather than the requester.
  - `status` is already a string column, so no migration is required
- [ ] 2.2 Add a `TimedJob` that expires lapsed requests, following `ExpireMachineLeasesJob` (hourly), and register it.
  - Only `pending` requests whose `expires_at` has passed are touched
  - A request with no `expires_at` is never touched — it stays open until fulfilled or manually revoked
  - The job body must do real work; an empty `run()` trips the stub-scan gate
- [ ] 2.3 Hoist the expiry evaluation in `SecretRequestPolicy::requireOpenByToken()` above the status switch so it applies to any status, and give `expired` an explicit arm alongside `fulfilled`/`declined`.
  - A request that lapsed since the last sweep is refused on `expires_at` alone — the job is cleanup, never enforcement
  - An `expired` request reports itself as expired; it must NOT fall through to the `default` arm, which answers 500 `Request is in an unknown state`

## 3. Frontend

- [ ] 3.1 Make `SecretRequestCreateDialog`'s `secret` prop optional, showing name and folder inputs when there is no target Secret; a name is required before submit in that mode.
- [ ] 3.2 Untick already-filled fields on a fresh request and label them as already holding a value; leave re-request selection as it is today.
  - Determined client-side from `key`/`login`/`url` and the decrypted `additionalFields`, per spec `:77`; the fields stay tickable
- [ ] 3.3 Pre-fill the expiry field with a suggested value the requester can change or clear, so requests carry an expiry by default and the job has something to act on.
- [ ] 3.4 Split the two entry points: add "Ask someone for a credential" to `SecretList.vue`, reachable with an empty vault, and relabel the `SecretDetail.vue` action as a re-request against that Secret.
- [ ] 3.5 Mark secrets with an outstanding request in the list, distinguishing "awaiting first fill" from "re-request outstanding", clearing when the request ends, and never rendering the fill token.

## 4. Tests

- [ ] 4.1 PHPUnit for `SecretService::create()`: refuses an empty key by default, accepts it with `allowUnfilled`, still requires a name in both modes.
- [ ] 4.2 PHPUnit for `SecretRequestService`: a fresh request creates its own placeholder, two fresh requests do not share one, a re-request creates none, and a failed request leaves no orphan.
- [ ] 4.3 PHPUnit for revoke and expire: placeholder deleted, re-request target preserved, `expired` is terminal, the job ignores requests with no expiry, and the audit actor is the system.
- [ ] 4.4 PHPUnit for the access gate: a lapsed-but-unswept request is refused, an `expired` request reports expiry rather than a 500, and each other status keeps its current code.
- [ ] 4.5 Vitest for the dialog and list: submits with no `secret` prop, filled fields unticked on a fresh request and selectable on a re-request, expiry pre-filled and clearable, and the outstanding-request indicator renders without a token.
- [ ] 4.6 Verify each new/modified spec scenario is driven by a test or carries a reason-bearing `@e2e exclude`, so gate-19 measures this change rather than skipping it.

## 5. Quality

- [ ] 5.1 Run the full quality sweep and leave it green.
  - Every new UI string translated into all 36 locales; `check-l10n.js` and `check-l10n-parity.js` both exit 0
  - hydra gates, PHPUnit, vitest, phpcs, php-cs-fixer, `openspec validate --strict` all pass
  - `@spec` anchor or reason-bearing exclude on every changed method

## Acceptance criteria

- A user with an empty vault can ask someone for a credential without creating a Secret first and without inventing a key
- A fresh request creates its own unfilled Secret; a re-request creates none
- An ordinary Secret still cannot be created without a key
- A fresh request does not pre-select fields that already hold values, and the fill response never reveals which fields those were
- A secret with an outstanding request is visibly marked as such, and the marking never carries the fill token
- A request created through the UI carries an expiry unless the requester clears it
- A lapsed request is refused on `expires_at` alone, before any job has run
- The expiry job removes lapsed placeholders and leaves requests without an expiry untouched
- An expiry is distinguishable from a cancellation after the fact
