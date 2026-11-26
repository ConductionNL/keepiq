# Tasks: announce a renewed root to partners, and let admins accept it

## 0. Decisions

- [x] 0.1 Settle the open questions and write the answers into the design and spec. Verify: `proposal.md` has no open questions, and `openspec validate --strict` passes.

## 1. Renewing side

- [x] 1.1 Sign the new root with the previous root's key in `CertificateAuthorityService::renewRoot`, in the same transaction, and keep the signature with the new root. Verify: a unit test that the signature verifies with the previous root's public key, and that a failed signing stores no new root.
- [x] 1.2 Publish the announcement in the discovery document and in `FederatedCertificateService::answer` next to the chain, as `rootRenewal` in the format of D1, and nothing for a root that was never renewed. Verify: `DiscoveryController` and `answer` tests for both.
- [x] 1.3 Send the renewal notification, its own OCM notification type tied to no share, to every partner with inbound sharing on, with retries: the renewal only queues the notices through `RootRenewedEvent`, and `RetryFederatedNotificationsJob` sends them. Verify: unit tests that only inbound partners get it, that announcing sends nothing itself, for a failed delivery being retried, and that the notification carries no certificate.

## 2. Receiving side

- [x] 2.1 Take the notification from a listed partner, outbound on or off, and ignore and log one from any other host, and notice an announcement in a lookup answer in `FederatedCertificateService::lookup`; on either, read the partner's discovery document through `preview` and run every check of D3. Verify: one unit test per failing check that nothing is stored and no one is notified, one for a valid announcement from each trigger, and one that a lookup for an already offered renewal starts no new check.
- [x] 2.2 Store the offered renewal on the partner (migration with `ISchemaWrapper`), replace it on a newer valid announcement, and clear it on any re-pin. Verify: mapper and service tests, and a migration test.
- [x] 2.3 Notify everyone who may accept, the members of the `admin` group and of the groups delegated Keepiq's general admin settings, each once, through two new `KeepiqNotifier` subjects (the offer and the reminder) to accept the renewed certificate, and remove those notifications when the offer is accepted, withdrawn, replaced or expires, or the partner is re-pinned or removed. Verify: notifier tests for the subjects and their link to the partner, a test that a delegated admin outside the `admin` group is notified once, and tests that accepting, withdrawing, replacing and expiring an offer mark its notifications processed.
- [x] 2.4 Accept through the re-pin: password confirmation, the D3 checks again, then the existing re-pin route and its audit entry. Verify: tests that a changed or vanished announcement is refused and that an accepted one writes the audit entry.
- [x] 2.5 An hourly background job that sends the 7, 5, 3 and 1-day reminders once each and withdraws an offered renewal 30 days after the renewal time the partner publishes. Verify: job tests with a fixed clock for each reminder, for no repeat, for a run just before the 1-day mark followed by one an hour later, and for the withdrawal.

## 3. Web app

- [x] 3.1 Show an offered renewal on the partner row in `FederationPartnersSection.vue`, with the pinned and new fingerprints, the note that the previous root signed the new one, and an "Accept renewed certificate" action that asks for the password. Verify: component tests for an offered, an accepted and a refused renewal, and every string in `l10n/en.json` and each locale.

## 4. Wrap-up

- [x] 4.1 Run `composer check:strict`, `npm run lint` and `npm run test`. Verify: all pass.
- [ ] 4.2 After `partner-share-temporary-refusals` is archived, archive this change. Verify: `openspec validate`.
