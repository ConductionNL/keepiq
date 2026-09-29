## MODIFIED Requirements

### Requirement: EncryptionSuite Compromise — Shared Copy Migration and Owner Notification
When a recipient's EncryptionSuite is **replaced due to compromise**, the suite migration process (see encryption-suites spec) MUST cover all `Secret` rows encrypted with the old suite — including shared copies held by the recipient. Those copies MUST be re-encrypted with the new suite and flagged `possibly_compromised_at` as part of the standard migration.

The additional responsibility of User Sharing is: when a shared copy is flagged `possibly_compromised_at` during migration, the **original owner of the secret MUST be notified** that the secret may have been compromised and its value should be replaced. The notification MUST point at the SOURCE secret, which the owner can open, not at the recipient's copy.

The SOURCE secret MUST itself be stamped `possibly_compromised_at` (when not already stamped) and carry a `suite_compromise` rotation flag. It is not sealed under the recipient's suite, so nothing else in the migration marks it, and without the stamp it does not appear in the owner's rotation and compliance views. The same holds when the recipient's suite is force-revoked as compromised (see encryption-suites: Administrator Force-Revocation).

A shared copy whose source no longer exists falls back to the copy and its holder. Any other failure to resolve the source MUST be logged, and a failure to mark one secret MUST NOT stop the cascade for the others.

When the owner replaces the secret value, sync-on-update (see Requirement: Sync on Update) propagates the new value to all copies, including the migrated copy in the recipient's new suite. Updating the value MUST unset `possibly_compromised_at` on all copies.

#### Scenario: Shared copy flagged during migration
- GIVEN user B holds a shared copy of a secret owned by A
- WHEN B's EncryptionSuite is replaced due to compromise and the copy is migrated
- THEN the copy MUST be flagged `possibly_compromised_at` (per encryption-suites migration)
- AND A MUST receive a Nextcloud notification: "A secret you shared may have been compromised — please replace its value", pointing at A's source secret
- AND A's source secret MUST be stamped `possibly_compromised_at` and flagged for rotation

#### Scenario: Shared copy on a force-revoked compromised suite
- GIVEN user B holds a shared copy of a secret owned by A
- WHEN an administrator force-revokes B's EncryptionSuite with `markCompromised: true`
- THEN both the copy and A's source secret MUST be stamped `possibly_compromised_at` and flagged for rotation
- AND A MUST be notified about the source secret

#### Scenario: Source secret is gone
- GIVEN a compromised shared copy whose source secret has been deleted
- WHEN the compromise cascade runs
- THEN the copy's holder MUST be notified about the copy
- AND nothing is logged as an error

#### Scenario: Owner replaces possibly-compromised secret value
- GIVEN A's secret (and its shared copies) is flagged `possibly_compromised_at`
- WHEN A updates the secret value
- THEN sync-on-update MUST propagate the new value to all copies
- AND `possibly_compromised_at` MUST be unset on the original and all copies
