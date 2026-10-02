package config

import (
	"strings"
	"testing"
	"time"
)

const valid = `
keepiq:
  url: https://cloud.example.org
  applicationId: ops-runner
  privateKeyFile: /run/keepiq/key.pem
stateDir: /var/lib/keepiq-runner
rotations:
  - secret: pg-app-password
    connector: postgres
    target: {host: "db:5432", database: app}
    adminSecret: pg-admin
    schedule: "0 3 * * 0"
  - secret: legacy-api
    connector: exec
    command: [/usr/local/bin/rotate-legacy]
    followExpiry: true
syncs:
  - secrets: [stripe-key]
    destination: aws-secrets-manager
    options: {region: eu-west-1, prefix: prod/}
`

func TestValidConfigGetsDefaults(t *testing.T) {
	c, err := Parse([]byte(valid))
	if err != nil {
		t.Fatal(err)
	}
	r := c.Rotations[0]
	if r.Generator.Length != 32 || len(r.Generator.Classes) != 3 || r.LeadTime.Duration != 7*24*time.Hour {
		t.Fatalf("rotation defaults %+v", r)
	}
	if c.Syncs[0].Interval.Duration != time.Minute || c.Syncs[0].Name != "aws-secrets-manager-0" || c.Tick.Duration != 30*time.Second {
		t.Fatalf("sync defaults %+v", c.Syncs[0])
	}
}

func TestInvalidConfigsAreRefusedWithAReason(t *testing.T) {
	cases := map[string]string{
		"unknown key":          strings.Replace(valid, "stateDir:", "statedir:", 1),
		"no state dir":         strings.Replace(valid, "stateDir: /var/lib/keepiq-runner", "", 1),
		"bad connector":        strings.Replace(valid, "connector: postgres", "connector: oracle", 1),
		"bad cron":             strings.Replace(valid, `"0 3 * * 0"`, `"every sunday"`, 1),
		"postgres no admin":    strings.Replace(valid, "adminSecret: pg-admin", "", 1),
		"exec no command":      strings.Replace(valid, "command: [/usr/local/bin/rotate-legacy]", "", 1),
		"no schedule":          strings.Replace(valid, "followExpiry: true", "", 1),
		"bad destination":      strings.Replace(valid, "destination: aws-secrets-manager", "destination: vault", 1),
		"short interval":       strings.Replace(valid, "options: {region", "interval: 5s\n    options: {region", 1),
		"short password":       strings.Replace(valid, "schedule: \"0 3 * * 0\"", "schedule: \"0 3 * * 0\"\n    generator: {length: 8}", 1),
		"github without token": strings.Replace(valid, "destination: aws-secrets-manager\n    options: {region: eu-west-1, prefix: prod/}", "destination: github-actions\n    options: {repository: example/app}", 1),
	}
	for name, raw := range cases {
		if _, err := Parse([]byte(raw)); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}
