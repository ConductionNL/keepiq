# Tasks: an inactivity lock that follows activity, and a timeout the user keeps

## 1. Inactivity

- [x] 1.1 Attach throttled activity listeners in `App.vue` that call `sessionStore.updateActivity()`. Verify: vitest with fake timers for reset, no reset while idle, and throttling.
- [x] 1.2 Represent Nextcloud session as `null` in the session store and stop the `|| 600000` fallback. Verify: vitest that `null` never locks and a number does.

## 2. Saved choice

- [x] 2.1 Make the user-settings select save through the session store and delete the unmounted `SessionTimeoutSection` and `saveTimeout` (design D3); load the saved value when the app mounts. Verify: vitest `tests/store/session.timeout.spec.js` and `tests/components/appSessionWiring.spec.js`. The Playwright flow is excluded (a reload and unlock need a vault with a master password on the test instance); the scenario carries the reason.

## 3. Close out

- [x] 3.1 Set rows `crypto-06` and `crypto-07` to built, clear their defects, archive the change. Verify: parity_verify --strict.

