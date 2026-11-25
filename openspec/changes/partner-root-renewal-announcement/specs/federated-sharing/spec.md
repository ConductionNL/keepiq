## ADDED Requirements

### Requirement: A renewed root is announced to partners

When an instance renews its Keepiq root, it MUST sign the new root's DER with the previous root's key (RSA with SHA-256) in the same transaction, MUST publish in its discovery document the new root, the previous root, the time of the renewal and that signature, MUST add the same announcement to its certificate lookup answers next to the chain, and MUST send every partner with inbound sharing on a signed OCM notification of its own type, tied to no share, that carries only the fact that this server announced a renewal. The notification MUST be retried when it fails, as share notifications are. An instance whose root was never renewed MUST publish no announcement.

#### Scenario: The partner renews its root

- **GIVEN** `cloud.partner.example` is a partner of `cloud.example`, which pinned its root
- **WHEN** the administrator of `cloud.partner.example` renews its Keepiq root
- **THEN** its discovery document and its certificate lookup answers MUST carry the new root, the previous root and the previous root's signature over the new root
- **AND** `cloud.example` MUST receive a notification that a renewal was announced, carrying no certificate

### Requirement: An announced renewal is offered to administrators after its signature checks out

On a renewal notification from a listed partner, whether outbound sharing is on or off, or a certificate lookup answer from it that carries an announcement not already offered, the system MUST read the partner's discovery document the same way as when the partner was added, and MUST offer the renewal only when the previous root is the pinned one, the previous root's signature over the new root verifies, the new root is the root the document publishes, the new root is a CA certificate valid now, it differs from the pin, and the renewal time is not in the future and less than 30 days ago. Otherwise it MUST store nothing and notify no one. The system MUST NOT take any certificate or fingerprint from the notification or the lookup answer itself, and MUST NOT change a pin without an administrator's acceptance. An offered renewal MUST be shown on the partner in the federation admin settings with the new fingerprint and the note that the previous root signed it, and every member of the Nextcloud `admin` group MUST get a Nextcloud notification to accept the renewed certificate. A notification from a host that is not a listed partner MUST be ignored and logged. Accepting MUST require Nextcloud password confirmation, MUST read the discovery document again and repeat the checks before storing, and MUST store the pin through the administrator re-pin, writing its audit entry. A newer valid announcement MUST replace an offered one, and any re-pin MUST clear it. A partner that publishes no announcement MUST keep only the manual re-pin.

#### Scenario: An administrator accepts a verified renewal

- **GIVEN** `cloud.partner.example` renewed its root and announced it, and the previous root it publishes is the one pinned at `cloud.example`
- **WHEN** `cloud.example` receives the notification
- **THEN** its administrators MUST be notified to accept the renewed certificate
- **AND** when an administrator accepts it with their password, the partner MUST be stored with the new fingerprint and the audit log MUST record the re-pin

#### Scenario: A renewal signed by another root

- **GIVEN** a notification from `cloud.partner.example` whose discovery document publishes a renewal signed by a root that is not the pinned one
- **WHEN** `cloud.example` receives it
- **THEN** no renewal MUST be offered, no administrator MUST be notified, and the pin MUST stay as it was

#### Scenario: The announcement changes before it is accepted

- **GIVEN** a renewal offered for `cloud.partner.example` with fingerprint A
- **WHEN** the partner's discovery document publishes another announcement, or none, before an administrator accepts A
- **THEN** accepting A MUST be refused and the pin MUST stay as it was

#### Scenario: A partner without announcements renews

- **GIVEN** a partner on a Keepiq version that publishes no announcement
- **WHEN** it renews its root
- **THEN** no renewal MUST be offered here, and an administrator MUST re-pin it by hand after comparing the fingerprint out of band

### Requirement: An offered renewal expires after 30 days, with reminders

An offered renewal MUST stay acceptable until 30 days after the renewal time the partner publishes. While no administrator has accepted it, administrators MUST be reminded 7, 5, 3 and 1 day before the 30 days end, each reminder once. When the 30 days end, the offered renewal MUST be withdrawn, and the partner MUST offer only the manual re-pin with its out-of-band comparison.

#### Scenario: No one accepts in time

- **GIVEN** a renewal offered for `cloud.partner.example` that no administrator accepts
- **WHEN** 30 days pass after the renewal
- **THEN** administrators MUST have been reminded 7, 5, 3 and 1 day before the end, once each
- **AND** after the end, Accept MUST no longer be offered, and the manual re-pin MUST remain
