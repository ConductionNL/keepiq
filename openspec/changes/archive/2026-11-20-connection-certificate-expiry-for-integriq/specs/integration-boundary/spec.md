## MODIFIED Requirements

### Requirement: Expiry And Rotation Visibility Stays In-App
Certificate and secret expiry awareness MUST continue to be served by Keepiq's own surfaces — the expiry scan jobs (`ScanCertificateExpiryJob`, `CheckRootCertificateExpiry` with 90/30/7-day thresholds, `ScanExpiringSecretsJob` with policy reminder thresholds), Nextcloud notifications, rotation flags, and the dashboard — and MUST NOT be re-implemented as a calendar leaf or external feed. These surfaces answer under the vault's own ACL: a user is notified only about entries they can access. The one exception is the expiry of an organisation credential that integriq minted through the credential broker for its own connection, which keepiq MAY answer to integriq as set out in "Integriq may learn when its own connection certificate expires".

#### Scenario: Expiring certificate surfaces without leaving the app
- **WHEN** a certificate crosses a notification threshold before its `notAfter`
- **THEN** the owner receives a Nextcloud notification from the existing scan pipeline
- **AND** no calendar object, feed, or cross-app artifact is produced
@e2e exclude existing background-job behaviour restated as a boundary; covered by the expiry-scan PHPUnit suites, no new surface.

## ADDED Requirements

### Requirement: Integriq may learn when its own connection certificate expires

keepiq SHALL answer integriq's in-process `CredentialExpiryRequestedEvent` with exactly two fields, the certificate's `notAfter` and a `renewUrl` to the secret's page in keepiq, and only when the credential is an organisation credential minted through the broker for the consumer `integriq` and belongs to the organisation named in the request. It SHALL answer nothing for any other credential. It SHALL NOT return a name, subject, issuer, folder or any material, and SHALL NOT write the answer to OpenRegister or any other store.

#### Scenario: integriq gets the expiry of its BRP certificate

@e2e exclude an in-process event with no keepiq browser surface; the integriq connection monitor e2e shows the result.

- **GIVEN** a certificate secret minted by integriq for organisation Zuiddrecht, expiring on 30 November 2026
- **WHEN** integriq asks for its expiry acting for Zuiddrecht
- **THEN** keepiq SHALL answer `notAfter` 2026-11-30 and a link to the secret
- **AND** the answer SHALL carry no other field

#### Scenario: A personal or foreign credential gets no answer

@e2e exclude an in-process event; covered by CredentialExpiryRequestedListenerTest.

- **GIVEN** a personal vault certificate, and a certificate of another organisation
- **WHEN** integriq asks for either expiry
- **THEN** keepiq SHALL answer nothing
