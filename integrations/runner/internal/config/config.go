// Package config loads runner.yaml. It names Keepiq secrets, never values:
// every credential the runner needs is itself a secret in the application's
// own vault.
package config

import (
	"errors"
	"fmt"
	"os"
	"strings"
	"time"

	"github.com/robfig/cron/v3"
	"gopkg.in/yaml.v3"
)

// Config is runner.yaml.
type Config struct {
	Keepiq    Keepiq     `yaml:"keepiq"`
	StateDir  string     `yaml:"stateDir"`
	Tick      Duration   `yaml:"tick"` // how often the daemon checks schedules (default 30s)
	Rotations []Rotation `yaml:"rotations"`
	Syncs     []Sync     `yaml:"syncs"`
}

// Keepiq is the instance and the application the runner acts as.
type Keepiq struct {
	URL            string `yaml:"url"`
	ApplicationID  string `yaml:"applicationId"`
	PrivateKeyFile string `yaml:"privateKeyFile"`
	// CertificateFile is optional; with it, envelopes for another certificate are refused.
	CertificateFile string `yaml:"certificateFile"`
}

// Rotation rotates one Keepiq secret at its target.
type Rotation struct {
	Secret    string            `yaml:"secret"`
	Folder    string            `yaml:"folder"`
	Connector string            `yaml:"connector"` // postgres, mysql or exec
	Target    map[string]string `yaml:"target"`    // connector options, no secrets
	Command   []string          `yaml:"command"`   // exec connector
	// AdminSecret is the Keepiq secret holding the target admin credential
	// (login = user, key = password). Not used by exec.
	AdminSecret  string    `yaml:"adminSecret"`
	Schedule     string    `yaml:"schedule"` // cron, five fields
	FollowExpiry bool      `yaml:"followExpiry"`
	LeadTime     Duration  `yaml:"leadTime"` // default 168h
	Generator    Generator `yaml:"generator"`
}

// Generator shapes new passwords.
type Generator struct {
	Length  int      `yaml:"length"`  // default 32
	Classes []string `yaml:"classes"` // lower, upper, digits, symbols (default all but symbols)
}

// Sync pushes a set of Keepiq secrets to one destination.
type Sync struct {
	Name        string            `yaml:"name"`
	Secrets     []string          `yaml:"secrets"`
	Folder      string            `yaml:"folder"`
	Destination string            `yaml:"destination"` // aws-secrets-manager, azure-key-vault, github-actions, exec
	Options     map[string]string `yaml:"options"`
	Command     []string          `yaml:"command"` // exec destination
	// CredentialsSecret is the Keepiq secret with destination credentials;
	// empty uses the ambient identity (AWS default chain, Azure managed identity).
	CredentialsSecret string   `yaml:"credentialsSecret"`
	Interval          Duration `yaml:"interval"` // default 60s
}

// Duration is a time.Duration written as "60s" or "168h".
type Duration struct{ time.Duration }

// UnmarshalYAML parses a Go duration string.
func (d *Duration) UnmarshalYAML(n *yaml.Node) error {
	var s string
	if err := n.Decode(&s); err != nil {
		return err
	}
	v, err := time.ParseDuration(s)
	if err != nil {
		return fmt.Errorf("invalid duration %q: %w", s, err)
	}
	d.Duration = v
	return nil
}

var (
	connectors   = map[string]bool{"postgres": true, "mysql": true, "exec": true}
	destinations = map[string]bool{"aws-secrets-manager": true, "azure-key-vault": true, "github-actions": true, "exec": true}
	classes      = map[string]bool{"lower": true, "upper": true, "digits": true, "symbols": true}
)

// CronParser parses the five-field schedules.
var CronParser = cron.NewParser(cron.Minute | cron.Hour | cron.Dom | cron.Month | cron.Dow | cron.Descriptor)

// Load reads and validates a config file and fills defaults.
func Load(path string) (*Config, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	return Parse(raw)
}

// Parse validates YAML bytes and fills defaults. Unknown keys are refused, so
// a typo cannot silently turn a check off.
func Parse(raw []byte) (*Config, error) {
	var c Config
	dec := yaml.NewDecoder(strings.NewReader(string(raw)))
	dec.KnownFields(true)
	if err := dec.Decode(&c); err != nil {
		return nil, fmt.Errorf("runner.yaml: %w", err)
	}
	var errs []string
	add := func(format string, a ...any) { errs = append(errs, fmt.Sprintf(format, a...)) }
	if c.Keepiq.URL == "" || c.Keepiq.ApplicationID == "" || c.Keepiq.PrivateKeyFile == "" {
		add("keepiq.url, keepiq.applicationId and keepiq.privateKeyFile are required")
	}
	if c.StateDir == "" {
		add("stateDir is required (the journal and sync state live there)")
	}
	if c.Tick.Duration == 0 {
		c.Tick.Duration = 30 * time.Second
	}
	if len(c.Rotations) == 0 && len(c.Syncs) == 0 {
		add("configure at least one rotation or sync")
	}
	seen := map[string]bool{}
	for i := range c.Rotations {
		checkRotation(&c.Rotations[i], fmt.Sprintf("rotations[%d]", i), seen, add)
	}
	names := map[string]bool{}
	for i := range c.Syncs {
		checkSync(&c.Syncs[i], i, names, add)
	}
	if len(errs) > 0 {
		return nil, errors.New("runner.yaml: " + strings.Join(errs, "; "))
	}
	return &c, nil
}

// checkRotation fills a rotation's defaults and reports what is wrong with it.
// seen holds the folder/secret pairs of the rotations before it.
func checkRotation(r *Rotation, at string, seen map[string]bool, add func(string, ...any)) {
	if r.Secret == "" {
		add("%s.secret is required", at)
	}
	key := r.Folder + "/" + r.Secret
	if seen[key] {
		add("%s: secret %q is rotated twice", at, r.Secret)
	}
	seen[key] = true
	checkRotationConnector(r, at, add)
	if r.Schedule == "" && !r.FollowExpiry {
		add("%s needs a schedule, followExpiry, or both", at)
	}
	if r.Schedule != "" {
		if _, err := CronParser.Parse(r.Schedule); err != nil {
			add("%s.schedule: %v", at, err)
		}
	}
	if r.LeadTime.Duration == 0 {
		r.LeadTime.Duration = 7 * 24 * time.Hour
	}
	checkGenerator(&r.Generator, at, add)
}

// checkRotationConnector reports a connector that is unknown or misses what it needs.
func checkRotationConnector(r *Rotation, at string, add func(string, ...any)) {
	if !connectors[r.Connector] {
		add("%s.connector must be postgres, mysql or exec", at)
	}
	if r.Connector == "exec" && len(r.Command) == 0 {
		add("%s.command is required for the exec connector", at)
	}
	if (r.Connector == "postgres" || r.Connector == "mysql") && (r.AdminSecret == "" || r.Target["host"] == "") {
		add("%s needs adminSecret and target.host", at)
	}
}

// checkGenerator fills the password generator's defaults and checks its length and classes.
func checkGenerator(g *Generator, at string, add func(string, ...any)) {
	if g.Length == 0 {
		g.Length = 32
	}
	if g.Length < 16 || g.Length > 256 {
		add("%s.generator.length must be between 16 and 256", at)
	}
	if len(g.Classes) == 0 {
		g.Classes = []string{"lower", "upper", "digits"}
	}
	for _, cl := range g.Classes {
		if !classes[cl] {
			add("%s.generator.classes: unknown class %q", at, cl)
		}
	}
}

// checkSync fills sync i's defaults and reports what is wrong with it.
// names holds the names of the syncs before it.
func checkSync(s *Sync, i int, names map[string]bool, add func(string, ...any)) {
	at := fmt.Sprintf("syncs[%d]", i)
	if s.Name == "" {
		s.Name = fmt.Sprintf("%s-%d", s.Destination, i)
	}
	if names[s.Name] {
		add("%s: name %q is used twice", at, s.Name)
	}
	names[s.Name] = true
	if len(s.Secrets) == 0 {
		add("%s.secrets needs at least one name", at)
	}
	checkSyncDestination(s, at, add)
	if s.Interval.Duration == 0 {
		s.Interval.Duration = 60 * time.Second
	}
	if s.Interval.Duration < 10*time.Second {
		add("%s.interval must be at least 10s", at)
	}
}

// checkSyncDestination reports a destination that is unknown or misses what it needs.
func checkSyncDestination(s *Sync, at string, add func(string, ...any)) {
	if !destinations[s.Destination] {
		add("%s.destination must be aws-secrets-manager, azure-key-vault, github-actions or exec", at)
	}
	if s.Destination == "exec" && len(s.Command) == 0 {
		add("%s.command is required for the exec destination", at)
	}
	if s.Destination == "azure-key-vault" && s.Options["vaultUrl"] == "" {
		add("%s.options.vaultUrl is required", at)
	}
	if s.Destination == "github-actions" && s.Options["repository"] == "" && s.Options["organization"] == "" {
		add("%s.options needs repository or organization", at)
	}
	if s.Destination == "github-actions" && s.CredentialsSecret == "" {
		add("%s.credentialsSecret is required (a GitHub token)", at)
	}
}
