# Tasks: announce a renewed root to partners, and let admins accept it

## 0. Decisions

- [ ] 0.1 Get answers to the seven open questions in `proposal.md` from a backend or frontend lead, and update the design and spec with them. Verify: no open question left in `proposal.md`, and `openspec validate --strict` passes.

## 1. Renewing side

- [ ] 1.1 Sign the new root with the previous root's key in `CertificateAuthorityService::renewRoot`, in the same transaction, and keep the signature with the new root. Verify: a unit test that the signature verifies with the previous root's public key, and that a failed signing stores no new root.
- [ ] 1.2 Publish the announcement in the discovery document in the format of question 2, and nothing for a root that was never renewed. Verify: `DiscoveryController` tests for both.
- [ ] 1.3 Send the renewal notification to the partners of question 7, addressed as question 4 decides, with retries. Verify: unit tests for sending, for a failed delivery being retried, and that the notification carries no certificate.

## 2. Receiving side

- [ ] 2.1 Take the notification, read the partner's discovery document through `preview`, and run every check of D3. Verify: one unit test per failing check that nothing is stored and no one is notified, and one for a valid announcement.
- [ ] 2.2 Store the offered renewal on the partner (migration with `ISchemaWrapper`), replace it on a newer valid announcement, and clear it on any re-pin. Verify: mapper and service tests, and a migration test.
- [ ] 2.3 Notify the administrators of question 1 through `KeepiqNotifier` with a new subject. Verify: notifier tests for the subject and the link to the partner.
- [ ] 2.4 Accept through the re-pin: password confirmation, the D3 checks again, then the existing re-pin route and its audit entry. Verify: tests that a changed or vanished announcement is refused and that an accepted one writes the audit entry.
- [ ] 2.5 A daily background job that sends the 7, 5, 3 and 1-day reminders once each and withdraws an offered renewal after 30 days, counted as question 5 decides. Verify: job tests with a fixed clock for each reminder, for no repeat, and for the withdrawal.

## 3. Web app

- [ ] 3.1 Show an offered renewal on the partner row in `FederationPartnersSection.vue`, with the pinned and new fingerprints and an Accept action that asks for the password. Verify: component tests for an offered, an accepted and a refused renewal, and every string in `l10n/en.json` and each locale.

## 4. Wrap-up

- [ ] 4.1 Run `composer check:strict`, `npm run lint` and `npm run test`. Verify: all pass.
- [ ] 4.2 After `partner-share-temporary-refusals` is archived, archive this change. Verify: `openspec validate`.
