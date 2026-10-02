module github.com/ConductionNL/keepiq/cli

go 1.25.0

require (
	github.com/ConductionNL/keepiq/sdk/go v0.0.0
	golang.org/x/crypto v0.52.0
	golang.org/x/sys v0.45.0
)

// The CLI builds against the library in this repository, so a change to
// sdk/go is tested by the CLI in the same pull request. sdk/go stays stdlib
// only; the CLI adds golang.org/x/crypto and golang.org/x/sys for its SSH
// agent (cli-ssh-agent).
replace github.com/ConductionNL/keepiq/sdk/go => ../sdk/go
