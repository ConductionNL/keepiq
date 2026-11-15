<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Website addresses, site images and Change password

## More than one address per login

A login has its main **URL** and up to **20 more website addresses**. Add them under
**More website addresses** when you create or edit the login, one per row.

The login is then found and suggested on every one of them:

- **Search** in the vault and in Nextcloud's unified search match any address.
- **The browser extension** offers the login on a page when any of its addresses
  matches that page, under the same match rule you chose for the main address.
  The extension never fills a login without your choice, so more addresses make a
  longer menu, not a silent fill.

Each address is trimmed, a repeat (ignoring case) is dropped, and one may be at most
512 characters long.

**Website addresses are not encrypted**, just like the main URL. That is what lets the
server search them and the extension match them without your master password. Server
administrators can read them, as they can folder names and tags. Keep anything secret
in the login's value or its additional fields instead.

When you share a login, the people you share it with get its addresses too, and they
follow your changes. Only you, as the owner, change them.

The addresses travel with the login in a vault backup and a restore, in the GDPR
export, and they are removed with the login and with your account.

## Site icons and previews

An administrator can show a small icon of the site in the vault list and a preview
image of the site in the detail sidebar. Both are **off** until an administrator sets a
service under **Administration settings → Keepiq → General → Site images**:

| Setting | Placeholder | What the service receives |
| --- | --- | --- |
| Site icon service | `{domain}` | the host name of each login shown, such as `example.com` |
| Site preview service | `{url}` | the full main address of each login opened |

A service must be an `https://` address that contains its placeholder, for example
`https://preview.example.com/shot?url={url}`. Leave a setting empty to switch it off.

**Privacy:** the browser of every vault user loads these images straight from the
service you configure, so that service learns which sites your users have logins for.
Images load without a referrer, but the host name or address itself is the request.
With both settings empty, no such request is made.

## Change password

A login with an address shows **Change password** in its detail sidebar, and the
password health report shows it on weak, reused and breached findings. It opens
`https://<host>/.well-known/change-password` for the login's main address in a new tab.

A site that supports this well-known address sends you on to its own change-password
page; one that does not shows its not-found page. Keepiq makes no request to the site
before you click, so it does not check in advance which sites support it.
