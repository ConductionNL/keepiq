# ADR-001: Bitwarden browser extension is the reference design

**Status**: accepted

**Date**: 2026-09-22

## Context

The extension has to cover a large surface (accounts, vault browsing, item detail, generator, send, settings, autofill) and the product description leaves much of it open. Users already know how a password manager extension behaves, and Bitwarden is the de facto reference for open-source ones. Re-deciding every interaction from scratch costs time and produces something users have to relearn.

## Decision

Where a requirement is underspecified or leaves freedom, the extension does what the Bitwarden browser extension does: layout, labels, defaults, option ranges and interaction flow.

A deviation is allowed only when one of these forces it:

- Keepiq's API or crypto model cannot express the Bitwarden behaviour.
- Keepiq's own spec already defines the behaviour differently (its specs win for server-side behaviour).
- The behaviour is a documented Bitwarden mistake this project chooses not to repeat (see ADR-002 on the "never lock" default).

Every deviation is written down in the change's proposal under a "Deviations from Bitwarden" heading.

## Consequences

- Specs can say "as in Bitwarden" for well-known details instead of re-describing them, as long as the spec names the concrete default it adopts.
- Reviewers check a change against Bitwarden's behaviour, not against personal taste.
- Bitwarden ships features Keepiq does not have (organizations, collections, premium). Those are out of scope, not deviations.
