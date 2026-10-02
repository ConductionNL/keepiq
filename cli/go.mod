module github.com/ConductionNL/keepiq/cli

go 1.22

require github.com/ConductionNL/keepiq/sdk/go v0.0.0

// The CLI builds against the library in this repository, so a change to
// sdk/go is tested by the CLI in the same pull request. Both stay stdlib only.
replace github.com/ConductionNL/keepiq/sdk/go => ../sdk/go
