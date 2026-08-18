## 0. Precondition

- [x] 0.1 Confirm PR #265 is merged so `Version000033` (empty-string default on `doriath_secrets.key`) is present; if it is not, add that migration to this change instead.
  - `git log origin/development --oneline -- lib/Migration/Version000033Date20260817120000.php` returns a commit, OR this change carries an equivalent migration
  - Rationale: without the column default, a keyless insert fails the NOT NULL constraint — the Nextcloud Entity setter never marks an unchanged field dirty, so QBMapper omits the column entirely

## 1. Backend — the placeholder

- [x] 1.1 Add an explicit `allowUnfilled` opt-in to `SecretService::create()`, defaulting to `false`, permitting an empty `key` only when asserted. Mirror `createByApplication()`; keep the error message unchanged for existing callers.
  - An ordinary `create()` with an empty `key` still throws `A secret requires a name and a key`
  - An empty name is still refused in both modes
- [x] 1.2 Create the placeholder in `SecretRequestService` for a fresh request — owner = requester, linked to their active suite, optional name/folder — and roll it back if request creation fails, mirroring `createForApplicationVault()`.
  - No orphan Secret remains after a failed request creation; a fresh request derives `secret_id` from the Secret it created
- [x] 1.3 Make `secret_id` optional for a fresh request and mandatory for a re-request in `SecretRequestController`, refusing a re-request without one.
  - The two flows differ by required input, not only by the `is_re_request` boolean
- [x] 1.4 Ensure revoking deletes the placeholder of a fresh request and preserves the target Secret of a re-request.

## 2. Frontend

- [ ] 2.1 Make `SecretRequestCreateDialog`'s `secret` prop optional, showing name and folder inputs when there is no target Secret; a name is required before submit in that mode.
- [ ] 2.2 Untick already-filled fields on a fresh request and label them as already holding a value; leave re-request selection as it is today.
  - Determined client-side from `key`/`login`/`url` and the decrypted `additionalFields`, per spec `:77`; the fields stay tickable
- [ ] 2.3 Add the vault-level "Ask someone for a credential" entry point to `SecretList.vue`, reachable with an empty vault.
- [ ] 2.4 Relabel the `SecretDetail.vue` action as a re-request against that Secret.
- [ ] 2.5 Mark secrets with an outstanding request in the list, distinguishing "awaiting first fill" from "re-request outstanding", clearing when the request ends, and never rendering the fill token.

## 3. Tests

- [ ] 3.1 PHPUnit for `SecretService::create()`: refuses an empty key by default, accepts it with `allowUnfilled`, still requires a name in both modes.
- [ ] 3.2 PHPUnit for `SecretRequestService`: a fresh request creates its own placeholder, two fresh requests do not share one, a re-request creates none, and a failed request leaves no orphan.
- [ ] 3.3 PHPUnit for revoke: the placeholder of a fresh request is deleted and a re-request's target Secret and values survive.
- [ ] 3.4 Vitest for the dialog and list: submits with no `secret` prop, filled fields unticked on a fresh request and selectable on a re-request, and the outstanding-request indicator renders without a token.
- [ ] 3.5 Verify each new/modified spec scenario is driven by a test or carries a reason-bearing `@e2e exclude`, so gate-19 measures this change rather than skipping it.

## 4. Quality

- [ ] 4.1 Translate every new UI string into all 36 locales so the no-regression parity ratchet stays green (`check-l10n.js` and `check-l10n-parity.js` both exit 0).
- [ ] 4.2 Run the full sweep — hydra gates, PHPUnit, vitest, phpcs, php-cs-fixer, `openspec validate --strict` — and confirm `@spec` anchors on every changed method.

## Acceptance criteria

- A user with an empty vault can ask someone for a credential without creating a Secret first and without inventing a key
- A fresh request creates its own unfilled Secret; a re-request creates none
- An ordinary Secret still cannot be created without a key
- A fresh request does not pre-select fields that already hold values, and the fill response never reveals which fields those were
- A secret with an outstanding request is visibly marked as such, and the marking never carries the fill token
