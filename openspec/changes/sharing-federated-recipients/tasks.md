# Tasks: federated recipients

## 1. Partners and discovery

- [ ] 1.1 Add the three federation tables, the `federated_source` and `read_only` columns on `secrets`, a migration step and a `<version>` bump. Verify: a PHPUnit migration test asserts the tables and columns.
- [ ] 1.2 Add a `federation` block (enabled flag, root certificate fingerprint) to the discovery document at `GET /api/v1/app/.well-known/keepiq`. Verify: PHPUnit asserts the fingerprint matches the instance root.
- [ ] 1.3 Add the admin partner section and endpoints (`#[AuthorizedAdminSetting]` plus `#[PasswordConfirmationRequired]`): add by URL, show and confirm the fetched fingerprint, set outbound and inbound, remove; show "needs Nextcloud 33" below 33. Verify: PHPUnit for add, pin and the version gate; vitest for the section.

## 2. Certificate lookup

- [ ] 2.1 Add the partner-facing `GET /api/v1/federation/recipient-certificate` that verifies the signed OCM request, answers only inbound partners and users who allow receiving, and otherwise answers as for an unknown user. Verify: PHPUnit for an unsigned request, a non-partner, a user who opted out and an allowed lookup.
- [ ] 2.2 Add the owner-facing lookup that parses the cloud id with `ICloudIdManager`, calls the partner through `IOCMDiscoveryService::requestRemoteOcmEndpoint()`, and returns the certificate chain. Verify: PHPUnit with a mocked discovery service.
- [ ] 2.3 In the share dialog, add the federated recipient option, verify the chain against the pinned root and the common name in the browser, show the fingerprint, and encrypt. Verify: vitest refuses a chain that ends at another root and a mismatched common name.

## 3. Delivery

- [ ] 3.1 Register the `keepiq-secret` OCM provider in `Application.php` and send outbound shares with `sendCloudShare()` after `POST /api/v1/secrets/{id}/federated-shares` stores the ciphertext. Verify: PHPUnit asserts the OCM share carries no ciphertext.
- [ ] 3.2 Implement `shareReceived()` (inbound partner check, pending row, notification) and the "Incoming from other organisations" list with accept and decline. Verify: PHPUnit for a non-partner sender and a pending row; vitest for the list.
- [ ] 3.3 On acceptance, pull the ciphertext from the sender's `GET /api/v1/federation/shares/{id}` with the shared secret in a signed request, and store a read-only `Secret` owned by the recipient. Verify: PHPUnit for the pull, the stored flags, and refusal of a wrong shared secret.
- [ ] 3.4 Refuse every write to a `read_only` secret for its owner (update, sync, share onward, link share). Verify: PHPUnit for each refused route.

## 4. Sync and revocation

- [ ] 4.1 Extend the browser's sync step to encrypt for federated recipients with a freshly verified certificate, and send `SHARE_UPDATED`; handle it in `notificationReceived()` with a new pull. Verify: vitest for the extra recipient; PHPUnit for the notification handler.
- [ ] 4.2 Revoke with `SHARE_UNSHARED` and delete the remote copy; suspend shares whose partner was removed or whose certificate no longer verifies; retry failed notifications from a background job with backoff. Verify: PHPUnit for revoke, suspend and retry.
- [ ] 4.3 Audit every federated share event on both sides with identifiers only. Verify: PHPUnit asserts no audit metadata holds ciphertext or the shared secret.

## 5. End to end

- [ ] 5.1 Add an integration test with two Nextcloud 33 containers: partners pinned on both sides, owner shares with a remote user, the recipient accepts and reads the value in the browser, the owner updates and revokes. Verify: the test passes in a dedicated CI job.

## Acceptance criteria

- A vault owner can share a secret with a user on an approved partner instance, and the recipient reads it in their own vault after accepting.
- Only ciphertext made in the owner's browser for the recipient's verified certificate crosses between instances.
- A certificate that does not chain to the pinned partner root is refused in the browser.
- Updates reach the remote copy, and revocation removes it.
- Nothing federates until both administrators add each other as partners, and a user receives only after opting in.
- Federation stays off on Nextcloud 32.
