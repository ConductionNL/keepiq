# Tasks: Honey Credentials

## 1. Data layer

- [ ] 1.1 Migration: `doriath_honey_flags` (`id`, `secret_id` FK+unique, `owner_id`, `note` nullable, `created_by`, `created_at`) and `doriath_honey_alerts` (`id`, `honey_flag_id` FK, `secret_id`, `accessor_type`, `accessor_id` nullable, `channel`, `ip` nullable, `user_agent` nullable, `accessed_at`, `acknowledged_at` nullable, `acknowledged_by` nullable, `snoozed_until` nullable)
- [ ] 1.2 `HoneyFlag` + `HoneyAlert` entities and mappers (`QBMapper`); `findFlagBySecretId`, `findAlertsByOwner`, instance-wide admin query, dedup lookup by accessor+window

## 2. Service layer

- [ ] 2.1 `HoneyCredentialService::flag/unflag(secretId, actorId, isAdmin)` — owner-or-admin only; flag never serialized into the secret response
- [ ] 2.2 `HoneyCredentialService::raiseAlert(secretId, accessor, channel, ip, userAgent)` — dedup by `(flag, accessor, channel)` within the configurable window; insert/update alert row; notify owner + all admins; dispatch `honey.accessed`
- [ ] 2.3 `HoneyCredentialService::acknowledge/snooze(alertId, actorId, isAdmin)` — per-accessor snooze; snooze suppresses paging but not the audit event
- [ ] 2.4 Ensure the honey flag is excluded from `Secret::jsonSerialize` / share/link/machine response shapes (recipients cannot distinguish)

## 3. Tripwire listener + notifications + audit

- [ ] 3.1 `HoneyTripwireListener` subscribing to the typed `AuditEvent` bus: on `secret.read` / `application.secret_retrieved` / `link_share.accessed` / share-recipient read of a honey-flagged secret, derive channel from event type and call `raiseAlert`; fail-soft (never blocks the access)
- [ ] 3.2 Register the listener in `Application::register()`; read IP/user-agent from `IRequest`
- [ ] 3.3 Add ungated `honey_access` subject to `NotificationService::SUBJECT_SETTING_MAP` (value `null`) + `DoriathNotifier` case; add `honey.accessed` to `AuditEventTypes` + whitelist (`channel` only)
- [ ] 3.4 Optional SIEM emit — class-existence-guarded on the sibling `siem-audit-export` change (no hard dependency)

## 4. Controllers + routes

- [ ] 4.1 `HoneyController`: `flag`/`unflag` (`#[NoAdminRequired]`, owner/admin), `alerts` (owner: own; admin: instance-wide), `acknowledge`, `snooze` — each with explicit auth attribute + per-object guard
- [ ] 4.2 Register routes in `appinfo/routes.php` under a commented "Honey credentials" section

## 5. Frontend

- [ ] 5.1 Honey toggle on the secret detail (visible to owner/admin only) with placement-note field
- [ ] 5.2 Honey alerts panel (owner + admin): accessor, channel, IP/UA, timestamp, acknowledge + snooze-per-accessor actions
- [ ] 5.3 High-severity honey-alert count on the admin dashboard

## 6. Tests + docs

- [ ] 6.1 Unit: flag owner/admin-only + never serialized + recipient indistinguishability; access via each channel raises an alert with accessor/channel/IP
- [ ] 6.2 Unit: ungated delivery despite opt-out; fail-soft (listener error does not block the read); dedup window collapses repeats; snooze suppresses paging but still audits; no secret material in alert or `honey.accessed`
- [ ] 6.3 e2e (Playwright): owner flags a secret honey, a second identity reads it, owner + admin receive the alert, owner snoozes that accessor; add placement-guidance docs

## Acceptance criteria

- Owner or admin can flag/unflag a secret honey; the flag never appears in the secret's normal response; recipients cannot distinguish it
- Non-owner non-admin flagging is rejected; flags and alerts are visible only to owner + admins
- Access via UI, machine API, link share, or user share raises an alert with accessor identity, channel, and IP/user-agent where available
- Each alert notifies the owner and all vault admins and records a distinguished `honey.accessed` audit event
- Alert delivery ignores the security-notification opt-out; the tripwire is fail-soft
- Repeated access by the same accessor within the configurable window collapses to one alert
- Owner/admin can acknowledge and snooze per accessor; a snoozed accessor stops paging but is still audited
- No honey alert or `honey.accessed` event ever contains secret value, login, additional field, or ciphertext
