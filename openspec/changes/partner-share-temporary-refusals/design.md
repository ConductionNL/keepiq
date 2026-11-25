# Design: stop the save on partner refusals that may be temporary

## Context

At development `ce51807af`:

- `FederationPartnerService::add` stores the partner's `rootFingerprint` once (`:224`); `update` only sets `allowOutbound` and `allowInbound`. `CertificateAuthorityService::renewRoot` makes a new root key and certificate, so a partner's renewal changes the SHA-256 of the root DER that the browser compares with the pin (`assertPinnedRoot`, `src/crypto/federatedCertificate.js:923`).
- `FederationPartnerService::outboundPartnerForRemote` returns null both for an unknown host and for a partner with outbound off (`:339`). `FederatedCertificateService::lookup` (`:204`) and `FederatedShareService::create` (`:159`) turn that null into `not_a_partner`, which the lookup answers with 403 and share creation with 400. `FederatedDeclineReceiver` (`:319`) and `FederatedShareService` (`:560`) also call it, for other purposes.
- The web app (`src/services/shareSave.js`, `partnerLookupFailure`) suspends on every `FederatedCertificateError`, including `untrusted_root` and `not_valid_now`, and on `unknown_recipient` 404, `not_a_partner` 403 and `bad_chain` 502. The extension (`browser-extension/src/vault/share-save.ts`) does the same, except that `not_valid_now` throws `partner_clock`.
- On `partner_unreachable` both clients save nothing and offer to save without the partners named; saving without them suspends those shares in the same share-save.

The backend lead decided which refusals stop the save, that outbound off answers its own reason with 403, also on new shares, that a stop reuses the unreachable-partner flow, and that an admin re-pin exists. The shapes below (method, route, dialog, suspend reason) are this design's.

## D1. Which refusals stop the save and which suspend

| Refusal | Where | Today | After this change |
|---|---|---|---|
| `untrusted_root` | browser | suspend | stop, unreachable dialog |
| `outbound_disabled`, 403 | server, new | (was `not_a_partner`) | stop, unreachable dialog |
| `not_valid_now` | browser | web: suspend, extension: `partner_clock` | stop with `partner_clock` in both |
| `partner_unreachable`, 502 | server | stop, unreachable dialog | unchanged |
| `not_a_partner` 403, `unknown_recipient` 404, `bad_chain` 502 | server | suspend | unchanged |
| `malformed`, `bad_chain`, `name_mismatch` from the browser, cloud id mismatch | browser | suspend | unchanged |

A reason counts only with the status this server sends it with, as for the existing gone reasons: `outbound_disabled` with any status other than 403 is a local server error.

## D2. A stop reuses the unreachable-partner flow

`untrusted_root` and `outbound_disabled` join the partners collected as unreachable, each with its reason, so one save can name several partners for different reasons in one dialog. The dialog's text names the reason per partner: for `untrusted_root`, that the partner's certificate authority changed and an administrator has to confirm it; for `outbound_disabled`, that sharing to that organisation is turned off by an administrator. Saving without them suspends those shares as it does today, with the refusal's reason as the suspend reason in the `federated_share.suspended` audit entry. `partner_unreachable` keeps its reason.

## D3. `not_valid_now` in the web app

The web app throws a `ShareSaveError` with code `partner_clock`, before any write, with a text that asks the owner to check the device's date and time. It does not offer to save without that partner: the clock is the owner's, so every partner would fail the same way.

## D4. `outbound_disabled` on the server

`outboundPartnerForRemote` keeps its meaning for its other callers. A new method on `FederationPartnerService` returns the outbound partner for a remote or throws `not_a_partner` (no partner for that host) or `outbound_disabled` (partner found, outbound off). `FederatedCertificateService::lookup` and `FederatedShareService::create` use it. Both answer `outbound_disabled` with 403 on every route: the single lookup (`FederationController`), the batch lookup (`PartnerCertificateBatchService::refusalStatus`) and share creation. `not_a_partner` keeps the status it has today on each route: 403 on the lookups, 400 on share creation. Who may be looked up or shared to does not change.

The share form (`FederatedShareForm.vue`) shows `outbound_disabled` with its own text. It is only rendered while an outbound partner exists, so it meets this reason only when another partner is still outbound or the toggle changed while the form was open.

## D5. Re-pin

A new route, `PUT /api/v1/federation/partners/{id}/root`, takes `{fingerprint}`. It is an admin route with `#[PasswordConfirmationRequired]`, as `create` and `update`. The server reads the partner's discovery document again through `preview(url: baseUrl)`, refuses when the host no longer matches the stored one, refuses when the published fingerprint is not the one the admin confirmed (as `add` does), and stores it. When the published fingerprint equals the pinned one it answers that nothing changed and stores nothing.

The admin settings show a Re-pin action per partner row, which opens a panel in that row, as the add-partner preview does. It calls the existing `POST /api/v1/federation/partners/preview` with the partner's URL and shows the pinned fingerprint next to the published one, formatted as the list shows fingerprints, with the instruction to compare it with the partner's administrator. Confirming asks for the password and calls the new route. When both fingerprints are equal, the dialog says so and offers no confirm.

A stored re-pin writes an audit log entry through `AuditService::record`, with the partner, the old and new fingerprint and the admin, as the backend lead decided. A refused re-pin or one that changed nothing writes none. Adding, changing and removing a partner stay out of the audit log, as today. The fingerprint is public and stays stored in plain text in `keepiq_federation_partners`, as today; no schema change is needed. Password confirmation uses Nextcloud's `OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired`, as the other partner routes.

## D6. What a re-pin does not do

Shares that were stopped at the dialog go through on their next save. Shares already suspended, before this change or by "Save without them", stay suspended: resuming is #1262.

## D7. Order with `sharing-atomic-share-save`

That change, not yet archived, states the requirements "Owner updates reach the remote copy and revocation removes it" and "An unreachable partner stops the save unless the user saves without them" as the code follows them today. This change's deltas modify those versions, so `sharing-atomic-share-save` is archived first.
