## ADDED Requirements

### Requirement: Shares and memberships can carry an end date

The system MUST let the owner set, change or clear an end date on a user share, a group share and a team-folder membership, and MUST refuse a date in the past. The end date MUST be materialised on each recipient copy as `access_expires_at`; a copy reached through several grants MUST take the latest end date, and a grant without an end date MUST win. The recipient MUST NOT be able to change it.

#### Scenario: A replacement during leave

- **GIVEN** a vault owner sharing "Payroll portal" with Carla, who covers during a colleague's leave
- **WHEN** they set "Access ends on" to the colleague's return date
- **THEN** Carla's copy MUST carry that `access_expires_at`

#### Scenario: A past date is refused

- **GIVEN** a vault owner in the share dialog
- **WHEN** they submit an end date in the past
- **THEN** the system MUST reject the request with a bad-request response

### Requirement: The server stops serving an expired copy at its end date

Every read path that serves a recipient's secrets (the secret list and detail, the extension match, unified search and the offline manifest) MUST exclude a copy whose `access_expires_at` has passed, using the database clock.

#### Scenario: Access ends on time

- **GIVEN** Carla's copy with an end date of today at 17:00
- **WHEN** she opens the secret list at /secrets at 17:01
- **THEN** "Payroll portal" MUST NOT be listed
- **AND** `GET /api/v1/secrets/{id}` for her copy MUST answer as for an unknown secret

### Requirement: A background job removes expired access

A background job running every 15 minutes MUST revoke expired share targets through the existing revocation path, remove expired team-folder memberships through the existing removal path, and recompute the flags of copies still covered by another grant.

#### Scenario: The copy is deleted after its end

- **GIVEN** an expired share target
- **WHEN** the job runs
- **THEN** the share target and Carla's copy MUST be deleted

### Requirement: An expiring copy cannot be shared onward

The system MUST refuse any share whose source is a copy with an end date, by every path that creates a share, and MUST leave such copies out of team-folder fan-out.

#### Scenario: Carla cannot extend her own access

- **GIVEN** Carla's expiring copy
- **WHEN** she tries to share it with her personal account
- **THEN** the system MUST refuse and create no copy

### Requirement: People are told before and when access ends

The recipient MUST be notified a day before the end date and when access ends. The owner MUST be notified when access ends; for a share that was not use-only the notice MUST suggest rotating the value because the recipient could see it.

#### Scenario: Owner gets a rotation hint

- **GIVEN** a non-use-only share to Carla that just expired
- **WHEN** the job removes it
- **THEN** the owner MUST receive a notification that Carla's access ended and that Carla could see the password

### Requirement: Offline copies respect the end date

The offline manifest MUST carry each copy's `accessExpiresAt`, and the offline client MUST refuse to decrypt a copy past it and drop it at the next sync.

#### Scenario: An offline snapshot past the end date

- **GIVEN** Carla's offline snapshot holding a copy whose end date has passed
- **WHEN** she unlocks offline and opens it
- **THEN** the web app MUST NOT decrypt it and MUST say her access ended
