# secrets-write-ui Specification Delta

## ADDED Requirements

### Requirement: Secret List Rows MUST Be Keyboard-Operable

The secret row rendered by `SecretListItem.vue` for every entry in the vault list MUST expose a real interactive semantic (a native `<button type="button">`, or a `<div>` carrying `role="button"`, `tabindex="0"`, and an accessible `aria-label` built from the secret's name) instead of a bare non-interactive `<div>` with only a mouse click handler. The row MUST be reachable via `Tab` and MUST open the secret's detail view when activated via `Enter` or `Space`, in addition to the existing mouse click. This satisfies WCAG 2.1 AA Success Criteria 2.1.1 (Keyboard) and 4.1.2 (Name, Role, Value), and ADR-010's mandatory keyboard-navigable requirement.

The row MUST display a visible focus indicator using Nextcloud focus-ring CSS custom properties (no hardcoded colors) when it receives keyboard focus via `:focus-visible`.

The inner copy-password control MUST remain independently focusable, and activating it via mouse or keyboard MUST NOT also trigger the row's `open` navigation.

#### Scenario: Opening a secret row via keyboard only

- **GIVEN** the user is tabbing through the vault list with no mouse input
- **WHEN** the user presses `Tab` until a secret row receives focus and then presses `Enter`
- **THEN** the system MUST emit the row's `open` event and navigate to that secret's detail view

#### Scenario: Space also activates a focused row

- **GIVEN** a secret row has keyboard focus
- **WHEN** the user presses `Space`
- **THEN** the system MUST emit the row's `open` event exactly once, matching the click behaviour

#### Scenario: Focused row shows a visible focus indicator

- **GIVEN** a secret row receives keyboard focus
- **WHEN** the row is rendered in that focused state
- **THEN** the system MUST render a `:focus-visible` outline using an NC CSS custom property, with no hardcoded color value

#### Scenario: Copy control does not trigger row navigation

- **GIVEN** a secret row's inner copy-password control has keyboard focus
- **WHEN** the user activates it via `Enter` or `Space`
- **THEN** the system MUST copy the password and MUST NOT also emit the row's `open` event
