## 1. Frontend fix

- [x] 1.1 In `src/components/SecretListItem.vue`, gave the root `<div>` a real
      interactive semantic — chose option (b): `role="button"`, `tabindex="0"`,
      `:aria-label="t('doriath', 'Open {name}', { name: secret.name })"`,
      `@keydown.enter="onRowActivate"` and `@keydown.space.prevent="onRowActivate"`.
      Option (a) (native `<button>` root) was rejected because the row nests
      the `CopyButton` (a real `<button>`), and a button inside a button is
      invalid HTML.
- [x] 1.2 Guarded the inner `CopyButton` slot so keyboard activation of the
      copy action does not bubble an `open` event to the row: added
      `@keydown.enter.stop @keydown.space.stop` to the `.secret-list-item__actions`
      wrapper (alongside the existing `@click.stop`), AND `onRowActivate` only
      acts when `event.target === event.currentTarget` (defence in depth)
- [x] 1.3 Added a `:focus-visible` style using NC CSS custom properties
      (`outline: 2px solid var(--color-primary-element)`), no hardcoded colors
- [x] 1.4 Verified the existing `.secret-list-item` / `.secret-list-item__name`
      selectors used by Playwright still resolve after the markup change (only
      attributes + handlers were added to the same root `<div>`)

## 2. Tests

- [x] 2.1 Added a Playwright test (`tests/e2e/workflows/secret-row-keyboard.spec.ts`):
      focus the first secret row, press `Enter`, assert the detail card renders —
      no mouse events. **Needs a live seeded instance to execute** (not run here;
      the spec file exists so gate-19 traceability is satisfied)
- [x] 2.2 Added a Playwright test asserting the copy-password control is
      independently focusable via keyboard and activating it does NOT open the
      row (same spec file). Also covered at the unit level (see 2.3)
- [x] 2.3 Ran + extended the vitest unit tests for the row
      (`tests/components/SecretListItem.spec.js`): added role/tabindex/aria-label,
      Enter-opens, Space-opens, and copy-keyboard-does-not-bubble cases —
      10/10 green; full suite 213/213 green (no regression)

## 3. Spec sync

- [x] 3.2 Tagged the new scenario with
      `@e2e openspec/specs/secrets-write-ui/spec.md#secret-list-rows-must-be-keyboard-operable`
      in `secret-row-keyboard.spec.ts` so gate-19 (e2e traceability) is satisfied
- [ ] 3.1 Apply the `specs/secrets-write-ui/spec.md` delta onto
      `openspec/specs/secrets-write-ui/spec.md` once merged — DEFERRED:
      spec-sync/archive is a post-merge Hydra step, out of scope for the apply pass
