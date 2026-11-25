# Tasks: stop the save on partner refusals that may be temporary

## 1. Backend

- [x] 1.1 Add the `FederationPartnerService` method that returns the outbound partner for a remote or throws `not_a_partner` or `outbound_disabled`, and use it in `FederatedCertificateService::lookup` and `FederatedShareService::create`; leave `outboundPartnerForRemote` and its other callers unchanged. Verify: unit tests for a removed partner, an outbound-off partner and an outbound partner, on the lookup and on share creation.
- [x] 1.2 Answer `outbound_disabled` with 403 on the single lookup (`FederationController`), the batch lookup (`PartnerCertificateBatchService::refusalStatus`) and share creation. Verify: controller tests and `PartnerCertificateBatchServiceTest` cases for each route.
- [x] 1.3 Add `PUT /api/v1/federation/partners/{id}/root` with `#[PasswordConfirmationRequired]`: re-read the discovery document, refuse on a changed host or a fingerprint other than the confirmed one, answer "nothing changed" without storing when it equals the pin, otherwise store it. Verify: unit tests for each of those outcomes, and that the permissions are kept.
- [x] 1.4 Write an audit log entry for a stored re-pin through `AuditService::record`, with the partner, the old and new fingerprint and the admin; none for a refused re-pin or one that changed nothing. Verify: unit tests for the entry and for its absence.
- [x] 1.5 Keep `not_a_partner` on share creation at 400; only `outbound_disabled` answers 403 there. Verify: `FederatedShareControllerTest` and `FederatedShareSendTest` per case.
- [x] 1.6 Leave `composer.json` and `composer.lock` as development has them: psalm's own install is #1949's fix, which lands first. Verify: `git diff origin/development -- composer.json composer.lock` is empty.

## 2. Web app

- [x] 2.1 In `src/services/shareSave.js`, collect `untrusted_root` and `outbound_disabled` (403 only) with the unreachable partners, each with its reason, and suspend with that reason when the owner saves without them. Verify: `tests/store/federatedShare.sync.spec.js` cases for both reasons and for mixed reasons in one save, and `tests/store/shareSave.spec.js` cases for `outbound_disabled` with a non-403 status as a local error.
- [x] 2.2 Stop the save with `partner_clock` on `not_valid_now`, without offering to save without them. Verify: a `federatedShare.sync.spec.js` case that nothing is written and no share is suspended.
- [x] 2.3 Add the per-reason texts to the unreachable dialog and the `partner_clock` text, and the `outbound_disabled` text in `FederatedShareForm.vue`. Verify: `shareSaveErrorText.golden.spec.js`, and every new string in `l10n/en.json` and each locale (`npm run test:l10n`, `npm run l10n:build`, `npm run check:l10n-js`).
- [x] 2.4 Add the Re-pin action to `FederationPartnersSection.vue`: preview, pinned and published fingerprints side by side, no confirm when equal, password confirmation, then the new route. Verify: `tests/components/FederationPartnersSection*.spec.js` cases for a changed root, an unchanged root and a refused re-pin.

- [x] 2.5 Name the reason wherever else the web app shows a `partner_unreachable` failure: the passkey delete error in `SecretDetailSidebar.vue`, and the offline queue's failed list, which stores `{cloudId, reason}` per partner and still reads failures stored as plain cloud ids. Verify: component and `offline` store tests for each reason and for an old stored failure.
- [x] 2.6 Fail an offline replay that stops with `partner_clock`, with its own text and a Try again action, instead of holding the queue silently. Verify: an `offline` store test that the entry fails with the clock text and later entries are not held.
- [x] 2.7 Prove that a save after a re-pin or after outbound is turned on again goes through: one save stops on `untrusted_root` or `outbound_disabled` while a second partner verifies, the next save looks the stopped partner up again and encrypts for it, and the other partner comes from the kept certificates. Verify: `federatedShare.sync.spec.js`.

## 3. Browser extension

- [x] 3.1 In `browser-extension/src/vault/share-save.ts`, treat `untrusted_root` and `outbound_disabled` (403 only) as unreachable with their reason instead of suspending, and keep `partner_clock` as is. Verify: `share-save.test.ts` cases mirroring 2.1.
- [x] 3.2 Add the per-reason texts in `browser-extension/src/messages.ts`. Verify: the extension's message tests, and its translations wherever `messages.ts` keeps them.

- [x] 3.3 The test of 2.7 for the extension. Verify: `share-save.test.ts`.

## 4. Wrap-up

- [x] 4.1 Run `composer check:strict`, `npm run lint`, `npm run test`, and `npm run lint` and `npm run test` in `browser-extension/`. Verify: all pass, except lint on `.claude/worktrees/` and the two phpmd findings that also fail on development.
- [ ] 4.2 After `sharing-atomic-share-save` and `sharing-share-save-missed-partner-shares` are archived, archive this change and merge its deltas into `openspec/specs/`. Verify: `openspec validate`, and the merged "Owner updates reach the remote copy and revocation removes it" holds both `for a reason other than an untrusted root` and `whether Dave saves the source or his own copy`.
