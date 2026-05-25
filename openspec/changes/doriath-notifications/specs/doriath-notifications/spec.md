# doriath-notifications

## ADDED Requirements

### Requirement: Secrets-manager schemas declare notification rules

Each doriath secrets-manager schema SHALL declare `x-openregister-notifications` rules.
The `application`, `share`, `secret-request`, `secret`, and `encryption-suite`
schemas declare these rules (once those entities are declared as OpenRegister
schemas in `lib/Settings/doriath_register.json`) so
that the OpenRegister notification engine, rather than bespoke imperative
`NotificationService` calls, dispatches the notifications the doriath specs
mandate (app pending approval, share received, secret-request incoming/fulfilled,
secret compromised, CA expiry). Every subject SHALL be metadata-only (no secret
values, keys, or login material) and provide both `nl` and `en` strings.

#### Scenario: Application pending approval notifies vault admins

- **WHEN** an `application` record is created with `status` = `pending`
- **THEN** the engine dispatches an `nc-notification` to the `admin` group
- **AND** the subject contains only the application name (no credentials)

#### Scenario: Share received notifies the recipient

- **WHEN** a `share` record is created
- **THEN** the engine dispatches an `nc-notification` to the recipient uid on the record

#### Scenario: Status-change rules are blocked on the engine field-change condition

- **WHEN** the `secret-compromised` or `secret-request-fulfilled` rule (an `updated` field-change rule) is evaluated
- **THEN** it does not fire until the OpenRegister `notification-updated-field-change-condition` engine change ships, and it remains disabled by default until then
