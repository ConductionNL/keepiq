# OpenSpec — Specifications & Architecture

This folder contains feature specifications, architectural decisions, and implementation specs for this app.

## Structure

| File / Folder | Purpose |
|---|---|
| `config.yaml` | OpenSpec project configuration — app identity, context, and rules |
| `ROADMAP.md` | High-level product roadmap |
| `specs/` | Feature specs — what the app should do (input for OpenSpec changes) |
| `architecture/` | App-specific Architectural Decision Records |
| `changes/` | Individual change directories, each with a full set of specification artifacts |

## Artifact Progression

Each change in `changes/` moves through these artifacts:

```
proposal.md ──► specs.md ──► design.md ──► tasks.md ──► plan.json
                                                          │
                                                          ▼
                                                    GitHub Issues
                                                          │
                                                          ▼
                                                    implementation
                                                          │
                                                          ▼
                                                    archive/
```

## Workflow

1. **Explore** — Use `/opsx:app-explore` to identify features and capture decisions in `specs/` and `architecture/`
2. **Plan** — When a feature spec reaches `planned` status, use `/opsx:ff` to create a change spec
3. **Implement** — Use `/opsx:apply` to implement the tasks
4. **Verify** — Use `/opsx:verify` to check implementation matches the spec
5. **Archive** — Use `/opsx:archive` to move completed changes to `changes/archive/`

## Commands

| Command | Purpose |
|---------|---------|
| `/opsx:app-explore` | Think through and update feature specs and ADRs |
| `/opsx:ff {name}` | Create all artifacts for a new change at once |
| `/opsx:new {name}` | Start a new change (step-by-step) |
| `/opsx:continue` | Generate the next artifact in the sequence |
| `/opsx:apply` | Implement tasks from a change |
| `/opsx:verify` | Verify implementation matches the spec |
| `/opsx:archive` | Archive a completed change |
