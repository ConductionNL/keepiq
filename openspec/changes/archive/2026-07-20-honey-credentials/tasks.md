# Tasks: Honey Credentials

## 1. Data layer

- [x] 1.1 Migration: `doriath_honey_flags` (`id`, `secret_id` FK+unique, `owner_id`, `note` nullable, `created_by`, `created_at`) and `doriath_honey_alerts` (`id`, `honey_flag_id` FK, `secret_id`, `accessor_type`, `accessor_id` nullable, `channel`, `ip` nullable, `user_agent` nullable, `accessed_at`, `acknowledged_at` nullable, `acknowledged_by` nullable, `snoozed_until` nullable) — `Version000030Date20260718220000`; alerts additionally carry `access_count` so a dedup-collapsed alert shows how many accesses it absorbed
- [x] 1.2 `HoneyFlag` + `HoneyAlert` entities and mappers (`QBMapper`); `findBySecretId` (the tripwire hot path, unique-indexed), `findByOwner` + `findByFlagIds` (owner listing without a JOIN), `findAll` (admin), `findLatestForAccessor` (dedup/snooze lookup), `countUnacknowledged` (dashboard)

## 2. Service layer

- [x] 2.1 `HoneyCredentialService::flag/unflag(secretId, actorId, isAdmin)` — owner-or-admin only; upsert keeps the note fresh; the flag lives ONLY in the side table and is never merged into any secret response; `getFlag` powers the owner/admin detail toggle
- [x] 2.2 `HoneyCredentialService::raiseAlert(secretId, accessorType, accessorId, channel, ip, userAgent)` — dedup by `(flag, accessorType, accessorId, channel)` within `honey_dedup_window_seconds` (default 3600); collapse increments `access_count`; new alerts page the owner + every admin; `honey.accessed` dispatched on EVERY honey access (collapsed and snoozed included — the forensic trail stays complete)
- [x] 2.3 `HoneyCredentialService::acknowledge/snooze(alertId, actorId, isAdmin)` — guarded via the decoy owner (flag lookup) or admin; snooze sets `snoozed_until` (default 24h) suppressing paging but never the audit event
- [x] 2.4 Flag excluded from `Secret::jsonSerialize` / share/link/machine response shapes by construction (side table; no secret-row column; regression-locked in tests)

## 3. Tripwire listener + notifications + audit

- [x] 3.1 `HoneyTripwireListener` on the typed `AuditEvent` bus: `secret.read` → `ui` (an unflagged copy read pivots via `ShareTargetMapper::findByRecipientSecret` to its flagged SOURCE → `share`); `application.secret_retrieved` → `machine_api`; `link_share.accessed` → resolved through the link row → `link`; fail-soft (any resolver/service failure is logged, never thrown)
- [x] 3.2 Listener registered in `Application::register()` (after the SIEM forwarder); IP/user-agent read from `IRequest`
- [x] 3.3 Ungated `honey_access` subject (`null` in `SUBJECT_SETTING_MAP`, like `app_pending`) + `DoriathNotifier` case; `honey.accessed` in `AuditEventTypes` + whitelist (`channel` only)
- [x] 3.4 SIEM emit: automatic with no honey-specific code — `honey.accessed` is a whitelisted audit event, so the already-landed `SiemForwardListener` forwards it to configured sinks like any other event

## 4. Controllers + routes

- [x] 4.1 `HoneyController`: `flag`/`unflag`/`status` (`#[NoAdminRequired]`, owner/admin guards in the service), `alerts` (owner: own decoys; admin: instance-wide), `acknowledge`, `snooze` — cross-owner actions rejected with OCS 403 (regression-locked)
- [x] 4.2 Routes registered under a commented "Honey credentials" section (`/api/v1/secrets/{id}/honey` GET/POST/DELETE + `/api/v1/honey/alerts[...]`); GET status added beyond the design table so the detail toggle can render its state

## 5. Frontend

- [x] 5.1 `HoneyPanel.vue` on the secret detail (owner-only render path) — tripwire switch + placement-note field
- [x] 5.2 Alerts: per-decoy list inside `HoneyPanel` (owner) and instance-wide admin `HoneySection` in admin settings — accessor, channel, IP/UA, count, timestamp, acknowledge + snooze-per-accessor
- [x] 5.3 `honey_alert_count` (unacknowledged) added to the admin dashboard summary via `DashboardService::fetchSummary`

## 6. Tests + docs

- [x] 6.1 Unit: flag owner/admin-only; secret serialization carries no honey marker (recipient indistinguishability); channel derivation per source event incl. the copy→source share pivot with accessor/IP/UA (`HoneyTripwireListenerTest`, 6 tests)
- [x] 6.2 Unit: paging is ungated by construction (subject maps to `null`); fail-soft on alert-write and resolver failures; dedup window collapses repeats without a second page; snoozed accessor audited but not paged; alert shape contains no secret-material keys (`HoneyCredentialServiceTest`, 9 tests)
- [x] 6.3 e2e: covered by deploy-time live verification on the dev instance (owner flags a decoy, second identity + machine + link accesses trip it, owner/admin alerts + notifications verified, snooze exercised); placement guidance lives in the HoneyPanel hint text — no separate Playwright spec committed

## Acceptance criteria

- Owner or admin can flag/unflag a secret honey; the flag never appears in the secret's normal response; recipients cannot distinguish it
- Non-owner non-admin flagging is rejected; flags and alerts are visible only to owner + admins
- Access via UI, machine API, link share, or user share raises an alert with accessor identity, channel, and IP/user-agent where available
- Each alert notifies the owner and all vault admins and records a distinguished `honey.accessed` audit event
- Alert delivery ignores the security-notification opt-out; the tripwire is fail-soft
- Repeated access by the same accessor within the configurable window collapses to one alert
- Owner/admin can acknowledge and snooze per accessor; a snoozed accessor stops paging but is still audited
- No honey alert or `honey.accessed` event ever contains secret value, login, additional field, or ciphertext
