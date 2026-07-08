## Why

`src/components/SecretListItem.vue` — the primary row rendered for every
secret in the main vault list (`src/views/SecretList.vue:68-72`) — opens the
secret's detail view purely via a mouse-oriented DOM click handler on a plain
`<div>`:

```vue
<!-- src/components/SecretListItem.vue:2 -->
<div class="secret-list-item" :class="{ 'secret-list-item--blocked': secret.blocked }" @click="$emit('open', secret.id)">
```

This `<div>` carries no `role`, no `tabindex`, and no `@keydown.enter` /
`@keydown.space` handler. A keyboard-only user (screen-reader user, motor
impairment, or simply someone tabbing through the page) can **Tab** past every
row without ever landing on it, and has no way to activate it — the single
most common interaction in a password manager (open a secret) is
keyboard-inaccessible. This is a direct WCAG 2.1 AA violation of Success
Criterion 2.1.1 (Keyboard) and 4.1.2 (Name, Role, Value — the element has
neither an accessible role nor name as an interactive control), and
contradicts ADR-010 ("WCAG AA mandatory: keyboard-navigable ...") and
ADR-004's WCAG AA requirement.

By contrast, the sibling `FolderTree.vue` component (`src/components/FolderTree.vue:4-9`)
gets this right — it uses a native `<button type="button">` for the same kind
of "click a row to select" interaction, which is keyboard-accessible for
free. `SecretListItem.vue` is the one place in the app that reinvented this
as a bare `<div>` instead.

The row's inner "copy password" action (`CopyButton.vue`, invoked via
`@click.stop` at `SecretListItem.vue:22`) is a separate, already-focusable
control and is unaffected by this change.

## What Changes

- Give the row's root element a real interactive semantic: either swap the
  outer wrapping `<div>` for a `<button type="button">` styled to match the
  current row layout (consistent with `FolderTree.vue`'s pattern), or keep the
  `<div>` and add `role="button"`, `tabindex="0"`, an accessible name
  (`aria-label` built from the secret's name), and `@keydown.enter`/`@keydown.space`
  handlers that call the same `open` emit.
- Ensure the inner `CopyButton` remains independently focusable and
  activating it does not also trigger the row's `open` navigation (already
  true for click via `@click.stop`; extend the same guard to keyboard
  activation — e.g. `@keydown.space.stop`/`@keydown.enter.stop` on the copy
  button's own element, or confirm the native button's default
  `stopPropagation`-equivalent behaviour is sufficient and document why).
- Add a visible focus indicator for the row (CSS `:focus-visible` using NC
  focus-ring custom properties — no hardcoded colors, per ADR-010).
- Add a Playwright test exercising Tab + Enter to open a secret from the list
  without a mouse.

## Impact

- **Frontend**: `src/components/SecretListItem.vue`.
- **Tests**: new Playwright coverage (keyboard-only open flow) under
  `tests/e2e/workflows/` or `tests/e2e/spec-coverage/`, tagged so
  `openspec/specs/secrets-write-ui/spec.md`'s new scenario (below) carries a
  real `@e2e` reference instead of joining the untagged-scenario backlog.
- **Not BREAKING**: no visual regression intended beyond an added focus ring;
  mouse-click behaviour is unchanged.
