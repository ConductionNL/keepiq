<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# CI integrations

Give a pipeline step its Keepiq secrets in one step, without a secret value in your pipeline configuration.
The pipeline holds only your application's private key. Every value is decrypted on the runner.

Both integrations install the `keepiq` command-line client from a release.
They check the download against the release's `SHA256SUMS` file and refuse a binary that does not match.

## GitHub Actions

Store the application's private key as the repository secret `KEEPIQ_APP_KEY`. Then:

```yaml
- uses: ConductionNL/keepiq/integrations/github-action@cli-v0.3.0
  with:
    url: https://cloud.example.org
    application-id: deploy-bot
    private-key: ${{ secrets.KEEPIQ_APP_KEY }}
    secrets: DB_PASSWORD
    run: ./deploy.sh
```

`./deploy.sh` finds the value in `KEEPIQ_DB_PASSWORD`. Nothing is written to disk.

List one secret name per line. Write `DB_PASSWORD=PGPASSWORD` to choose the variable name yourself.

### Exporting to later steps

Set `export-env: "true"` instead of `run` when later steps need the values:

```yaml
- uses: ConductionNL/keepiq/integrations/github-action@cli-v0.3.0
  with:
    url: https://cloud.example.org
    application-id: deploy-bot
    private-key: ${{ secrets.KEEPIQ_APP_KEY }}
    secrets: API_TOKEN
    export-env: "true"
- run: ./publish.sh   # sees KEEPIQ_API_TOKEN
```

Every line of every value is masked in the log first.
Export does write the values to the runner's environment file, so use `run` when one step is enough.

A step with neither `run` nor `export-env` fails and tells you to set one of them.

When you use the action at a branch instead of a `cli-v` tag, also set `version: cli-v0.3.0`.

## GitLab CI

Include the template at a release tag and extend `.keepiq`:

```yaml
include:
  - remote: https://raw.githubusercontent.com/ConductionNL/keepiq/cli-v0.3.0/integrations/gitlab-ci/keepiq.gitlab-ci.yml

migrate:
  extends: .keepiq
  variables:
    KEEPIQ_CLI_VERSION: cli-v0.3.0
  script:
    - keepiq ci run DB_PASSWORD -- ./migrate.sh
```

Set `KEEPIQ_URL`, `KEEPIQ_APP_ID` and `KEEPIQ_APP_KEY` as protected, masked CI/CD variables.
A variable of type File works too: name it `KEEPIQ_APP_KEY_FILE`.

`./migrate.sh` finds the value in `KEEPIQ_DB_PASSWORD`.
GitLab cannot mask a value fetched during the job, so the template only offers this wrapped form.

The job image needs a shell, `curl` or `wget`, and `sha256sum`.
When the job has its own `before_script`, start it with `- !reference [.keepiq, before_script]`.

## The container image

Each CLI release is also an image, `ghcr.io/conductionnl/keepiq-cli`, with the same version:

```sh
docker run --rm -e KEEPIQ_URL -e KEEPIQ_APP_ID -e KEEPIQ_APP_KEY ghcr.io/conductionnl/keepiq-cli:0.3.0 ci fetch DB_PASSWORD --output json
```

## Next step

Register the application your pipeline will use, then add its key to your CI secrets.
See [OpenConnector integration](./integration-openconnector.md) for how registration and approval work.
