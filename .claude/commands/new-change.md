---
description: Start a new OpenSpec change in openspec/changes/
---

Start an OpenSpec change named `$ARGUMENTS` (kebab-case, for example `vault-item-clone-preview-and-print`).

1. Read `openspec/config.yaml` for the project rules, and `openspec/specs/` for the capabilities the change touches.
2. Create `openspec/changes/<name>/` with the same files as the existing changes there:
   - `proposal.md` with `kind: code` front matter and the sections `## Why`, `## What Changes`, `## Capabilities` and `## Impact`.
   - `design.md` with one section per design decision.
   - `tasks.md` with numbered checkbox tasks (`- [ ] 1.1 ...`), each naming how it is verified.
   - `specs/<capability>/spec.md` per capability, using `## ADDED Requirements` or `## MODIFIED Requirements`, then `### Requirement: ...` and `#### Scenario: ...`.
3. Do not edit `openspec/specs/` directly; the change's delta specs are merged there when it is archived to `openspec/changes/archive/YYYY-MM-DD-<name>/`.
