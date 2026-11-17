---
kind: code
---

# More website addresses per login, a site preview and a change-password link

## Why

A Keepiq login carries one website address, the plain-text `url` column (`lib/Db/Secret.php:87`). The same account often signs in on several addresses (`login.example.com`, `example.com`, a mobile subdomain, a second brand), and the browser extension matches only on that one address (`browser-extension/src/lib/match.js:76-104`, `lib/Controller/ExtensionController.php:180`). The address is also the only link from a login to the site, and Keepiq does nothing more with it than draw a favicon, and only when an administrator has set `favicon_service_url` with `occ` (`lib/Controller/DashboardController.php:101-102`, `src/utils/favicon.js`); there is no admin screen for it. When a password is weak or breached, the user has to find the site's change-password page by hand, although sites publish it at `/.well-known/change-password` (W3C "A Well-Known URL for Changing Passwords").

The three rows share one field, the login's website address, and one set of screens: the create and edit dialogs, the detail sidebar and the extension match.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `vault-28` | Link several website addresses to one login. | `no`: a secret carries one `url`; the extension matches on that one |
| `vault-31` | See each login with the website's icon and a screenshot preview of the site. | `partial`: site icons show when an admin configures a favicon service; no preview |
| `rotation-08` | Open the website's own change-password page straight from a login, found through its well-known address. | `no`: Keepiq stores the address but never opens the site's change-password page |

For `vault-31` the missing half is a preview image of the site; site icons are built.

### Demand

- `vault-28`: feature request, https://community.passbolt.com/t/as-a-user-i-want-to-associate-multiple-uris-with-a-password/4388
- `rotation-08`: feature request, https://community.bitwarden.com/t/implementing-the-change-password-url-spec/3367
- `vault-31`: changelog, https://github.com/marius-wieschollek/passwords/blob/2026.7.0/CHANGELOG.md
- `vault-28` and `vault-31` are in the core area (vault).

### Competitors rated yes

- `vault-28`, Bitwarden: "libs/vault/src/cipher-form/components/autofill-options/autofill-options.component.ts:74 uris formArray ... A login carries a list of URIs, each with its own match detection, added from the cipher form."
- `vault-28`, 1Password: "If you only have one website field in an item, learn how to add more"; each website field has its own autofill behaviour (https://support.1password.com/autofill-behavior/).
- `vault-28`, Passbolt: "DisplayResourceDetailsURIs.js:68 main URI metadata.uris[0], :75 additionalUris Note: v5 resources hold a list of URIs, shown as a main address plus additional ones."
- `vault-28`, Keeper: "store multiple website domains in one record through additional URL fields; KeeperFill matches every one" (https://docs.keeper.io/user-guides/tips-and-tricks/one-record-with-multiple-url-domains).
- `vault-31`, Nextcloud Passwords: "src/appinfo/routes.php service/favicon/{domain}/{size} and service/preview/{domain}/{view}; src/vue/Components/Sidebar/PasswordSidebar/Preview.vue:2; src/lib/Provider/Favicon, src/lib/Provider/Preview."
- `rotation-08`, Bitwarden: "src/Icons/Controllers/ChangePasswordUriController.cs:9 change-password-uri resolves a site's well-known address; ... notification.background.ts:572 hasPasswordChangeUri offers the change page."
- `rotation-08`, Nextcloud Passwords: "src/lib/Services/PasswordChangeUrlService.php:61 getPasswordChangeUrl() probes /.well-known/change-password at :121; src/appinfo/routes.php:106 POST /api/1.0/service/password-change; ... PasswordItemActionMenu.vue:99 openChangePasswordPage()."

## What Changes

- **More addresses.** A login gets a list of extra website addresses next to its main address, edited in the create and edit dialogs. They are stored in plain text like the main address, so search, unified search and the extension match all see them.
- **Site preview.** An administrator can switch on site images in a new admin section: the favicon service (the existing `favicon_service_url`, now with a screen) and a preview service URL template. With a preview service set, the detail sidebar shows a preview image of the login's site. Both are off by default, and the section says which addresses leave the instance when they are on.
- **Change password.** Each login with an address gets a Change password action that opens `https://<host>/.well-known/change-password` in a new tab. The password health report offers the same action on weak, reused and breached findings.

## Capabilities

### New Capabilities

- `website-addresses`: several plain-text addresses per login used for search and autofill, opt-in site icons and previews, and a change-password link through the well-known address.

### Modified Capabilities

- None in delta form. The `secrets` data model keeps `url` as the main address; this change's own requirements add the list.

## Impact

- **Backend**: a `keepiq_secret_urls` table (`secret_id`, `owner_id`, `url`, `position`); `SecretService::create()` and `update()` accept `additionalUrls`; `SecretMapper::searchByNameOrUrl()` and `findForUnifiedSearch()` search the table too; share copies carry the list; `site_preview_service_url` in `AdminSettingsService`.
- **Frontend**: the address list in `SecretCreateDialog.vue` and `SecretEditDialog.vue`, the list and the preview in `SecretDetailSidebar.vue`, a Change password action there and in `HealthCategory.vue`, a new admin section `SiteImagesSection.vue`.
- **Browser extension**: `match.js` scores every address of a candidate and the match endpoint returns the list.
- **Database**: one migration; `<version>` bump.
- **Security**: extra addresses are plain text, as the main address is by design (`openspec/specs/secrets/spec.md:566`). Site previews and icons send the site's host to the configured service, only when an administrator switches them on.
- **Cross-app**: none.
