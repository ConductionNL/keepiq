# Tasks: share a secret with a Nextcloud group from the secret sidebar

## 1. Wire the form

- [ ] 1.1 Create `src/store/modules/groupShare.js` with list, create and revoke actions. Verify: vitest with mocked axios asserting the three routes and payloads.
- [ ] 1.2 Rework `GroupShareForm.vue` to a group picker and the store action, and open it from the sidebar. Verify: vitest on the form; Playwright flow share with a group as owner, see the result counts.
- [ ] 1.3 List and revoke group shares in the sidebar. Verify: Playwright flow revoke, recipient loses access.

## 2. Backend check

- [ ] 2.1 Add a PHPUnit test that a user cannot create a group share for a secret they do not own and cannot target a group they cannot see. Verify: PHPUnit; hydra no-admin-idor gate.

## 3. Close out

- [ ] 3.1 Set row `sharing-02` to built, clear its defect, archive the change. Verify: parity_verify --strict.

