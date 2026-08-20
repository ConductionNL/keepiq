## Why

Doriath's four public surfaces — `appinfo/info.xml`, `src/manifest.json`/`manifest.d/`, the `conduction.nl/apps/doriath` product page, and the `doriath.conduction.nl` docs site — had drifted out of agreement ahead of beta release:

- `info.xml` declared `<licence>agpl</licence>` while every PHP file's own docblock (`@license EUPL-1.2`), the app description's own prose ("Free and open source under the EUPL-1.2 license"), and `docs/intro.md` all say EUPL-1.2. AGPL vs EUPL-1.2 is a real license mismatch, not cosmetic — it is what an app-store reviewer and every downstream integrator reads as ground truth.
- The product page (`src/pages/apps/doriath.mdx` + the `nl` i18n copy) described Doriath as "in design and implementation" with a generic "in development" status, when the shipped code (25 controllers, 20 services, 49 Vue components, full manifest with Dashboard/Vault/Applications/Documentation/Roadmap/Lock/Settings pages) is functionally complete for its MVP tier and closer to a beta than a design-stage app.
- The docs site's two tutorial stubs (`docs/tutorials/user/01-first-launch.md`, `docs/tutorials/admin/01-admin-settings.md`) asserted facts that are false on HEAD: "Doriath stores its vault data through OpenRegister, so it is a hard dependency" (false — `docs/ARCHITECTURE.md` itself documents Doriath owns all its own DB tables as an explicit ADR-001 exception, and `InitializeSettings::run()` checks `isOpenRegisterAvailable()` and skips gracefully if absent), reliance on "Nextcloud's own server-side encryption" (false — Doriath's `EncryptionSuiteService`/`CertificateAuthorityService`/`EncryptService`/`DecryptService` have no dependency on NC's server-side encryption app), an "audit log" that gets written on every operation (false — no `AuditLog` entity/table exists; `openspec/changes/add-secret-audit-trail/` is an *unimplemented* proposal), and "default sharing policy for new team vaults" (false — there is no team-vault entity, only per-user vaults plus group-fan-out sharing).

This change reconciles all four surfaces against a single, code-verified feature vocabulary and removes every claim that could not be traced to `lib/` or `src/`.

## What Changes

- **`appinfo/info.xml`**: `<licence>agpl</licence>` → `<licence>EUPL-1.2</licence>` (matches every PHP docblock's `@license` tag and the description's own license prose). EN/NL descriptions expanded to name the shipped feature set precisely: personal vault + folders + unified search, sharing (user/group/link/request/delegation), application management (CSR onboarding, write-without-read, RFC 7523 JWT-Bearer application tokens), CA lifecycle, admin settings.
- **`src/manifest.json`**: no change — the nav/page vocabulary (`Vault`, `Dashboard`, `Applications`, `Features & roadmap`, `Documentation`, `Lock vault`, `Settings`) already matches shipped routes/components 1:1 and was used as the source of truth for the other three surfaces.
- **Product page** (`conduction-website/src/pages/apps/doriath.mdx` + `i18n/nl/.../doriath.mdx`): rewritten from a generic "in development" placeholder to a feature-complete Beta positioning (status Beta, version v0.2.10) using the FeatureList pattern already established for other beta apps (e.g. `shillinq.mdx`). Every bullet is traceable to a specific controller/service. The one cross-app "Pairs well with" claim (OpenRegister) is scoped to what is actually verified: Doriath is the storage leaf `OCA\OpenRegister\Service\Credential\DoriathCredentialStore` calls into for application-owned credential custody — not a generic "integration," and not extended to OpenConnector, which has no code reference to any Doriath credential class.
- **Docs** (`docs/intro.md`, `docs/tutorials/user/01-first-launch.md`, `docs/tutorials/admin/01-admin-settings.md`): status line changed from "in development" to "public beta, not yet on the app store"; removed the false hard-OpenRegister-dependency claim, the false NC-server-side-encryption prerequisite, the false "audit log" verification step, and the false "team vaults" default-policy language. Replaced with the real prerequisite (none beyond the app being enabled) and the real verification steps (lock screen / master password / CA health card).
- **Explicitly not claimed anywhere**: emergency access (tracked as the unimplemented `openspec/changes/emergency-access/` proposal — a worktree branch, not on `development` HEAD) and a general secret audit trail (tracked as the unimplemented `openspec/changes/add-secret-audit-trail/` proposal). Both risk being conflated with "audited access" language a generic beta-alignment pass might otherwise introduce; neither is asserted on any of the four surfaces after this change.

## Canonical feature vocabulary (used identically across all 4 surfaces)

1. Zero-knowledge end-to-end encryption — RSA-4096 + AES-256, master password never stored
2. Private Certificate Authority — root + intermediate, automatic renewal, forced renewal, admin health monitoring
3. Personal vault — per-user folder tree, fuzzy search, copy-to-clipboard, Nextcloud unified search integration
4. Secret sharing — user-to-user (sync-on-update), group (fan-out + approval + auto-revoke on leave), password-protected link sharing (Argon2id, usage-limit auto-delete), secret requests (write-without-read fill-in links), temporary ownership delegation + reclaim
5. Application management — CSR-based or generated-keypair onboarding, admin approval queue, write-without-read application secrets, RFC 7523 JWT-Bearer application tokens
6. Admin settings — CA health, master-password policy, application approval queue
7. Dashboard — vault summary, CA health card, pending-applications counter, recent activity
8. Credential-custody leaf for OpenRegister's credential broker (verified via `DoriathCredentialStore`)

## Impact

- Affected files: `appinfo/info.xml`; `conduction-website/src/pages/apps/doriath.mdx`; `conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/doriath.mdx`; `docs/intro.md`; `docs/tutorials/user/01-first-launch.md`; `docs/tutorials/admin/01-admin-settings.md`.
- No code behavior changes — metadata/documentation only.
- No new dependency added to `info.xml` — OpenRegister integration (unified-search deep links) is verified optional/soft (`isOpenRegisterAvailable()` graceful skip), so it is intentionally NOT declared as a hard `<dependency>` app entry.
