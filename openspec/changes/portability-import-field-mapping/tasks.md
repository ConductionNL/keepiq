# Tasks: adjust the column mapping in the import wizard

## 1. Mapping

- [ ] 1.1 Write and read `mapping` in the import store and pass it to `parseFile`. Verify: vitest that a changed mapping changes the parsed rows.
- [ ] 1.2 Add the mapping step as its own dialog component with the target field selects and the required-name rule. Verify: vitest for the disabled Import button; Playwright flow import a CSV with a renamed column.
- [ ] 1.3 Wire the per-cell reveal in the preview. Verify: vitest that only the chosen cell is revealed.

## 2. Close out

- [ ] 2.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 2.2 Set row `portability-03` to built, clear its defects, archive the change. Verify: parity_verify --strict.

