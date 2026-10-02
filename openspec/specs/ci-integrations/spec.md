# Ci integrations Specification

**Status**: done

**OpenSpec changes:**
- [apps-client-libraries-and-ci](../../changes/archive/2026-10-02-apps-client-libraries-and-ci/) _(archived 2026-10-02)_

## Purpose
A GitHub Action and a GitLab CI template that bring Keepiq application secrets into a pipeline step without a secret value in the pipeline configuration, plus a verifiable, containerised CLI release. Parity row apps-18.

## Requirements

### Requirement: GitHub Action runs a step with Keepiq secrets

The project MUST ship a composite GitHub Action at `integrations/github-action/`, usable as `ConductionNL/keepiq/integrations/github-action@<tag>`. It MUST take the instance URL, application id, application private key and a list of secret names. It MUST install the CLI for the runner from the matching release and MUST refuse a binary whose checksum does not match the release checksums. With the `run` input it MUST execute that command through `keepiq ci run`, so values exist only in that command's environment and nothing is written to disk.

#### Scenario: Deploy step gets a database password

- **GIVEN** a workflow with the application private key in GitHub secret `KEEPIQ_APP_KEY`
- **WHEN** a step uses the action with `secrets: DB_PASSWORD` and `run: ./deploy.sh`
- **THEN** `./deploy.sh` MUST see `KEEPIQ_DB_PASSWORD` in its environment
- **AND** no file on the runner MUST contain the value

### Requirement: GitHub Action exports only on request and masks every value

The action MUST NOT export values to later steps unless `export-env` is `true`. When it exports, it MUST register every value with `::add-mask::` (every line of a multi-line value) before writing it to `$GITHUB_ENV`. Without `run` and without `export-env`, the step MUST fail and name both options.

#### Scenario: Exported value is masked in logs

- **GIVEN** a step using the action with `secrets: API_TOKEN` and `export-env: true`
- **WHEN** a later step echoes `$KEEPIQ_API_TOKEN`
- **THEN** the workflow log MUST show the value masked

### Requirement: GitLab CI template wraps a job command

The project MUST ship `integrations/gitlab-ci/keepiq.gitlab-ci.yml` defining a hidden job `.keepiq` that installs the checksum-verified CLI. A job extending it MUST be able to run its command through `keepiq ci run`, so values exist only in that command's environment.

#### Scenario: GitLab job runs a migration with a secret

- **GIVEN** a `.gitlab-ci.yml` that includes the template by URL and a job `migrate` with `extends: .keepiq`
- **WHEN** the job runs `keepiq ci run DB_PASSWORD` with `./migrate.sh` as the wrapped command
- **THEN** `./migrate.sh` MUST see `KEEPIQ_DB_PASSWORD` in its environment

### Requirement: The CLI release is verifiable and containerised

Each `cli-v*` release MUST include a `SHA256SUMS` file for every binary and MUST publish the container image `ghcr.io/conductionnl/keepiq-cli` with the same version.

#### Scenario: Pipeline verifies the downloaded CLI

- **GIVEN** release `cli-v0.2.0`
- **WHEN** a pipeline downloads `keepiq-linux-amd64` and `SHA256SUMS` from it
- **THEN** the binary's SHA-256 MUST match its line in `SHA256SUMS`
