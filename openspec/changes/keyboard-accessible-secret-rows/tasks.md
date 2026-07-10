## 1. Frontend fix

- [ ] 1.1 In `src/components/SecretListItem.vue`, replace the root `<div
      @click="$emit('open', secret.id)">` (line 2) with either:
      (a) a native `<button type="button" class="secret-list-item">` (mirrors
      `FolderTree.vue`'s pattern), or
      (b) the same `<div>` with `role="button"`, `tabindex="0"`,
      `:aria-label="t('doriath', 'Open {name}', { name: secret.name })"`, and
      `@keydown.enter="$emit('open', secret.id)"` /
      `@keydown.space.prevent="$emit('open', secret.id)"`.
      Prefer (a) unless the button's default styling/box model conflicts with
      the existing flex row layout.
- [ ] 1.2 Confirm/adjust the inner `CopyButton` slot (line ~22) so keyboard
      activation of the copy action does not also bubble an `open` event to
      the row (native `<button>` nesting is invalid HTML — if using option
      (a), restructure so `CopyButton` is a sibling positioned absolutely or
      the row becomes a `<div role="button">` per option (b) instead).
- [ ] 1.3 Add a `:focus-visible` style using NC CSS custom properties (e.g.
      `outline: 2px solid var(--color-primary-element)`), no hardcoded colors.
- [ ] 1.4 Verify existing `data-testid` hooks on the row (if any) still
      resolve for Playwright after the markup change.

## 2. Tests

- [ ] 2.1 Add a Playwright test: load the secret list, `Tab` to the first
      secret row, press `Enter`, assert navigation to the secret detail view
      (no mouse events used).
- [ ] 2.2 Add a Playwright test asserting the copy-password control inside a
      row is independently reachable via `Tab` and activatable via keyboard
      without triggering row navigation.
- [ ] 2.3 Run the existing SecretList/SecretListItem vitest unit tests (if
      any) and update snapshots/assertions touching the row's root tag.

## 3. Spec sync

- [ ] 3.1 Apply the `specs/secrets-write-ui/spec.md` delta in this change
      onto `openspec/specs/secrets-write-ui/spec.md` once merged.
- [ ] 3.2 Tag the new scenario with `@e2e tests/e2e/workflows/<new-file>.spec.ts`
      (or wherever task 2.1's test lands) so gate-19 (e2e traceability) is
      satisfied for the new requirement.
