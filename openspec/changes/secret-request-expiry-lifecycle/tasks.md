## 1. Set an expiry

- [ ] 1.1 Pre-fill the expiry field in `SecretRequestCreateDialog.vue` with a suggested value the requester can change or clear.
  - Confirm the interval with the product owner before implementing; the spec asserts only that it is suggested and clearable
  - Clearing it MUST produce a request with `expires_at` null, so a perpetual link stays available in one action
  - A pre-filled value must survive the dialog's reset/reopen cycle correctly rather than reverting to empty

## 2. Act on an expiry

- [ ] 2.1 Add the terminal `STATUS_EXPIRED` to `SecretRequest`.
  - `status` is already a string column, so no migration is required
- [ ] 2.2 Add the expire transition to `SecretRequestService`: invalidate the token, delete the placeholder for a fresh request, preserve the Secret and its values for a re-request, and dispatch the audit event with the SYSTEM as actor rather than the requester.
- [ ] 2.3 Add a `TimedJob` that sweeps lapsed requests and register it in `appinfo/info.xml`, following `ExpireMachineLeasesJob` (hourly).
  - Query is narrow: `status = pending` AND `expires_at IS NOT NULL` AND `expires_at < now`
  - A request with no `expires_at` is never touched
  - The job body must do real work; an empty `run()` trips the stub-scan gate

## 3. Enforce it independently of the sweeper

- [ ] 3.1 Hoist the expiry evaluation in `SecretRequestPolicy::requireOpenByToken()` above the status switch so it applies to any status.
  - A request that lapsed since the last sweep is refused on `expires_at` alone — the job is cleanup, never enforcement
- [ ] 3.2 Give `expired` an explicit arm in that switch, in the 410 family alongside `fulfilled` and `declined`.
  - Without it the new status falls into `default: … 'Request is in an unknown state', code: 500`, answering every expired link with a server error
- [ ] 3.3 Render the expired state in `SecretRequestFill.vue` as an expiry message rather than an unexpected error.

## 4. Tests

- [ ] 4.1 PHPUnit for the expire transition: placeholder deleted for a fresh request, Secret and values preserved for a re-request, `expired` is terminal, and the audit actor is the system.
- [ ] 4.2 PHPUnit for the job: sweeps only lapsed pending requests, never touches a request with no `expires_at`, and is idempotent across consecutive runs.
- [ ] 4.3 PHPUnit for the access gate: a lapsed-but-unswept request is refused on `expires_at` alone, an `expired` request reports expiry rather than a 500, and every other status keeps the code it returns today.
- [ ] 4.4 Vitest for the dialog and fill view: expiry pre-filled, clearable to null, and the expired state renders as an expiry message.
- [ ] 4.5 Verify each new/modified spec scenario is driven by a test or carries a reason-bearing `@e2e exclude`, so gate-19 measures this change.

## 5. Quality

- [ ] 5.1 Translate every new UI string into all 36 locales so the no-regression parity ratchet stays green (`check-l10n.js` and `check-l10n-parity.js` both exit 0).
- [ ] 5.2 Run the full sweep — hydra gates, PHPUnit, vitest, phpcs, php-cs-fixer, `openspec validate --strict` — and confirm a `@spec` anchor or reason-bearing exclude on every changed method.

## Acceptance criteria

- A request created through the UI carries an expiry unless the requester clears it
- Clearing the suggested expiry produces a perpetual request, unchanged from today's behaviour
- A lapsed request is refused on `expires_at` alone, before any job has run
- An expired request tells the recipient it expired and never answers with a 500
- The job sweeps lapsed pending requests and leaves requests without an expiry untouched
- A swept fresh request loses its placeholder Secret; a swept re-request keeps its Secret and values
- An expiry is distinguishable from a requester's own cancellation after the fact
- Automatic expiry is attributed to the system in the audit trail

## Release note

The job's first run on an established instance may sweep a batch of long-abandoned requests at once, since every request that lapsed in the past becomes eligible simultaneously. That is the intended cleanup, but it is worth announcing rather than letting an operator discover it.
