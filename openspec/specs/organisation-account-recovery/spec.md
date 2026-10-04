# organisation-account-recovery Specification

## Purpose
A user who forgot their master password gets their vault back, with the same key pair and every secret readable, once enough recovery officers named by the administrator approve. The server stores only certificates, public keys and ciphertext it cannot open.

## Requirements

### Requirement: Administrators name recovery officers, a threshold and a policy

The system MUST let an administrator, after Nextcloud password confirmation, name recovery officers from Nextcloud users with an active encryption suite, set an approval threshold between 1 and the number of officers, and set `account_recovery_policy` to `off` (default), `optional` or `required`. It MUST refuse a threshold above the officer count.

#### Scenario: Administrator sets up two-person recovery

- **GIVEN** an administrator on the account recovery section of the Keepiq admin settings who has confirmed their password
- **WHEN** they name officers `olga` and `omar`, set the threshold to 2 and the policy to `optional`
- **THEN** the settings MUST be stored and both officers MUST be notified that they are recovery officers

#### Scenario: A threshold above the officer count is refused

- **GIVEN** two named officers
- **WHEN** an administrator sets the threshold to 3
- **THEN** the system MUST reject the change with a bad-request response

### Requirement: The recovery private key is generated and held by officers only

An officer MUST generate the organisation recovery key pair in their own browser. The system MUST store the recovery certificate and, per officer, the recovery private key wrapped to that officer's suite certificate, and MUST NOT receive the recovery private key in any other form. The system MUST return an officer's wrapped copy only to that officer.

#### Scenario: Officer creates the recovery key

- **GIVEN** officer `olga` with an unlocked vault on the recovery officer page
- **WHEN** she creates the organisation recovery key
- **THEN** the server MUST store a certificate and one wrapped copy for each named officer
- **AND** no request from her browser MUST contain the recovery private key unwrapped

### Requirement: Users enrol by wrapping their own key to the recovery certificate

Under the `optional` policy a user MUST be able to enrol and withdraw in their personal settings; under `required` the web app MUST enrol the user at their next unlock and MUST refuse withdrawal. Enrolment MUST happen in the user's browser: it MUST show the recovery certificate fingerprint, check that the certificate chains to the instance CA, wrap the user's suite private key to the recovery certificate with the emergency-access hybrid envelope, and send only that envelope.

#### Scenario: Required policy enrols at unlock

- **GIVEN** the policy is `required` and a user who is not enrolled
- **WHEN** the user unlocks their vault at /lock with their master password
- **THEN** the web app MUST post an enrolment envelope and tell the user they are enrolled, showing the certificate fingerprint
- **AND** the request MUST NOT contain the master password or the private key in plain

#### Scenario: Withdrawal under a required policy is refused

- **GIVEN** the policy is `required` and an enrolled user
- **WHEN** the user calls `DELETE /api/v1/recovery/enrolment`
- **THEN** the system MUST refuse and keep the enrolment

### Requirement: A recovery request carries a one-time key and a verification phrase

An enrolled user whose vault is locked MUST be able to file a recovery request from the lock screen. The user's browser MUST generate a one-time X25519 key pair, keep the private key in that browser only, and send the public key. The request MUST expire after 72 hours. The user's screen and every officer's approval dialog MUST show the same verification phrase derived from the request public key.

#### Scenario: Forgotten password starts a request

- **GIVEN** an enrolled user who forgot their master password
- **WHEN** they choose "Forgot your master password?" on the lock screen at /lock and confirm
- **THEN** a request MUST be created with state `pending` and a 72 hour expiry
- **AND** the lock screen MUST show a verification phrase, and every officer MUST be notified

### Requirement: Recovery needs a threshold of proven officer approvals

The system MUST count an approval only when it carries a vault-key proof from the approving officer's active suite for purpose `approve-account-recovery`, MUST count each officer once, MUST refuse an officer's approval of their own request, and MUST move a request to `approved` only when distinct approvals reach the threshold. Any officer MAY decline, which ends the request.

#### Scenario: Two officers approve

- **GIVEN** a pending request, a threshold of 2, and officers `olga` and `omar` who each compared the verification phrase with the user by phone
- **WHEN** both approve with a valid vault-key proof
- **THEN** the request MUST move to `approved`

#### Scenario: An approval without a proof is refused

- **GIVEN** a pending request
- **WHEN** an officer calls `POST /api/v1/recovery/requests/{id}/approve` with a valid session but no vault-key proof
- **THEN** the system MUST refuse the approval and the count MUST stay unchanged

#### Scenario: Self-approval is refused

- **GIVEN** officer `olga` who filed a recovery request for her own account
- **WHEN** she tries to approve it
- **THEN** the system MUST refuse the approval

### Requirement: The recovered key reaches only the requesting browser

For an `approved` request the system MUST release the user's enrolment envelope and the officer's own wrapped recovery key only to an officer who approved it. That officer's browser MUST seal the user's private key to the request public key with HPKE and post only the sealed result. The system MUST release the sealed result only to the requesting user. The user's browser MUST open it with the request private key, set a new master password, and replace the suite's private-key wrapping through `PUT /api/v1/suites/{id}/private-key` with a vault-key proof by the recovered key. The system MUST delete the sealed result when the request completes.

#### Scenario: User completes the recovery

- **GIVEN** an approved request whose sealed result an approving officer has posted
- **WHEN** the user, in the browser that filed the request, opens the recovery screen and sets a new master password
- **THEN** their suite MUST be re-wrapped under the new password and their existing secrets MUST decrypt
- **AND** the request MUST be `fulfilled` and the sealed result MUST no longer be stored

#### Scenario: Nobody else can fetch the handoff material

- **GIVEN** an approved request
- **WHEN** a user who is not an approving officer asks for the handoff material, or anyone but the requester asks for the sealed result
- **THEN** the system MUST refuse with the same response it gives for an unknown request

### Requirement: The user is told what happened and offered a rotation

After completion the web app MUST tell the user which officer handled the recovery and MUST offer a compromise-recovery key rotation. Every recovery step MUST be recorded in the audit trail with identifiers only.

#### Scenario: Recovery notice

- **GIVEN** a user who just completed a recovery handled by officer `omar`
- **WHEN** the vault opens
- **THEN** the web app MUST show that `omar` handled the recovery and offer to rotate the vault key

### Requirement: Enrolments and officer copies follow the suite

A compromise-recovery rotation MUST rebuild the user's enrolment for the new suite in the rotating browser, and the system MUST delete enrolments left on the old suite once the migration completes. Revoking a suite MUST delete its enrolment and end its open requests. An officer's rotation MUST re-wrap their recovery copy to their new suite. Removing an officer MUST delete their copy and the admin section MUST offer a recovery key rotation, after which each enrolled user's browser re-enrols at its next unlock.

#### Scenario: Rotation keeps the user enrolled

- **GIVEN** an enrolled user who completes a compromise-recovery rotation
- **WHEN** the migration completes
- **THEN** exactly one enrolment MUST exist for the user, bound to the new suite

### Requirement: Force-revocation warns about enrolled users

The administrator suite section MUST warn, before a force-revocation, that the suite's owner is enrolled in account recovery and that recovery keeps their secrets while revocation deletes the enrolment.

#### Scenario: Warning before revoking an enrolled user's suite

- **GIVEN** an administrator in the encryption suites section of the Keepiq admin settings
- **WHEN** they enter the suite id of an enrolled user
- **THEN** the section MUST show the enrolled-user warning before the force-revoke action
