---
kind: code
---

# Proposal: adopt-connection-registry

## Why

Keepiq talks to two outside systems. Today an admin can only tell whether they work by reading two settings sections and the server log.

- **Have I Been Pwned.** When an admin switches on breach checking, users can check their passwords. Keepiq sends a 5-character SHA-1 hash prefix to `api.pwnedpasswords.com`. A failing upstream shows only as a warning in the log.
- **SIEM audit export.** Keepiq forwards whitelisted audit events to syslog or webhook sinks. A background job drains the queue every minute. There can be many sinks, and each one is a record in Keepiq's own table.

Hydra change `connection-registry` (hydra#667, amended in hydra#673, hydra#674 and hydra#676) gives every app one page of its connections, backed by integriq.

## What changes

- New `lib/Settings/connections.json` with two connections: `hibp` and `siem`.
- `hibp` requires `breach_check_enabled`. The key is a boolean, and integriq reads a stored `false` as empty (amendment 6), so a switched-off check reads Not configured.
- `siem` is `reportedOnly`. The sinks are records, not settings, so a static file cannot list them. One row speaks for the whole family (D12, "Still out").
- The Breach checking and SIEM audit export sections get stable ids: `section-breach-check` and `section-siem`.
- Saving `breach_check_enabled` sends `ConnectionRefreshRequestedEvent` for `hibp`.
- Creating, changing or deleting a sink sends a refresh for `siem`, then reports Not configured when no sink is switched on.
- A range lookup that reaches Have I Been Pwned reports its outcome. A SIEM drain that delivered to at least one sink reports the outcome over those sinks. Both are throttled: the same status at most once an hour, a new status at most once every five minutes.
- A report names a status code or a host. It never carries a hash prefix, a password, a sink URL path, a token or an exception message.
- An Integrations page under the settings gear, over integriq's `app_connection` schema, preset to `app=keepiq`, admin only, and only shown when integriq is installed.
- Keepiq's own navigation rail learns to honour a menu entry's `query`, `permission: admin` and `visibleIf.appInstalled`. It ignored all three before, so the preset would not have reached the page.
- Add integration opens `/apps/integriq/connections?app=keepiq&link=1`.
- Local `connectionStatus` and `connectionSettingsLabel` formatters with all six statuses, and the strings in English and Dutch.

## Depends on

- hydra `openspec/changes/connection-registry`, design D2, D3, D4, D6, D8, D9 and D12 amendments 1 to 7.
- integriq on `development`: the `app_connection` schema, the declaration sync, both events and the Connections overview.

Without integriq the menu entry is hidden, a deep link shows the missing-dependency screen, and nothing is sent.

## Out of scope

- The SIEM test-fire button. It tests one sink, and the row speaks for all of them. Its result already shows in the SIEM section.
- The CA certificate renewal and the browser extension relay. Both stay inside the instance.
- Exempting the Integrations page from the vault lock. Every routed Keepiq page sits behind the master password, and this change keeps that rule.

## Rollback

Revert the change. Keepiq writes no rows of its own. Integriq removes the rows without a linked source on its next sync. The two `connection_report_*` app-config keys can stay: nothing else reads them.
