# Trash and archive

Deleting a secret in Keepiq no longer removes it at once. It goes to the trash, where you can bring it back until the retention period ends. Archiving puts a secret aside without deleting it.

## Deleting a secret

Choose **Delete secret** in the secret's detail panel, or select several secrets and choose **Delete**. The secrets move to the trash and every share ends at that moment: link shares, shares with people and groups, delegations and open requests. Nobody but you can read a secret in the trash.

The secret keeps its value, attachments and version history while it is in the trash.

## Restoring or deleting for good

Open **Trash** in the navigation. Select one or more secrets and choose:

- **Restore** to put them back in your vault. Their old shares do not come back; share them again where needed.
- **Delete for good** to remove them with their attachments and version history. This cannot be undone.

A secret that stays in the trash longer than the retention period is deleted for good by a daily job. The retention is 30 days unless your administrator changed it.

## Archiving a secret

Choose **Archive** in the secret's detail panel, or select secrets and choose **Archive**. An archived secret leaves the vault list, search, Nextcloud search, the browser extension's suggestions and the password health report. It keeps its shares, and people you shared it with see no change.

Open **Archive** in the navigation to find archived secrets. Choose **Unarchive** in the detail panel or on a selection to bring them back. An export includes archived secrets but never secrets in the trash.

## For administrators

The retention is set under **Administration settings, Keepiq, Attachments and version history**: days a deleted secret stays in the trash, from 1 to 365, 30 by default.
