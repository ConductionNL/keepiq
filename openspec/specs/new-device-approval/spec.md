# new-device-approval Specification

## Purpose
A user unlocks a new browser or the browser extension by approving it from a device that is already unlocked, without typing the master password on the new device. The server only relays a sealed unlock key it cannot open.

## Requirements

### Requirement: A new device requests approval with a one-time key

A signed-in client whose vault is locked (the web app at /lock, or the paired browser extension) MUST be able to request approval by generating a one-time X25519 key pair, keeping the private key in memory only, and calling `POST /api/v1/device-approvals` with the public key, its client kind and a device label. The system MUST store the request with the caller's IP address and user agent and a 15 minute expiry, MUST return a request secret once, MUST raise a Nextcloud notification to the user, and MUST refuse more than three requests per user per hour.

#### Scenario: A user asks from a new laptop

- **GIVEN** a user signed in to Nextcloud on a new laptop whose Keepiq vault is locked
- **WHEN** they choose "Approve from another device" on the lock screen at /lock
- **THEN** a pending request MUST be stored with a 15 minute expiry and the laptop MUST show a verification phrase
- **AND** the user MUST receive a Nextcloud notification about the request

#### Scenario: Request spam is limited

- **GIVEN** a user who created three requests in the last hour
- **WHEN** a fourth `POST /api/v1/device-approvals` arrives
- **THEN** the system MUST refuse it with a rate-limit response

### Requirement: Both devices show the same verification phrase

The requesting device and the approval dialog MUST show a phrase derived from the SHA-256 of the one-time public key. The approval dialog MUST also show the device label, client kind, IP address and request time.

#### Scenario: A swapped key is visible

- **GIVEN** a request whose public key was replaced on its way to the approving device
- **WHEN** the user compares the two screens
- **THEN** the phrases MUST differ

### Requirement: Approval seals the unlock key and needs proof of the master password

An unlocked user MUST be able to approve a pending request of their own from the web app only after confirming their master password or a PRF passkey. The approving browser MUST seal the raw vault unlock key to the request public key with HPKE, and MUST send only the sealed key. `POST /api/v1/device-approvals/{id}/approve` MUST require a vault-key proof from the user's active suite for purpose `approve-device` bound to the request id and the sealed key. The system MUST refuse approval of an expired, decided or foreign request.

#### Scenario: The user approves their own new laptop

- **GIVEN** a user with Keepiq unlocked on their desktop and a pending request from their new laptop with matching phrases
- **WHEN** they approve in the dialog and confirm their master password
- **THEN** the request MUST become `approved` and hold a sealed unlock key
- **AND** the approve request MUST NOT contain the master password or the raw unlock key

#### Scenario: An unlocked tab cannot approve by itself

- **GIVEN** an unlocked web app session and a pending request
- **WHEN** a script calls the approve route with a sealed key but without a vault-key proof
- **THEN** the system MUST refuse the approval and the request MUST stay pending

### Requirement: Pickup is one-time and unlocks one session

The requesting client MUST fetch the result with its request secret. The system MUST return the sealed key at most once and then clear it and mark the request `consumed`. The client MUST open the sealed key with its one-time private key, unlock its session, and discard the one-time key. The unlock MUST NOT be remembered beyond that session.

#### Scenario: The new laptop unlocks

- **GIVEN** an approved request
- **WHEN** the new laptop polls `GET /api/v1/device-approvals/{id}` with its request secret
- **THEN** it MUST receive the sealed key and unlock its vault view
- **AND** a second fetch MUST return no key

#### Scenario: The extension unlocks through approval

- **GIVEN** a paired but locked browser extension
- **WHEN** the user requests approval from the popup and approves it in their unlocked web app
- **THEN** the extension MUST unlock and list matching logins for the current site

### Requirement: Deny, expiry, audit and administrator switch

The user MUST be able to deny a pending request, after which the dialog MUST point to the Nextcloud security settings to end other sessions. The system MUST expire pending requests after 15 minutes, MUST audit creation, approval, denial, expiry and pickup with identifiers only, and MUST let an administrator turn device approval off with `device_approval_enabled`.

#### Scenario: An unknown device is denied

- **GIVEN** a pending request from a device the user does not recognise
- **WHEN** the user denies it
- **THEN** the request MUST become `denied` and no key MUST ever be released for it
- **AND** the dialog MUST offer the link to end other sessions

#### Scenario: Feature switched off

- **GIVEN** an administrator who turned device approval off
- **WHEN** a user opens the lock screen at /lock
- **THEN** the "Approve from another device" option MUST NOT be shown and the create route MUST refuse

### Requirement: The administrator path goes through organisation account recovery

For a user enrolled in organisation account recovery, the requesting device MUST offer to file a recovery request with purpose `device` carrying its one-time key, approved by recovery officers under that capability's threshold and verification phrase. For purpose `device`, the device MUST unlock the session with the recovered private key without asking for a new master password. For a user who is not enrolled, no administrator path MUST be offered.

#### Scenario: Officers approve a device for an enrolled user

- **GIVEN** an enrolled user on a new laptop with no other unlocked device
- **WHEN** they choose "Ask your organisation instead" and the required officers approve after comparing the phrase
- **THEN** the laptop MUST unlock the vault for this session
- **AND** the server MUST never have held a key that opens the handoff

#### Scenario: Not enrolled, no administrator path

- **GIVEN** a user who is not enrolled in organisation account recovery
- **WHEN** they open the device approval screen
- **THEN** the option to ask the organisation MUST NOT be shown
