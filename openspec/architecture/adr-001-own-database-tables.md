# ADR-001: Own Database Tables (No OpenRegister)

**Status**: accepted

**Date**: 2026-03-23

## Context

Org-wide ADR-001 mandates that Conduction apps use OpenRegister as their data layer (JSON object storage with schema validation). This keeps data architecture consistent across apps and avoids each app managing its own migrations.

Doriath is a secrets manager that stores highly sensitive encrypted data: private keys (AES-encrypted), encrypted secret values, and Certificate Authority certificates. The security model depends on:

- Strict schema control — encrypted fields must never be accidentally exposed or logged as plain text
- Fine-grained DB-level isolation — secrets must be queryable by owner and encryption suite without exposing their contents to the query layer
- No intermediary services in the data path — every hop between Doriath and the database is a potential point of failure or leakage

OpenRegister is a generic JSON object store. It introduces an external service in the data path and provides no mechanism for field-level encryption guarantees. Storing encrypted blobs in OpenRegister's generic object model would mix encrypted and non-encrypted data in a shared store, complicating auditing and access control.

## Decision

Doriath manages its own Doctrine entities and Nextcloud database migrations. It does **not** depend on OpenRegister for any data storage.

This is an explicit app-specific exception to org-wide ADR-001.

Doriath also does **not** use n8n for any internal workflow or automation — all business logic runs directly in PHP.

## Consequences

**Positive:**
- Full control over schema — encrypted fields are typed and documented at the DB level
- No external service dependency for core functionality — Doriath works standalone
- Simpler audit trail — the only path to the data is through Doriath's own controllers and services
- Migrations use Nextcloud's ISchemaWrapper, keeping the app upgrade path clean

**Negative / trade-offs:**
- Doriath must maintain its own DB migrations — more maintenance than OpenRegister-managed objects
- No benefit from OpenRegister's search, filtering, or schema validation infrastructure
- The thin-client pattern used by other Conduction apps does not apply here — Doriath requires a proper backend service layer

## Alternatives Considered

| Option | Reason not chosen |
|--------|------------------|
| OpenRegister JSON object store | Introduces external service in data path; no field-level encryption guarantees; shared object store complicates access control auditing |
| Nextcloud's UserPreferences / AppConfig | Not suited for structured relational data or large encrypted blobs |
| External secrets backend (e.g., HashiCorp Vault) | Breaks Nextcloud-native design; introduces dependency outside the Nextcloud instance |
