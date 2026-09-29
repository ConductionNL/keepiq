# Design: adjust the column mapping in the import wizard

## Context

At development `156cd800`:

- `src/dialogs/ImportWizardDialog.vue:66-117` preview table; `:468` masks sensitive cells but nothing sets `revealed[key]`; `:506` `parseFile` call passes only the passphrase.
- `src/import/parsers/csv.js:120` accepts `options.mapping`; `:142` declares `adjustableMapping`.
- `src/store/modules/import.js:57` holds a `mapping` state that nothing writes.
- Vendor formats (Bitwarden, 1Password, and so on) have fixed mappings and keep them.

## Goals / Non-Goals

**Goals**
- The user controls the column mapping of a generic CSV before anything is stored.

**Non-Goals**
- Mapping for vendor formats.
- Saving a mapping as a preset.

## Decisions

### D1: Mapping for generic CSV only

Vendor exports have a known shape; showing selects for them adds a way to break a working import. The step appears only when the parser reports `adjustableMapping`.

### D2: The store owns the mapping

The wizard writes the mapping into the import store and both the preview and the import read it from there, so they cannot disagree.
