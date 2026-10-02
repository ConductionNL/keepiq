// Package state is the runner's state directory: the rotation journal (values
// as ciphertext only), and the schedule and sync bookkeeping (ids, names,
// times and ETags only). Files are written atomically with mode 0600.
package state

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"time"
)

// Dir is one state directory.
type Dir struct{ Path string }

// Open creates the directory (0700) if needed.
func Open(path string) (*Dir, error) {
	if err := os.MkdirAll(filepath.Join(path, "journal"), 0o700); err != nil {
		return nil, err
	}
	return &Dir{Path: path}, nil
}

// JournalEntry is one rotation in flight. NewValue and OldValue are
// encrypted to the application's own key; nothing else is secret.
type JournalEntry struct {
	SecretID  string    `json:"secretId"`
	Name      string    `json:"name"`
	Folder    string    `json:"folder,omitempty"`
	ETag      string    `json:"etag"`
	User      string    `json:"user"`
	NewValue  string    `json:"newValueCiphertext"`
	OldValue  string    `json:"oldValueCiphertext"`
	Stage     string    `json:"stage"` // pending (before the target change) or set (target changed)
	CreatedAt time.Time `json:"createdAt"`
}

var unsafe = regexp.MustCompile(`[^A-Za-z0-9._-]`)

func (d *Dir) journalPath(secretID string) string {
	return filepath.Join(d.Path, "journal", unsafe.ReplaceAllString(secretID, "_")+".json")
}

// PutJournal writes or replaces an entry.
func (d *Dir) PutJournal(e JournalEntry) error { return d.write(d.journalPath(e.SecretID), e) }

// DeleteJournal removes an entry.
func (d *Dir) DeleteJournal(secretID string) error {
	err := os.Remove(d.journalPath(secretID))
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	return err
}

// Journal lists the entries, oldest first.
func (d *Dir) Journal() ([]JournalEntry, error) {
	files, err := filepath.Glob(filepath.Join(d.Path, "journal", "*.json"))
	if err != nil {
		return nil, err
	}
	var out []JournalEntry
	for _, f := range files {
		var e JournalEntry
		if err := d.read(f, &e); err != nil {
			return nil, err
		}
		out = append(out, e)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].CreatedAt.Before(out[j].CreatedAt) })
	return out, nil
}

// Rotations is the schedule bookkeeping, keyed by folder/name.
type Rotations map[string]RotationState

// RotationState says when a rotation last ran and last succeeded.
type RotationState struct {
	LastRun     time.Time `json:"lastRun"`
	LastRotated time.Time `json:"lastRotated"`
}

// LoadRotations reads rotations.json (empty when missing).
func (d *Dir) LoadRotations() (Rotations, error) {
	r := Rotations{}
	err := d.read(filepath.Join(d.Path, "rotations.json"), &r)
	if errors.Is(err, os.ErrNotExist) {
		return Rotations{}, nil
	}
	return r, err
}

// SaveRotations writes rotations.json.
func (d *Dir) SaveRotations(r Rotations) error {
	return d.write(filepath.Join(d.Path, "rotations.json"), r)
}

// SyncState is one sync set's bookkeeping.
type SyncState struct {
	LastPoll time.Time             `json:"lastPoll"`
	Entries  map[string]EntryState `json:"entries"`
}

// EntryState is one destination entry: the ETag last pushed, and retry state.
type EntryState struct {
	PushedETag  string    `json:"pushedEtag,omitempty"`
	Dirty       bool      `json:"dirty,omitempty"`
	Failures    int       `json:"failures,omitempty"`
	NextAttempt time.Time `json:"nextAttempt,omitempty"`
}

// LoadSync reads sync-<name>.json.
func (d *Dir) LoadSync(name string) (*SyncState, error) {
	s := &SyncState{Entries: map[string]EntryState{}}
	err := d.read(filepath.Join(d.Path, "sync-"+unsafe.ReplaceAllString(name, "_")+".json"), s)
	if errors.Is(err, os.ErrNotExist) {
		return &SyncState{Entries: map[string]EntryState{}}, nil
	}
	if s.Entries == nil {
		s.Entries = map[string]EntryState{}
	}
	return s, err
}

// SaveSync writes sync-<name>.json.
func (d *Dir) SaveSync(name string, s *SyncState) error {
	return d.write(filepath.Join(d.Path, "sync-"+unsafe.ReplaceAllString(name, "_")+".json"), s)
}

func (d *Dir) read(path string, v any) error {
	raw, err := os.ReadFile(path)
	if err != nil {
		return err
	}
	return json.Unmarshal(raw, v)
}

func (d *Dir) write(path string, v any) error {
	raw, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	tmp := path + ".tmp"
	f, err := os.OpenFile(tmp, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	if _, err := f.Write(raw); err != nil {
		f.Close()
		return err
	}
	if err := f.Sync(); err != nil {
		f.Close()
		return err
	}
	if err := f.Close(); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}
