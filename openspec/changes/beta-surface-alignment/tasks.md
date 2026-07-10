## 1. Code metadata

- [x] 1.1 Fix `appinfo/info.xml` `<licence>` from `agpl` to `EUPL-1.2` (matches PHP docblock `@license` tags and existing description text)
- [x] 1.2 Expand EN/NL `<description>` bullets to name the verified shipped feature set (personal vault + unified search, sharing incl. delegation, application tokens) without adding an audit-log or team-vault claim
- [x] 1.3 Confirm no hard `<dependency>` app entry is warranted for OpenRegister (verified optional/soft via `isOpenRegisterAvailable()`)
- [x] 1.4 Confirm `img/app.svg` (24x24, white fill, lock glyph) matches the product-page icon — no change needed

## 2. Product page

- [x] 2.1 Rewrite `conduction-website/src/pages/apps/doriath.mdx`: status Beta, version v0.2.10, FeatureList of code-verified features
- [x] 2.2 Rewrite `conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/doriath.mdx` to match (real Dutch, not a translation-shaped English copy)
- [x] 2.3 Scope the "Pairs well with" claim to the verified OpenRegister credential-broker leaf relationship only; drop the unverified OpenConnector pairing

## 3. Docs

- [x] 3.1 Update `docs/intro.md` status line to public beta + note the own-database architecture and the OpenRegister credential-broker leaf role
- [x] 3.2 Fix `docs/tutorials/user/01-first-launch.md`: remove the false hard-OpenRegister-dependency and NC-server-side-encryption prerequisites; correct the verification/common-issues sections to match the real lock-screen/master-password flow
- [x] 3.3 Fix `docs/tutorials/admin/01-admin-settings.md`: remove the false "audit log" verification step and "team vaults" default-policy language; correct prerequisites and common issues to the real CA-health-card flow

## 4. Verification

- [x] 4.1 Every claim added to the product page and docs traced to a specific `lib/Controller`, `lib/Service`, or `src/*.vue` file
- [x] 4.2 Confirmed emergency access is NOT claimed anywhere (unimplemented `openspec/changes/emergency-access/` proposal, not on `development` HEAD)
- [x] 4.3 Confirmed a general secret audit trail is NOT claimed anywhere (unimplemented `openspec/changes/add-secret-audit-trail/` proposal)
