# Design: tell integriq when a connection's certificate expires

## The boundary gate, condition by condition

`integration-boundary` lets a change surface keepiq data only when it (1) modifies the capability explicitly, (2) carries no secret material and no vault-structure metadata, or proves the receiver's access is at least as narrow, (3) is scoped to the principal, and (4) names the receiver and the fields.

1. This change modifies `Expiry And Rotation Visibility Stays In-App` and adds one requirement.
2. The expiry date is vault-structure metadata, so the change relies on the second half: the credential is an organisation credential minted by integriq itself through the broker, for a connection integriq runs. integriq already holds the material at call time; the date tells it nothing it could not parse itself. The answer goes only to administrators of that connection in integriq. Personal vault entries are never answered.
3. The request carries the acting organisation. keepiq answers only when the credential belongs to that organisation and was minted for integriq (`consumer = integriq`).
4. Receiver: integriq. Fields: `notAfter` (ISO 8601) and `renewUrl`. Nothing else.

## Mechanism

- `OCA\Keepiq\Event\CredentialExpiryRequestedEvent` carries `credentialId` and `actingOrganisationId`, and collects `notAfter` and `renewUrl`. In-process, no HTTP, no stored copy.
- An unknown credential, a personal credential, another organisation's credential, or one without `expires_at` gets no answer, and integriq shows "expiry unknown".
- At mint, the broker passes `notAfter` for a certificate secret. keepiq writes it to `expires_at` without touching the ciphertext (the `rotation-expiry-policies` rule), so `ScanExpiringSecretsJob` reminds the owners in keepiq too.

## Not in scope

- A general API for other apps to read vault metadata. Any further consumer needs its own modification of `integration-boundary`.
