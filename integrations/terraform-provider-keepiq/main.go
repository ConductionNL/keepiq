// Command terraform-provider-keepiq is the Terraform and OpenTofu provider
// for Keepiq application secrets. Values are decrypted and encrypted only in
// this process and never reach plan or state.
package main

import (
	"context"
	"flag"
	"log"

	"github.com/hashicorp/terraform-plugin-framework/providerserver"

	"github.com/ConductionNL/terraform-provider-keepiq/internal/provider"
)

var version = "dev"

func main() {
	var debug bool
	flag.BoolVar(&debug, "debug", false, "run with support for debuggers like delve")
	flag.Parse()
	err := providerserver.Serve(context.Background(), provider.New(version), providerserver.ServeOpts{
		Address: "registry.terraform.io/conductionnl/keepiq",
		Debug:   debug,
	})
	if err != nil {
		log.Fatal(err)
	}
}
