# Tasks: an inactivity lock that follows activity, and a timeout the user keeps

## 1. Inactivity

- [ ] 1.1 Attach throttled activity listeners in `App.vue` that call `sessionStore.updateActivity()`. Verify: vitest with fake timers for reset, no reset while idle, and throttling.
- [ ] 1.2 Represent Nextcloud session as `null` in the session store and stop the `|| 600000` fallback. Verify: vitest that `null` never locks and a number does.

## 2. Saved choice

- [ ] 2.1 Mount `SessionTimeoutSection` in personal settings, delete the in-memory select and `saveTimeout`, load the saved value in the unlock path. Verify: vitest for load at unlock; Playwright flow pick 30 minutes, reload, unlock, read the select.

## 3. Close out

- [ ] 3.1 Set rows `crypto-06` and `crypto-07` to built, clear their defects, archive the change. Verify: parity_verify --strict.

