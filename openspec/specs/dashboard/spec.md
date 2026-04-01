# Dashboard Specification

**Status**: in-progress

**Standards**: Nextcloud Dashboard API
**Feature tier**: MVP | V1

**OpenSpec changes:** [implement-dashboard-settings](../../changes/implement-dashboard-settings/)

## Purpose

The Doriath dashboard is the landing page after unlocking the vault. It provides a personal vault summary, quick access to recent and shared secrets, and — for administrators — CA health status and pending application registrations. The dashboard is designed as a security-aware overview: it highlights compromised secrets, active migrations, and vault health indicators.

## Data Model

No new entities. The dashboard aggregates data from existing entities:
- `Secret` — count by owner, count where `possibly_compromised_at` is set
- `SecretShare` — count of shares received
- `Application` — count where `status = pending` (admin only)
- `CACertificate` — expiry dates, health status (admin only)
- `SuiteMigration` — active migration status

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for entity definitions.

## Requirements

### Requirement: Vault Summary Cards [MVP]
The dashboard MUST display KPI summary cards showing the user's vault status at a glance.

#### Scenario: User views dashboard
- GIVEN a user has unlocked the vault
- WHEN they view the dashboard
- THEN the system MUST display:
  - Total secrets count (owned + received shares)
  - Shared secrets count (secrets shared with the user)
  - Folder count
  - Compromised secrets count (where `possibly_compromised_at` is set, highlighted in warning color)

#### Scenario: Empty vault
- GIVEN a new user has just created their EncryptionSuite
- WHEN they view the dashboard
- THEN the system MUST show an empty state with guidance to create their first secret

### Requirement: Migration Status Banner [MVP]
The dashboard MUST display a prominent banner when a suite migration is in progress or completed with errors.

#### Scenario: Migration in progress
- GIVEN a `SuiteMigration` record exists with status `in_progress`
- WHEN the user views the dashboard
- THEN the system MUST display a warning banner: "Key migration in progress — {N} secrets remaining"
- AND the banner MUST link to the migration resume screen

#### Scenario: Migration completed with errors
- GIVEN a `SuiteMigration` record exists with status `completed_with_errors`
- WHEN the user views the dashboard
- THEN the system MUST display an error banner: "{N} secrets failed migration — retry required"
- AND the banner MUST link to the failed secrets list

### Requirement: Pending Applications Counter (Admin) [MVP]
The dashboard MUST display a pending application counter to vault administrators. Non-administrators MUST NOT see this counter.

#### Scenario: Pending applications exist
- GIVEN one or more applications are in `pending` status
- WHEN a vault administrator views the dashboard
- THEN the counter MUST show the number of pending registrations
- AND the counter MUST link to the approval queue

### Requirement: CA Health Status Card (Admin) [V1]
The admin dashboard MUST display a card showing the Certificate Authority health status.

#### Scenario: CA healthy
- GIVEN the CA is configured and no renewal is needed soon
- WHEN an admin views the dashboard
- THEN the CA card MUST show "Healthy" with the intermediate expiry date

#### Scenario: CA action required
- GIVEN the intermediate certificate is within 30 days of expiry
- WHEN an admin views the dashboard
- THEN the CA card MUST show "Expiring soon" in warning state
- AND link to the CA management admin settings

### Requirement: Recently Accessed Secrets [V1]
The dashboard SHOULD display a widget showing the user's most recently accessed secrets for quick navigation.

#### Scenario: User with recent activity
- GIVEN a user has accessed secrets in the past 7 days
- WHEN they view the dashboard
- THEN the system SHOULD display up to 5 recently accessed secrets with name and type icon

## User Stories

- As a user, I want to see my vault summary at a glance so that I know how many secrets I manage
- As a user, I want to see if any secrets are compromised so that I can take action immediately
- As a user, I want to know if a key migration is in progress so that I can resume it
- As an administrator, I want to see pending application registrations so that I can approve them quickly
- As an administrator, I want to see CA health so that I know when certificates need attention

## Acceptance Criteria

- [ ] Dashboard displays vault summary cards (total secrets, shared, folders, compromised)
- [ ] Empty state is shown for new users with no secrets
- [ ] Migration status banner appears when migration is in progress or has errors
- [ ] Banner links to migration resume/retry screen
- [ ] Pending applications counter is shown to vault administrators only
- [ ] Counter links to the approval queue
- [ ] CA health card shows status to administrators (V1)
- [ ] Recently accessed secrets widget displays up to 5 items (V1)
- [ ] Dashboard is the default landing page after unlock

## Notes

- The dashboard is only accessible after unlocking the vault (master password in session). The lock screen is the pre-dashboard route.
- Unlike other Conduction apps, the dashboard does not use CnStatsBlock from `@conduction/nextcloud-vue` — Doriath implements its own KPI cards to match the security-focused design language.
- Related specs: encryption-suites (migration), application-mgmt (pending counter), secrets (compromised count)
