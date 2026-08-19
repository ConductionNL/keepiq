## 1. Guard test

- [ ] 1.1 Create `tests/unit/Settings/RegisterLeafGuardTest.php`: load `lib/Settings/doriath_register.json` plus every `lib/Settings/register.d/*.json`, walk all schema `configuration` blocks, and assert neither `linkedTypes` nor `mailObjectTemplate` appears anywhere. Failure message names `openspec/specs/integration-boundary/spec.md` and its exception gate.
- [ ] 1.2 Prove the guard can fail (positive control): run it once against an in-memory fixture that adds `linkedTypes` to the `example` schema and assert it reports the violation — then keep that as a second test case, not a one-off.

## 2. Documentation

- [ ] 2.1 Add the boundary statement to `docs/FEATURES.md` (security-model section): OR integration leaves are deliberately not adopted; secret material and vault-structure metadata never leave the vault's ACL envelope; expiry/rotation visibility is served by the in-app scan/notification pipeline.
- [ ] 2.2 One line in `CHANGELOG.md` (documented decision + guard test).

## 3. Verify

- [ ] 3.1 `openspec validate --strict leaf-integrations` exits 0.
- [ ] 3.2 Full PHPUnit run: the guard passes against HEAD's register (no leaf configuration exists today) and zero other failures vs a self-measured baseline.
- [ ] 3.3 Confirm no runtime file changed: `git status` shows only the test, the two docs, and the openspec artifacts.

## Acceptance criteria

- The `integration-boundary` capability exists with the four requirements (metadata rule, no-leaf declaration with per-leaf refusals, in-app expiry visibility, exception gate).
- The guard test demonstrably fails on a leaf declaration and passes on HEAD.
- No leaf, schema, or runtime behaviour ships.
