---
kind: code
---

# Adjust the column mapping in the import wizard

## Why

The import wizard shows a five-row preview (`src/dialogs/ImportWizardDialog.vue:66-117`) but the user cannot change which column feeds which field. The CSV parser already accepts `options.mapping` and declares `adjustableMapping` (`src/import/parsers/csv.js:120,142`), yet the wizard calls `parseFile(text, format, {passphrase})` (`:506`) and the import store's `mapping` state (`src/store/modules/import.js:57`) is never written. A wrongly guessed column silently imports a password as a note. Keeper and Nextcloud Passwords rate yes.

One row, one change.

### Matrix rows (`keepiq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `portability-03` | Preview and adjust the field mapping before importing. | `partial`: `partial`: the wizard previews the first five rows read-only; the CSV parser accepts a mapping but the wizard never passes one |

### Demand

- `portability-03`: no demand row.

### Competitors rated yes

- `portability-03`, keeper: "https://docs.keeper.io/user-guides/web-vault#field-mapping : field mapping screen ('Click any field to open a dropdown menu'), then 'a summary screen will display a preview of your vault' before import"
- `portability-03`, nextcloud-passwords: "marius-wieschollek/passwords@2026.9.0 src/vue/Components/Import.vue:172-189 'Preview Line' select and csv-mapping field selectors via csvFieldMapping() Note: Custom CSV imports show a preview row and let you map each column; prede"

## What Changes

- Add a mapping step for CSV imports: one select per target field (name, url, login, password, notes, folder, type) pre-filled from the detected header.
- Re-run the preview when the mapping changes, and pass the mapping to `parseFile`.
- Make masked preview cells revealable, which the current code cannot do.

## Capabilities

### New Capabilities

- `portability-import-mapping`

### Modified Capabilities

- None in delta form.

## Impact

- **Frontend**: `ImportWizardDialog.vue` (split the mapping step into its own dialog file per the modal-isolation gate), `import.js` store, `csv.js` call site.
- **Backend**: none; import is client-side then batch create.
- **Database**: none.
