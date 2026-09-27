# Tasks: more website addresses per login, a site preview and a change-password link

## 1. Addresses

- [ ] 1.1 Add the migration and a `SecretUrl` entity and mapper; accept `additionalUrls` (at most 20) on create and update in `SecretController` and `SecretService`; bump `<version>`. Verify: PHPUnit for store, replace and the cap.
- [ ] 1.2 Search the table in `searchByNameOrUrl()` and `findForUnifiedSearch()`, and return the list in the match endpoint. Verify: PHPUnit that a login is found and matched by an extra address.
- [ ] 1.3 Copy the list onto share, group-share and team-folder copies at creation and on the owner's change. Verify: PHPUnit that a recipient copy gets the owner's new list.
- [ ] 1.4 Add the address list to the create and edit dialogs and the detail sidebar; include it in backup export, restore, the GDPR export and account deletion. Verify: Playwright flow add an extra address, search for it, the login is found; vitest on the serializer round trip.
- [ ] 1.5 Score every address in `browser-extension/src/lib/match.js`. Verify: extension unit test that a login matches on its second address.

## 2. Site images

- [ ] 2.1 Add `site_preview_service_url` to `AdminSettingsService` and a `SiteImagesSection.vue` admin section for both templates with the privacy text. Verify: PHPUnit on validation (https only, placeholder present), and a Playwright check of the section.
- [ ] 2.2 Show the preview image in the detail sidebar when the template is set, with no-referrer. Verify: vitest that nothing is requested when the template is empty.

## 3. Change password

- [ ] 3.1 Add Change password to the detail sidebar for logins with an address and to weak, reused and breached findings in `HealthCategory.vue`, opening the host's well-known address in a new tab. Verify: vitest on the URL built from the address, and a Playwright check that the link target is `/.well-known/change-password` on the host.

## 4. Docs

- [ ] 4.1 Document extra addresses, site images with their privacy note, and the change-password link. Verify: docs build.

## Acceptance criteria

- A login with extra addresses is found by search and suggested by the extension on each of them.
- Site previews appear only after an administrator sets a preview service, and the admin section says what leaves the instance.
- Change password opens the site's well-known change-password address.
