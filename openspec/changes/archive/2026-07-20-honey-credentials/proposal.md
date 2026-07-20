---
kind: code
---

# Proposal: Honey credentials — decoy secrets as tripwires

## Why

A vault is the highest-value target an intruder touches first, and Doriath's zero-knowledge model (ADR-003) means the server can never inspect a secret's *content* to detect misuse — but it CAN see every *access* to a secret. That asymmetry makes deception one of the very few post-compromise detection controls a zero-knowledge store can offer natively: mark a purpose-made decoy secret, and any access to it is a high-confidence intrusion signal. Doriath already funnels every server-observable read through a single typed-event seam — `SecretService::dispatchAudit` (`lib/Service/SecretService.php:166`) fires `secret.read` on individual blob fetches (`:705`), `application.secret_retrieved` on machine-API fetches (`:667`), and `LinkShareService` fires `link_share.accessed` (`lib/Service/LinkShareService.php:246`) — so a tripwire can hook one place and cover UI, machine API, and link channels at once. Nothing today flags a secret as a decoy or alerts on access to one (`grep -ri honey lib src` returns nothing).

Infisical ships honey tokens as a **paid** differentiator — the only adjacent product with deception built in. No self-hosted password manager has this. Post-compromise detection is exactly what NIS2 Article 21 (Dutch Cyberbeveiligingswet) asks for, and a decoy credential that pages the owner the instant an attacker touches it is pure differentiation for Doriath's public-sector target — cheap to build on the existing audit stream, impossible for a competitor whose server can read secrets to claim as uniquely zero-knowledge-native.

## What Changes

- Add a **honey flag**: an owner or administrator marks a purpose-made secret as a honey credential. The flag is stored server-side in a side table (`doriath_honey_flags`) and is NEVER serialized into the secret's normal response — the decoy value itself is a normal encrypted secret that protects nothing real, so recipients and attackers see an ordinary secret.
- Add a **tripwire**: a single central `HoneyTripwireListener` subscribes to the existing typed audit-event stream; when a `secret.read`, `application.secret_retrieved`, `link_share.accessed`, or share-recipient read references a honey-flagged secret, it immediately raises a **high-severity alert** — a Nextcloud notification to the owner and all vault administrators, a distinguished `honey.accessed` audit event, and (when the sibling `siem-audit-export` change is present) a SIEM event. The listener is **fail-soft**: it never blocks or slows the access it observes (same posture as the audit listener, `AuditService::record` `:56`).
- The alert MUST include the **accessor identity** (from the source event's actor), the **channel** (UI reveal, machine API, link share, or user share — derived from the source event type), and **IP / user-agent** where the request exposes them.
- Add **false-positive handling**: an owner or admin can **acknowledge** an alert and **snooze** future alerts **per accessor**; a snoozed accessor stops paging until the snooze elapses.
- Add **alert-storm rate limiting**: at most one alert per `(honey secret, accessor)` within a configurable window (default 1h), so a scripted attacker hammering the decoy does not bury the signal.
- **Visibility**: honey flags and alerts are visible ONLY to the secret's owner and vault administrators. Placement guidance (e.g. a decoy `prod-database` secret in a shared team folder) ships in docs.

## Capabilities

### New Capabilities
- `honey-credentials`: decoy secrets that act as tripwires — an owner/admin honey flag invisible to recipients, a central fail-soft listener that raises a high-severity alert (owner + admin notification, distinguished audit event, optional SIEM event) on any server-observable access across all channels, per-accessor acknowledge/snooze, and rate-limited alert storms.

### Modified Capabilities
- _(none — this change consumes the existing typed audit-event stream (`secret-audit-trail`) and notification path by reference and adds no MODIFIED requirement to their scenarios.)_

## Impact

- **New tables** (own DB per ADR-001): `doriath_honey_flags` (`secret_id` unique, `owner_id`, `note`, `created_by`, `created_at`) and `doriath_honey_alerts` (`honey_flag_id`, `secret_id`, `accessor_type`, `accessor_id` nullable, `channel`, `ip` nullable, `user_agent` nullable, `accessed_at`, `acknowledged_at` nullable, `acknowledged_by` nullable, `snoozed_until` nullable). No OpenRegister.
- **Services**: new `HoneyCredentialService` (flag/unflag, alert raise/dedup/ack/snooze); new `HoneyTripwireListener` on the typed `AuditEvent` bus (`lib/Event/Audit/AuditEvent.php` factories); extends `NotificationService` (new `honey_access` subject) and `DoriathNotifier`.
- **Routes/controllers**: new `HoneyController` — flag/unflag a secret (owner/admin), list alerts (owner: own; admin: instance-wide), acknowledge, snooze — all with explicit auth attributes and per-object guards.
- **Audit**: new event type `honey.accessed` added to `AuditEventTypes` + whitelist (no DB migration — string types); no secret value/PEM/ciphertext ever recorded.
- **Frontend**: honey toggle on the secret detail (owner/admin only); alerts panel (owner + admin) with acknowledge/snooze; admin dashboard high-severity alert count.
- **OpenConnector**: machine-API fetch of a honey secret trips the wire via the existing `application.secret_retrieved` event — no change to the machine API surface.
- **SIEM**: the optional SIEM event is class-existence-guarded on the sibling `siem-audit-export` change (not yet built) — same pattern the audit trail uses for the not-yet-built export events.
