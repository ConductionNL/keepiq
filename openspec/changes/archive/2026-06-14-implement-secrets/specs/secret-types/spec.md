## ADDED Requirements

### Requirement: System SecretTypes
The system MUST seed 6 immutable system SecretTypes on install via an IRepairStep. System types have scope `system` and deterministic UUIDs (v5). System types MUST NOT be modified or deleted by any user or administrator. The 6 system types are: `login` (Login), `api_key` (API Key), `ssh_key` (SSH Key), `certificate` (Certificate), `note` (Secure Note), `database` (Database).

#### Scenario: System types exist after install
- **WHEN** the app is installed and repair steps have run
- **THEN** 6 SecretTypes with scope `system` MUST exist in the database with the defined names and labels

#### Scenario: System type modification blocked
- **WHEN** a user or admin attempts to update a SecretType with scope `system`
- **THEN** the system MUST return a 403 error indicating system types cannot be modified

#### Scenario: System type deletion blocked
- **WHEN** a user or admin attempts to delete a SecretType with scope `system`
- **THEN** the system MUST return a 403 error indicating system types cannot be deleted

#### Scenario: Repair step is idempotent
- **WHEN** `occ maintenance:repair` runs and system types already exist
- **THEN** the repair step MUST not create duplicate types and MUST not modify existing system types

### Requirement: User Custom SecretTypes
The system MUST allow authenticated users to create SecretTypes with scope `user`. User-scoped types MUST be visible only to the user who created them. The owner_id MUST be set to the creating user's UID. Users MUST be able to rename and delete their own custom types.

#### Scenario: Create user custom type
- **WHEN** a user creates a SecretType with a name and label
- **THEN** the system MUST create the type with scope `user`, owner_id set to the user's UID, and a generated UUID

#### Scenario: User type visibility
- **WHEN** a user lists available SecretTypes
- **THEN** the system MUST return all system types, all global types, and only the user's own user-scoped types

#### Scenario: Rename user custom type
- **WHEN** a user updates the label of a SecretType they own
- **THEN** the system MUST update the label

#### Scenario: User cannot modify another user's type
- **WHEN** a user attempts to update a SecretType owned by another user
- **THEN** the system MUST return a 403 error

### Requirement: Admin Global SecretTypes
The system MUST allow administrators to create SecretTypes with scope `global`. Global types MUST be visible to all users on the instance. The owner_id MUST be null for global types. Admins MUST be able to rename and delete global types.

#### Scenario: Create global type
- **WHEN** an admin creates a SecretType with scope `global`
- **THEN** the system MUST create the type with scope `global`, owner_id null, and a generated UUID

#### Scenario: Global type visible to all users
- **WHEN** any user lists available SecretTypes
- **THEN** the system MUST include all global types in the response

#### Scenario: Non-admin cannot create global type
- **WHEN** a non-admin user attempts to create a SecretType with scope `global`
- **THEN** the system MUST return a 403 error

### Requirement: Default Type Assignment
Every secret MUST have a type. If no type_id is specified when creating a secret, the system MUST assign the `login` system type as the default.

#### Scenario: Secret created without type
- **WHEN** a user creates a secret without specifying a type_id
- **THEN** the system MUST set type_id to the `login` system type's UUID

#### Scenario: Secret created with explicit type
- **WHEN** a user creates a secret with a valid type_id
- **THEN** the system MUST use the specified type_id

### Requirement: Custom Type Deletion with Fallback
When a custom SecretType (user or global scope) is deleted, all secrets currently assigned to that type MUST be reassigned to the `login` system type. The reassignment MUST happen atomically with the deletion.

#### Scenario: Delete custom type with assigned secrets
- **WHEN** a user deletes a custom SecretType that has 5 secrets assigned to it
- **THEN** all 5 secrets MUST have their type_id updated to the `login` system type
- **AND** the SecretType MUST be deleted

#### Scenario: Delete custom type with no assigned secrets
- **WHEN** a user deletes a custom SecretType with no secrets assigned
- **THEN** the SecretType MUST be deleted

### Requirement: Unique Type Names
SecretType names MUST be globally unique across all scopes. The system MUST NOT allow any two SecretTypes with the same name, regardless of scope or owner. This prevents confusion when assigning types to secrets — each name unambiguously identifies one type.

#### Scenario: Duplicate name rejected
- **WHEN** a user or admin attempts to create a SecretType with a name that already exists in any scope
- **THEN** the system MUST return a 409 Conflict error

#### Scenario: User cannot shadow system type
- **WHEN** a user attempts to create a user-scoped type named `login` (which is a system type)
- **THEN** the system MUST reject it with a 409 Conflict error
