<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Rotation and sync runner

Let a database password change itself every week, and keep your cloud secret stores in step with Keepiq.
The Keepiq server cannot do either, because it never sees a value.
`keepiq-runner` can: it acts as one Keepiq application and decrypts with that application's own key, on your machine.

## Set it up

1. Register a Keepiq application for the runner, for example `ops-runner`, and keep its private key on the runner host.
2. File the secrets it rotates or syncs in that application's vault, together with the credentials it needs: the database admin login, the AWS keys, the GitHub token.
3. Write `runner.yaml`. Start from `integrations/runner/runner.example.yaml`; it names secrets, never values.
4. Check it with `keepiq-runner check --config runner.yaml`, then start `keepiq-runner run`.

Give each runner its own application, holding only the secrets that runner works on.
Whoever holds the runner's key can read every secret in that vault.

Run it as a service, or once from cron or a Kubernetes CronJob with `keepiq-runner run --once`.
The image is `ghcr.io/conductionnl/keepiq-runner`.

## Rotation

A rotation never stores a password that does not work. For each rotation the runner:

1. reads the current password and its version from Keepiq;
2. generates a new one;
3. notes both in its journal, encrypted to the application's key;
4. sets the new password at the database as the admin;
5. logs in with the new password;
6. stores it in Keepiq, but only if nobody changed the secret in the meantime.

If the login fails, the runner sets the old password back and Keepiq keeps the old value.
If someone changed the secret in Keepiq during the rotation, the runner sets the old password back and logs a conflict.
If the runner stops between steps 4 and 6, it finishes the rotation from the journal at its next start.

A rotation runs on its cron `schedule`, and with `followExpiry` also when the secret's expiry date is within `leadTime` (default 7 days).
The rotation does not move the expiry date; set a new one in Keepiq when your policy needs it.

| Connector | What it changes | Target options |
|---|---|---|
| `postgres` | `ALTER ROLE … PASSWORD` | `host`, `database`, `sslmode`, `user` |
| `mysql` | `ALTER USER … IDENTIFIED BY` | `host`, `database`, `userHost`, `tls`, `user` |
| `exec` | anything your command does | `command` |

The rotated secret's `login` is the account to rotate, unless `target.user` names another one.
The `adminSecret` holds the admin account in `login` and its password in `key`.

The `exec` command gets one JSON object on stdin, `{"action":"set","user":…,"current":…,"new":…}` or `{"action":"login","user":…,"password":…}`, and answers with its exit code.
The runner never reads its output, so a hook cannot leak a value into the log.

## Sync

Every `interval` (default 60 seconds) the runner asks Keepiq what changed and pushes changed secrets of each sync set.
An unchanged secret is never pushed again. A failed push is retried later and never holds up the others.

| Destination | Options | Credentials secret |
|---|---|---|
| `aws-secrets-manager` | `region`, `prefix` | `login` access key id, `key` secret key; or leave it out for the default AWS chain |
| `azure-key-vault` | `vaultUrl`, `prefix` | `login` client id, `key` client secret, `additionalFields.tenantId`; or leave it out for the managed identity |
| `github-actions` | `repository` (with `environment`) or `organization` | `key` a token that may write Actions secrets |
| `exec` | `command` | none; the command gets `{"name":…,"value":…}` on stdin |

GitHub gets every value sealed with the repository's public key, as GitHub requires.
A destination keeps its own access rules; the runner copies only the secrets you name in a sync set.
When you delete a secret in Keepiq, the runner does not delete it at the destination. Remove it there yourself.

## What stays secret

The runner sends Keepiq ciphertext only, and the private key never leaves the host.
Its log and its state directory hold names, ids and versions, never a value.
Every rotation shows in the Keepiq audit trail as an update by the application, and version history keeps the previous value.

## Next step

Register the `ops-runner` application, copy `runner.example.yaml`, and run `keepiq-runner check`.
