## REMOVED Requirements

### Requirement: Offline mode is strictly read-only

**Reason**: Superseded by the `offline-edit-queue` capability. With offline edits disabled (the default) the offline view stays read-only exactly as this requirement said; with them enabled, secret edits are queued and replayed with a fresh fan-out.

**Migration**: The read-only behaviour for sharing, link shares, sends, team-folder membership, folders and attachments moves to the requirement "Sharing and membership actions stay online-only" in `offline-edit-queue`; the read-only behaviour for secret edits when the setting is off moves to "Offline changes go into a sealed local queue".
