## ADDED Requirements

### Requirement: Dashboard Summary API Endpoint
The system MUST expose a `GET /api/dashboard/summary` endpoint that returns aggregated vault data for the current user. The endpoint MUST use DashboardController -> DashboardService layering (ADR-008). The response MUST include:

| Field | Source | Notes |
|-------|--------|-------|
| `total_secrets` | SecretMapper count by owner | Owned + received shares |
| `shared_secrets` | SecretShareMapper count by target_user_id | Secrets shared with this user |
| `folder_count` | FolderMapper count by owner | User's folder count |
| `compromised_count` | SecretMapper count where possibly_compromised_at IS NOT NULL | Per-user |
| `pending_apps_count` | ApplicationMapper count where status=pending | Admin only; null for non-admins |
| `migration_status` | SuiteMigrationMapper findInProgressByOwner | null if no migration |
| `ca_status` | CertificateAuthorityService getStatus | Admin only; null for non-admins |

#### Scenario: Regular user requests dashboard summary
- GIVEN a user has unlocked the vault
- WHEN they call GET /api/dashboard/summary
- THEN the response MUST include total_secrets, shared_secrets, folder_count, compromised_count, and migration_status
- AND pending_apps_count and ca_status MUST be null

#### Scenario: Admin user requests dashboard summary
- GIVEN a vault administrator has unlocked the vault
- WHEN they call GET /api/dashboard/summary
- THEN the response MUST include all fields including pending_apps_count and ca_status

### Requirement: Vault Summary KPI Cards [MVP]
The dashboard MUST display four KPI cards in a grid showing the user's vault status. Cards MUST be custom Doriath components (NOT CnStatsBlock from @conduction/nextcloud-vue — per spec note, Doriath implements its own KPI cards to match the security-focused design language).

| Card | Value | Icon | Variant |
|------|-------|------|---------|
| Total Secrets | total_secrets | ShieldKeyOutline | primary |
| Shared With Me | shared_secrets | ShareVariantOutline | default |
| Folders | folder_count | FolderOutline | default |
| Compromised | compromised_count | AlertCircleOutline | warning (if > 0) |

The compromised card MUST be highlighted in warning color when the count is greater than zero.

#### Scenario: User views vault summary
- GIVEN a user with 15 secrets (10 owned + 5 shared), 3 folders, and 1 compromised secret
- WHEN they view the dashboard
- THEN four KPI cards MUST display: Total Secrets: 15, Shared With Me: 5, Folders: 3, Compromised: 1

### Requirement: Empty State [MVP]
The dashboard MUST display an empty state with guidance when the user has zero secrets.

#### Scenario: New user with empty vault
- GIVEN a user with total_secrets = 0
- WHEN they view the dashboard
- THEN the dashboard MUST show an NcEmptyContent with a prompt to create their first secret
- AND the KPI cards MUST NOT be displayed

### Requirement: Migration Status Banner [MVP]
The dashboard MUST display a prominent NcNoteCard banner when a SuiteMigration is in progress or completed with errors.

#### Scenario: Migration in progress
- GIVEN migration_status has status=in_progress and remaining_count=12
- WHEN the user views the dashboard
- THEN a warning NcNoteCard MUST display: "Key migration in progress — 12 secrets remaining"
- AND clicking the banner MUST navigate to the migration resume screen via $router.push

#### Scenario: Migration completed with errors
- GIVEN migration_status has status=completed_with_errors and failed_count=3
- WHEN the user views the dashboard
- THEN an error NcNoteCard MUST display: "3 secrets failed migration — retry required"

#### Scenario: No active migration
- GIVEN migration_status is null
- WHEN the user views the dashboard
- THEN no migration banner MUST be displayed

### Requirement: Pending Applications Counter (Admin) [MVP]
The dashboard MUST display a pending application counter visible only to vault administrators.

#### Scenario: Admin with pending applications
- GIVEN pending_apps_count is 4
- WHEN a vault administrator views the dashboard
- THEN a card MUST display "4 pending applications" with a link to the approval queue in admin settings

#### Scenario: Non-admin user
- GIVEN the user is not a vault administrator
- WHEN they view the dashboard
- THEN no pending applications card MUST be visible

### Requirement: CA Health Status Card (Admin) [V1]
The dashboard MUST display a CA health card for administrators showing certificate status.

#### Scenario: CA healthy
- GIVEN ca_status shows status=healthy with intermediate expiry 2029-03-15
- WHEN an admin views the dashboard
- THEN a card MUST show "Certificate Authority: Healthy" with the expiry date

#### Scenario: CA expiring
- GIVEN ca_status shows status=expiring_soon
- WHEN an admin views the dashboard
- THEN a card MUST show warning state with a link to admin settings CA management

### Requirement: Recently Accessed Secrets Widget [V1]
The dashboard MUST display up to 5 recently accessed secrets for quick navigation. Access tracking uses a dedicated `doriath_access_log` table (separate from the secrets table) with columns: id, secret_id (FK), user_id, accessed_at. This table is created by the implement-secrets change (or a dedicated migration if needed) and populated by the SecretService on each secret read. The dashboard queries this table for the 5 most recent distinct secrets per user.

#### Scenario: User with recent activity
- GIVEN a user has accessed secrets in the past 7 days
- WHEN they view the dashboard
- THEN a "Recently Accessed" section MUST display up to 5 secrets with name and type icon
- AND clicking a secret MUST navigate to its detail view via $router.push

### Requirement: Dashboard Pinia Store
The dashboard MUST use a dedicated Pinia store (`useDashboardStore`) that fetches and caches the summary data. The store MUST call the summary API on dashboard mount and expose reactive state for all KPI values.

#### Scenario: Dashboard data loading
- GIVEN the user navigates to the dashboard
- WHEN Dashboard.vue mounts
- THEN the dashboard store MUST fetch GET /api/dashboard/summary
- AND expose isLoading, summary, and error state
