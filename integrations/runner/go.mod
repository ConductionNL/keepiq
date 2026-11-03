module github.com/ConductionNL/keepiq/integrations/runner

go 1.25.0

require (
	github.com/ConductionNL/keepiq/sdk/go v0.0.0
	github.com/aws/aws-sdk-go-v2 v1.30.5
	github.com/aws/aws-sdk-go-v2/config v1.27.33
	github.com/aws/aws-sdk-go-v2/credentials v1.17.32
	github.com/aws/aws-sdk-go-v2/service/secretsmanager v1.32.8
	github.com/go-sql-driver/mysql v1.8.1
	github.com/jackc/pgx/v5 v5.9.2
	github.com/robfig/cron/v3 v3.0.1
	golang.org/x/crypto v0.52.0
	gopkg.in/yaml.v3 v3.0.1
)

require (
	filippo.io/edwards25519 v1.1.1 // indirect
	github.com/aws/aws-sdk-go-v2/feature/ec2/imds v1.16.13 // indirect
	github.com/aws/aws-sdk-go-v2/internal/configsources v1.3.17 // indirect
	github.com/aws/aws-sdk-go-v2/internal/endpoints/v2 v2.6.17 // indirect
	github.com/aws/aws-sdk-go-v2/internal/ini v1.8.1 // indirect
	github.com/aws/aws-sdk-go-v2/service/internal/accept-encoding v1.11.4 // indirect
	github.com/aws/aws-sdk-go-v2/service/internal/presigned-url v1.11.19 // indirect
	github.com/aws/aws-sdk-go-v2/service/sso v1.22.7 // indirect
	github.com/aws/aws-sdk-go-v2/service/ssooidc v1.26.7 // indirect
	github.com/aws/aws-sdk-go-v2/service/sts v1.30.7 // indirect
	github.com/aws/smithy-go v1.20.4 // indirect
	github.com/jackc/pgpassfile v1.0.0 // indirect
	github.com/jackc/pgservicefile v0.0.0-20240606120523-5a60cdf6a761 // indirect
	github.com/kr/text v0.2.0 // indirect
	github.com/rogpeppe/go-internal v1.12.0 // indirect
	golang.org/x/sys v0.45.0 // indirect
	golang.org/x/text v0.37.0 // indirect
)

// The runner builds against the library in this repository, like the CLI.
replace github.com/ConductionNL/keepiq/sdk/go => ../../sdk/go
