# Tell integriq when a connection's certificate expires

Ruben's decision of 2026-10-09: certificates and credentials of any connection live in keepiq. integriq references the keepiq secret and shows its expiry with a link to keepiq to renew it. The first connection is the BRP connection that pipelinq runs today with a certificate file on the server (board `PqBrpMonitor`, moving to `integriq/IqBrpMonitor`).

This is keepiq's share. The counterpart changes are:

- ConductionNL/integriq `brp-connection-monitor-from-pipelinq`: mTLS material by keepiq reference, a connection monitor with performance and certificate expiry, expiry alerts.
- ConductionNL/pipelinq `brp-monitor-moves-to-integriq`: pipelinq hands its BRP settings and certificate over and stops monitoring.
- ConductionNL/design-system: the board moves to integriq.

## Why

The secret bytes of integriq's connections already live in keepiq through the OpenRegister credential broker (integriq `source-credential-custody`). A certificate can go the same way. But integriq must show when it expires, and keepiq's `integration-boundary` capability forbids handing expiry dates to another app unless that capability is changed explicitly, names the fields and the receiver, and keeps the answer inside the vault's access rules. This change is that explicit modification.

## What changes

- **One narrow exception to the boundary.** For a credential minted through the broker for an organisation, keepiq answers integriq's in-process request with two fields: the certificate's `notAfter` and a link to the secret in keepiq. Only for a credential of the organisation integriq acts for. No name, subject, issuer, folder or material. Nothing is written to OpenRegister or any other store by keepiq.
- **A certificate minted for a connection carries its expiry.** When integriq mints a certificate secret through the broker, it passes the `notAfter` it parsed while validating the PEM. keepiq stores it as the secret's `expires_at`, the same field the browser sets for a stored certificate, so the existing expiry scan and reminders cover it without keepiq decrypting anything.
- **The renew link opens the secret.** The link goes to the secret's page in keepiq (`/secrets/:id`), where the existing guided renewal (checklist and replace value) applies. Replacing the value updates `expires_at` from the new certificate.

## Capabilities

### Modified capabilities

- `integration-boundary`: the expiry of a broker credential may be answered to integriq, nothing more.
- `certificate-lifecycle`: a certificate minted through the broker carries its `notAfter`; renewing it updates the expiry integriq sees.

## Impact

- New: `lib/Event/CredentialExpiryRequestedEvent.php`, a listener answering it, tests
- Broker mint path: accept `notAfter` metadata for a certificate secret
- `tests/unit/Settings/RegisterLeafGuardTest.php` unchanged: no leaf is declared
- Feature tier: V1.
