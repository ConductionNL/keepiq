# Trace the lock gate and split a colliding scenario

## Why

Six `@e2e` anchors in `tests/e2e/` named scenarios that did not exist (#209), so the tests ran and were credited to nothing. The behaviour they test (a locked vault sends every route to the lock screen) was written down in requirement prose, never as a scenario. Separately, two scenarios in `application-mgmt` had the same title, so no anchor could tell them apart (#215).

## What changes

- Adds the scenarios the tests already assert: `dashboard#dashboard-route-gated-by-lock`, `secrets#vault-route-gated-by-lock`, `secrets#secret-detail-route-gated-by-lock`, `secrets#folder-route-gated-by-lock` and `encryption-suites#user-views-lock-screen`.
- Renames the notification scenario in `application-mgmt` to "Pending registration notifies vault administrators".

No code changes: the behaviour is built and tested. This change only records it, so it is archived on creation.
