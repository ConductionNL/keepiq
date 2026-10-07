# Tasks: adjust the column mapping in the import wizard

## 1. Mapping

- [x] 1.1 Write and read `mapping` in the import store and pass it to `parseFile`. Verify: vitest that a changed mapping changes the parsed rows.
- [x] 1.2 Add the mapping step as its own dialog component with the target field selects and the required-name rule. Verify: vitest tests/dialogs/ImportWizardDialog.mapping.spec.js (one select per column, Import blocked without a name column). The Playwright flow is excluded: it needs an unlocked vault on the test instance; the scenarios carry the reason.
- [x] 1.3 Wire the per-cell reveal in the preview. Verify: vitest that only the chosen cell is revealed.

## 2. Close out

- [x] 2.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [x] 2.2 Set row `portability-03` to built, clear its defects, archive the change. Verify: parity_verify --strict.

