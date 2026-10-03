package provider

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// ErrNotFound is what the admin client returns for a 404.
var ErrNotFound = errors.New("not found in Keepiq")

// AdminApplication is an application as the Keepiq admin API returns it.
type AdminApplication struct {
	ID          string `json:"id"`
	Name        string `json:"name"`
	Description string `json:"description"`
	Type        string `json:"type"`
	Status      string `json:"status"`
	Certificate string `json:"certificate"`
}

// LeaseValues are the three lease policy values.
type LeaseValues struct {
	DefaultTTL *int64 `json:"defaultTtl"`
	MaxTTL     *int64 `json:"maxTtl"`
	Renewable  *bool  `json:"renewable"`
}

// LeasePolicy is an application's lease policy: its override and the
// values that apply.
type LeasePolicy struct {
	Override  *LeaseValues `json:"override"`
	Effective LeaseValues  `json:"effective"`
}

// AdminClient is the part of the Keepiq admin API (/api/v1/admin) the
// provider uses.
type AdminClient interface {
	CreateApplication(name, description, appType, csr string) (*AdminApplication, error)
	GetApplication(id string) (*AdminApplication, error)
	ApproveApplication(id string) (*AdminApplication, error)
	DeleteApplication(id string) error
	GetLeasePolicy(id string) (*LeasePolicy, error)
	SetLeasePolicy(id string, v LeaseValues) (*LeasePolicy, error)
}

// adminHTTP calls the admin API with a Nextcloud app password over HTTP
// Basic and the OCS-APIRequest header, as the admin API document asks.
type adminHTTP struct {
	base, user, password string
	http                 *http.Client
}

// NewAdminClient returns an admin API client for base (the Keepiq address,
// including /index.php without pretty URLs).
func NewAdminClient(base, user, password string) AdminClient {
	return &adminHTTP{
		base:     strings.TrimRight(base, "/") + "/apps/keepiq/api/v1/admin",
		user:     user,
		password: password,
		http:     &http.Client{Timeout: 30 * time.Second},
	}
}

func (c *adminHTTP) do(method, path string, body any, out any) error {
	var reader io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return err
		}
		reader = bytes.NewReader(b)
	}
	req, err := http.NewRequest(method, c.base+path, reader)
	if err != nil {
		return err
	}
	req.SetBasicAuth(c.user, c.password)
	req.Header.Set("OCS-APIRequest", "true")
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	switch {
	case resp.StatusCode == http.StatusNotFound:
		return ErrNotFound
	case resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden:
		return fmt.Errorf("Keepiq refused the admin call (%d): the admin user needs the Applications and machine access area", resp.StatusCode)
	case resp.StatusCode >= 300:
		var e struct {
			Message string `json:"message"`
		}
		_ = json.Unmarshal(raw, &e)
		if e.Message == "" {
			e.Message = http.StatusText(resp.StatusCode)
		}
		return fmt.Errorf("Keepiq answered %d: %s", resp.StatusCode, e.Message)
	}
	if out == nil {
		return nil
	}
	return json.Unmarshal(raw, out)
}

func (c *adminHTTP) CreateApplication(name, description, appType, csr string) (*AdminApplication, error) {
	body := map[string]string{"name": name, "type": appType}
	if description != "" {
		body["description"] = description
	}
	if csr != "" {
		body["csr"] = csr
	}
	var a AdminApplication
	return &a, c.do(http.MethodPost, "/applications", body, &a)
}

func (c *adminHTTP) GetApplication(id string) (*AdminApplication, error) {
	var a AdminApplication
	return &a, c.do(http.MethodGet, "/applications/"+url.PathEscape(id), nil, &a)
}

func (c *adminHTTP) ApproveApplication(id string) (*AdminApplication, error) {
	var a AdminApplication
	return &a, c.do(http.MethodPost, "/applications/"+url.PathEscape(id)+"/approve", nil, &a)
}

func (c *adminHTTP) DeleteApplication(id string) error {
	return c.do(http.MethodDelete, "/applications/"+url.PathEscape(id), nil, nil)
}

func (c *adminHTTP) GetLeasePolicy(id string) (*LeasePolicy, error) {
	var p LeasePolicy
	return &p, c.do(http.MethodGet, "/applications/"+url.PathEscape(id)+"/lease-policy", nil, &p)
}

func (c *adminHTTP) SetLeasePolicy(id string, v LeaseValues) (*LeasePolicy, error) {
	var p LeasePolicy
	return &p, c.do(http.MethodPut, "/applications/"+url.PathEscape(id)+"/lease-policy", v, &p)
}
