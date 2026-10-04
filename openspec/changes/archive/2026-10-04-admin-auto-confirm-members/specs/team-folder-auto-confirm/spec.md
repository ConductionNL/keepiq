## ADDED Requirements

### Requirement: Administrator switches automatic member confirmation on

The system MUST offer an admin policy switch `team_folder_auto_confirm`, off by default, in the Policies area of the Keepiq admin settings. Only an administrator MUST be able to change it. Every change MUST be audited. `GET /api/settings/policy` MUST expose its value to the browser. With the switch off, new members MUST receive copies only through the owner's fan-out, as before.

#### Scenario: Administrator turns on automatic confirmation

- **GIVEN** an administrator on the Keepiq admin settings page
- **WHEN** they switch on "Automatically confirm new team folder members" and save
- **THEN** `GET /api/settings/policy` MUST return `team_folder_auto_confirm` as true
- **AND** one policy audit event MUST be recorded

### Requirement: Pending confirmations are served to authorised confirmers only

`GET /api/v1/team-folders/pending-confirmations` MUST return, for the session user, each team folder where that user is the owner or has an effective `write` grade and where covered members still miss copies. Each entry MUST carry the missing pairs, the recipients' certificates and, for a member, the ids of their own copies. It MUST exclude disabled accounts and users without an active suite. It MUST return an empty list when the switch is off or the user is a `read` member or a non-member.

#### Scenario: Write member sees a waiting colleague

- **GIVEN** the switch is on, `hank` has a `write` grade on team folder `Ops`, and `kim` just joined group `ops-team`, a member of `Ops`
- **WHEN** `hank`'s browser calls `GET /api/v1/team-folders/pending-confirmations`
- **THEN** the response MUST list `Ops` with the missing pairs for `kim` and `kim`'s certificate

#### Scenario: Read member sees nothing

- **GIVEN** the switch is on and `jack` has a `read` grade on team folder `Ops` with a waiting member
- **WHEN** `jack`'s browser calls `GET /api/v1/team-folders/pending-confirmations`
- **THEN** the response MUST be an empty list

### Requirement: An unlocked confirmer's browser confirms without a click

When the switch is on, the browser of an authorised confirmer MUST, after the vault unlocks and every 15 minutes while it stays unlocked, fetch pending confirmations, decrypt the needed secret (the owner's source, or the member's own copy) with the session key, encrypt it under each recipient's certificate, and post the rows to `POST /api/v1/team-folders/{id}/shares`. No request MUST carry plaintext. The run MUST stop when the vault locks.

#### Scenario: New member gets access without the owner

- **GIVEN** the switch is on, owner `iris` of team folder `Ops` is away, and `kim` joined a member group of `Ops`
- **WHEN** `write` member `hank` unlocks his vault on the lock screen at `/lock`
- **THEN** `kim` MUST receive a copy of every secret in `Ops` without any click
- **AND** `kim` MUST receive the "team folder shared" notification
- **AND** `iris` MUST receive a notification that `hank` confirmed `kim`

### Requirement: The server accepts a confirmer's row only when it is safe

`POST /api/v1/team-folders/{id}/shares` MUST accept a row from a non-owner only when the caller's effective grade on the folder is `write`, the target user is covered by a membership row and still misses that copy, the source secret is inside the folder subtree, and the caller's own copy of that source is not older than the source's last key change. Otherwise the row MUST be skipped and nothing MUST be stored for it. The server MUST NOT decrypt any submitted blob.

#### Scenario: Stale copy is refused

- **GIVEN** `hank`'s copy of secret `db-root` in `Ops` is older than the last key change of the source
- **WHEN** `hank`'s browser posts a row for `db-root` to new member `kim`
- **THEN** no copy for `kim` MUST be stored from that row
- **AND** the pair MUST stay pending for the next confirmer

#### Scenario: Uncovered user is refused

- **GIVEN** user `lee` is not covered by any membership row of `Ops`
- **WHEN** a `write` member of `Ops` posts a row targeting `lee`
- **THEN** no copy for `lee` MUST be stored
