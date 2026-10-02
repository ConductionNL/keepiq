package controller

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	appsv1 "k8s.io/api/apps/v1"
	corev1 "k8s.io/api/core/v1"
	"k8s.io/apimachinery/pkg/api/meta"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/runtime"
	"k8s.io/apimachinery/pkg/types"
	clientgoscheme "k8s.io/client-go/kubernetes/scheme"
	"k8s.io/client-go/tools/record"
	ctrl "sigs.k8s.io/controller-runtime"
	"sigs.k8s.io/controller-runtime/pkg/client"
	"sigs.k8s.io/controller-runtime/pkg/envtest"

	"github.com/ConductionNL/keepiq/sdk/go/keepiqtest"

	v1 "github.com/ConductionNL/keepiq/integrations/kubernetes/api/v1alpha1"
)

// These tests run against a real kube-apiserver and etcd (envtest) with the
// chart's CRDs, and a stub Keepiq serving envelopes encrypted to the shared
// test key in sdk/testdata. KUBEBUILDER_ASSETS must point at the envtest
// binaries (setup-envtest use 1.30.0 -p path).

var (
	k8s    client.Client
	scheme = runtime.NewScheme()
)

func TestMain(m *testing.M) {
	if os.Getenv("KUBEBUILDER_ASSETS") == "" {
		// Without the binaries nothing here can run; say so instead of passing.
		println("KUBEBUILDER_ASSETS is not set: run setup-envtest use 1.30.0 -p path first")
		os.Exit(1)
	}
	_ = clientgoscheme.AddToScheme(scheme)
	_ = v1.AddToScheme(scheme)
	env := &envtest.Environment{
		CRDDirectoryPaths:     []string{filepath.Join("..", "..", "charts", "keepiq-operator", "crds")},
		ErrorIfCRDPathMissing: true,
	}
	cfg, err := env.Start()
	if err != nil {
		panic(err)
	}
	k8s, err = client.New(cfg, client.Options{Scheme: scheme})
	if err != nil {
		panic(err)
	}
	code := m.Run()
	_ = env.Stop()
	os.Exit(code)
}

type fixture struct {
	t        *testing.T
	ctx      context.Context
	ns       string
	stub     *keepiqtest.Stub
	recorder *record.FakeRecorder
	r        *KeepiqSecretReconciler
}

var nsSeq int

func setup(t *testing.T) *fixture {
	t.Helper()
	ctx := context.Background()
	nsSeq++
	ns := "kq-" + strings.ToLower(strings.ReplaceAll(t.Name(), "_", "-"))
	if len(ns) > 50 {
		ns = ns[:50]
	}
	ns = strings.TrimRight(ns, "-") + "-" + time.Now().Format("150405") + "-" + string(rune('a'+nsSeq%26))
	if err := k8s.Create(ctx, &corev1.Namespace{ObjectMeta: metav1.ObjectMeta{Name: ns}}); err != nil {
		t.Fatal(err)
	}
	stub, err := keepiqtest.Start("shop-prod")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(stub.Close)
	// The fixture secret's name equals its value; give it a value that
	// appears nowhere else, so a leak check can tell them apart.
	if err := stub.SetValue("sec-cli-fixture", "key", dbPassword); err != nil {
		t.Fatal(err)
	}
	rec := record.NewFakeRecorder(100)
	f := &fixture{t: t, ctx: ctx, ns: ns, stub: stub, recorder: rec,
		r: &KeepiqSecretReconciler{Client: k8s, Scheme: scheme, Recorder: rec}}
	f.create(&corev1.Secret{ObjectMeta: metav1.ObjectMeta{Name: "keepiq-app-key", Namespace: ns},
		Data: map[string][]byte{"key.pem": []byte(stub.Fixture.PrivateKeyPem), "cert.pem": []byte(stub.Fixture.CertificatePem)}})
	f.create(&v1.KeepiqConnection{ObjectMeta: metav1.ObjectMeta{Name: "shop", Namespace: ns}, Spec: v1.KeepiqConnectionSpec{
		URL: stub.URL(), ApplicationID: "shop-prod", PrivateKeySecretRef: v1.SecretKeyRef{Name: "keepiq-app-key", Key: "key.pem"},
	}})
	return f
}

func (f *fixture) create(obj client.Object) {
	f.t.Helper()
	if err := k8s.Create(f.ctx, obj); err != nil {
		f.t.Fatalf("create %T: %v", obj, err)
	}
}

func (f *fixture) keepiqSecret(name string, items []v1.KeepiqSecretItem, restart ...v1.RestartTarget) *v1.KeepiqSecret {
	ks := &v1.KeepiqSecret{ObjectMeta: metav1.ObjectMeta{Name: name, Namespace: f.ns}, Spec: v1.KeepiqSecretSpec{
		ConnectionRef: v1.LocalObjectReference{Name: "shop"}, Target: v1.TargetRef{Name: "shop-db"},
		Items: items, RestartTargets: restart,
	}}
	f.create(ks)
	return ks
}

func (f *fixture) reconcile(name string) {
	f.t.Helper()
	res, err := f.r.Reconcile(f.ctx, ctrl.Request{NamespacedName: types.NamespacedName{Namespace: f.ns, Name: name}})
	if err != nil {
		f.t.Fatalf("reconcile: %v", err)
	}
	if res.RequeueAfter != DefaultRefresh {
		f.t.Fatalf("requeue after %v, want %v", res.RequeueAfter, DefaultRefresh)
	}
}

func (f *fixture) status(name string) v1.KeepiqSecret {
	f.t.Helper()
	var ks v1.KeepiqSecret
	if err := k8s.Get(f.ctx, types.NamespacedName{Namespace: f.ns, Name: name}, &ks); err != nil {
		f.t.Fatal(err)
	}
	return ks
}

func (f *fixture) target() (*corev1.Secret, bool) {
	var s corev1.Secret
	if err := k8s.Get(f.ctx, types.NamespacedName{Namespace: f.ns, Name: "shop-db"}, &s); err != nil {
		return nil, false
	}
	return &s, true
}

func (f *fixture) events() []string {
	var out []string
	for {
		select {
		case e := <-f.recorder.Events:
			out = append(out, e)
		default:
			return out
		}
	}
}

func (f *fixture) ready(name string) *metav1.Condition {
	ks := f.status(name)
	return meta.FindStatusCondition(ks.Status.Conditions, ConditionReady)
}

// noValueLeaks fails when a plaintext appears in status, events or any request
// body the operator sent to Keepiq.
func (f *fixture) noValueLeaks(name string, values ...string) {
	f.t.Helper()
	ks := f.status(name)
	raw, _ := yamlish(ks.Status)
	events := strings.Join(f.events(), "\n")
	for _, v := range append(values, "PRIVATE KEY") {
		if strings.Contains(raw, v) {
			f.t.Fatalf("status carries %q", v)
		}
		if strings.Contains(events, v) {
			f.t.Fatalf("an event carries %q", v)
		}
		if f.stub.BodiesContain(v) {
			f.t.Fatalf("a request to Keepiq carries %q", v)
		}
	}
}

const dbPassword = "s3cret-db-pass-7f3a"

func dbItems() []v1.KeepiqSecretItem {
	return []v1.KeepiqSecretItem{
		{Name: "ci-fixture-db-password", Field: "key", TargetKey: "DB_PASSWORD"},
		{Name: "ci-fixture-db-password", Field: "login", TargetKey: "DB_USER"},
		{Name: "ci-fixture-db-password", Field: "additionalFields.host", TargetKey: "DB_HOST"},
	}
}

// 1.2: invalid resources are rejected by the API server.
func TestCRDValidation(t *testing.T) {
	f := setup(t)
	bad := []struct {
		name string
		mut  func(*v1.KeepiqSecret)
	}{
		{"refresh under 10s", func(k *v1.KeepiqSecret) { k.Spec.RefreshInterval = &metav1.Duration{Duration: 5 * time.Second} }},
		{"unknown field", func(k *v1.KeepiqSecret) { k.Spec.Items[0].Field = "password" }},
		{"additionalFields without a name", func(k *v1.KeepiqSecret) { k.Spec.Items[0].Field = "additionalFields." }},
		{"duplicate target key", func(k *v1.KeepiqSecret) {
			k.Spec.Items = append(k.Spec.Items, v1.KeepiqSecretItem{Name: "x", Field: "key", TargetKey: k.Spec.Items[0].TargetKey})
		}},
		{"no items", func(k *v1.KeepiqSecret) { k.Spec.Items = nil }},
		{"restart kind", func(k *v1.KeepiqSecret) { k.Spec.RestartTargets = []v1.RestartTarget{{Kind: "Pod", Name: "x"}} }},
	}
	for i, b := range bad {
		ks := &v1.KeepiqSecret{ObjectMeta: metav1.ObjectMeta{Name: "bad-" + string(rune('a'+i)), Namespace: f.ns}, Spec: v1.KeepiqSecretSpec{
			ConnectionRef: v1.LocalObjectReference{Name: "shop"}, Target: v1.TargetRef{Name: "t"},
			Items: []v1.KeepiqSecretItem{{Name: "n", Field: "key", TargetKey: "K"}},
		}}
		b.mut(ks)
		if err := k8s.Create(f.ctx, ks); err == nil {
			t.Errorf("%s: accepted, want rejected", b.name)
		}
	}
	good := &v1.KeepiqSecret{ObjectMeta: metav1.ObjectMeta{Name: "good", Namespace: f.ns}, Spec: v1.KeepiqSecretSpec{
		ConnectionRef: v1.LocalObjectReference{Name: "shop"}, Target: v1.TargetRef{Name: "t"},
		RefreshInterval: &metav1.Duration{Duration: 10 * time.Second},
		Items:           []v1.KeepiqSecretItem{{Name: "n", Field: "additionalFields.host", TargetKey: "HOST"}},
	}}
	if err := k8s.Create(f.ctx, good); err != nil {
		t.Fatalf("valid resource rejected: %v", err)
	}
	var got v1.KeepiqSecret
	_ = k8s.Get(f.ctx, client.ObjectKeyFromObject(good), &got)
	if got.Spec.RefreshInterval.Duration != 10*time.Second {
		t.Fatalf("refreshInterval = %v", got.Spec.RefreshInterval)
	}
	defaulted := &v1.KeepiqSecret{ObjectMeta: metav1.ObjectMeta{Name: "defaulted", Namespace: f.ns}, Spec: v1.KeepiqSecretSpec{
		ConnectionRef: v1.LocalObjectReference{Name: "shop"}, Target: v1.TargetRef{Name: "t"},
		Items: []v1.KeepiqSecretItem{{Name: "n", Field: "key", TargetKey: "K"}},
	}}
	f.create(defaulted)
	_ = k8s.Get(f.ctx, client.ObjectKeyFromObject(defaulted), &got)
	if got.Spec.RefreshInterval == nil || got.Spec.RefreshInterval.Duration != DefaultRefresh {
		t.Fatalf("default refreshInterval = %v, want 60s", got.Spec.RefreshInterval)
	}
}

// 2.1: the target Secret gets the decrypted values, owned by the KeepiqSecret;
// an unchanged second loop does not write it.
func TestSyncWritesTheDecryptedValues(t *testing.T) {
	f := setup(t)
	f.keepiqSecret("db", dbItems())
	f.reconcile("db")

	target, ok := f.target()
	if !ok {
		t.Fatal("target Secret not created")
	}
	want := map[string]string{"DB_PASSWORD": dbPassword, "DB_USER": "ci-deployer", "DB_HOST": "db.internal.test"}
	for k, v := range want {
		if string(target.Data[k]) != v {
			t.Fatalf("%s = %q, want %q", k, target.Data[k], v)
		}
	}
	owner := f.status("db")
	if !metav1.IsControlledBy(target, &owner) {
		t.Fatalf("owner references %+v", target.OwnerReferences)
	}
	if c := f.ready("db"); c == nil || c.Status != metav1.ConditionTrue || c.Reason != ReasonSynced {
		t.Fatalf("Ready = %+v", c)
	}
	st := f.status("db")
	if len(st.Status.Items) != 3 || st.Status.Items[0].ETag == "" || st.Status.Items[0].SecretID != "sec-cli-fixture" {
		t.Fatalf("item status %+v", st.Status.Items)
	}
	f.noValueLeaks("db", dbPassword, "ci-deployer", "db.internal.test")

	rv := target.ResourceVersion
	exchanges := f.stub.Exchanges
	f.reconcile("db")
	again, _ := f.target()
	if again.ResourceVersion != rv {
		t.Fatal("an unchanged loop rewrote the target Secret")
	}
	if f.stub.Exchanges != exchanges {
		t.Fatalf("token exchanged again (%d -> %d), want cached", exchanges, f.stub.Exchanges)
	}
}

// A target Secret deleted by hand comes back on the next loop, even though
// Keepiq answers 304 to the remembered ETag.
func TestDeletedTargetIsRecreated(t *testing.T) {
	f := setup(t)
	f.keepiqSecret("db", dbItems()[:1])
	f.reconcile("db")
	target, _ := f.target()
	if err := k8s.Delete(f.ctx, target); err != nil {
		t.Fatal(err)
	}
	f.reconcile("db")
	again, ok := f.target()
	if !ok || string(again.Data["DB_PASSWORD"]) != dbPassword {
		t.Fatalf("target not recreated with the value: %v %+v", ok, again)
	}
}

// 2.3: a rotation updates the Secret and patches the Deployment template once.
func TestRotationRestartsTheWorkloadOnce(t *testing.T) {
	f := setup(t)
	replicas := int32(1)
	labels := map[string]string{"app": "shop-api"}
	f.create(&appsv1.Deployment{ObjectMeta: metav1.ObjectMeta{Name: "shop-api", Namespace: f.ns}, Spec: appsv1.DeploymentSpec{
		Replicas: &replicas, Selector: &metav1.LabelSelector{MatchLabels: labels},
		Template: corev1.PodTemplateSpec{ObjectMeta: metav1.ObjectMeta{Labels: labels}, Spec: corev1.PodSpec{Containers: []corev1.Container{{Name: "api", Image: "busybox"}}}},
	}})
	f.keepiqSecret("db", dbItems()[:1], v1.RestartTarget{Kind: "Deployment", Name: "shop-api"})
	annotation := func() string {
		var d appsv1.Deployment
		_ = k8s.Get(f.ctx, types.NamespacedName{Namespace: f.ns, Name: "shop-api"}, &d)
		return d.Spec.Template.Annotations[ChecksumAnnotation]
	}

	f.reconcile("db")
	if a := annotation(); a != "" {
		t.Fatalf("first sync restarted the workload (%s)", a)
	}

	if err := f.stub.SetValue("sec-cli-fixture", "key", "rotated-password-1"); err != nil {
		t.Fatal(err)
	}
	f.reconcile("db")
	target, _ := f.target()
	if string(target.Data["DB_PASSWORD"]) != "rotated-password-1" {
		t.Fatalf("after rotation DB_PASSWORD = %q", target.Data["DB_PASSWORD"])
	}
	first := annotation()
	if first == "" || first != f.status("db").Status.Checksum {
		t.Fatalf("annotation %q, status checksum %q", first, f.status("db").Status.Checksum)
	}

	f.reconcile("db")
	if annotation() != first {
		t.Fatal("an unchanged loop changed the annotation")
	}

	_ = f.stub.SetValue("sec-cli-fixture", "key", "rotated-password-2")
	f.reconcile("db")
	if second := annotation(); second == first || second == "" {
		t.Fatalf("second rotation: annotation %q (first %q)", second, first)
	}
	f.noValueLeaks("db", "rotated-password-1", "rotated-password-2")
}

func (f *fixture) expectFailure(name, reason string, mustMention ...string) {
	f.t.Helper()
	c := f.ready(name)
	if c == nil || c.Status != metav1.ConditionFalse || c.Reason != reason {
		f.t.Fatalf("Ready = %+v, want False/%s", c, reason)
	}
	events := strings.Join(f.events(), "\n")
	if !strings.Contains(events, reason) {
		f.t.Fatalf("no %s event in %q", reason, events)
	}
	for _, m := range mustMention {
		if !strings.Contains(events, m) {
			f.t.Fatalf("event does not mention %q: %s", m, events)
		}
	}
}

// 2.2: an unknown name leaves the target alone and says so.
func TestUnknownNameIsReported(t *testing.T) {
	f := setup(t)
	f.keepiqSecret("db", []v1.KeepiqSecretItem{{Name: "nope", Field: "key", TargetKey: "X"}})
	f.reconcile("db")
	f.expectFailure("db", ReasonNotFound, "nope")
	if _, ok := f.target(); ok {
		t.Fatal("target Secret created for a missing name")
	}
}

// 2.2: an ambiguous name lists both candidates and leaves the target unchanged.
func TestAmbiguousNameListsCandidates(t *testing.T) {
	f := setup(t)
	f.keepiqSecret("db", dbItems()[:1])
	f.reconcile("db")
	before, _ := f.target()
	_ = f.events()

	if _, err := f.stub.Add("sec-twin", "ci-fixture-db-password", "other/team", map[string]string{"key": "twin-value"}); err != nil {
		t.Fatal(err)
	}
	f.reconcile("db")
	f.expectFailure("db", ReasonAmbiguousName, "sec-cli-fixture in ci/database", "sec-twin in other/team")
	after, _ := f.target()
	if after.ResourceVersion != before.ResourceVersion {
		t.Fatal("target changed on an ambiguous name")
	}
	f.noValueLeaks("db", "twin-value", dbPassword)
}

// 2.2 and the spec scenario: a key that does not match the certificate is
// FingerprintMismatch and the target does not change.
func TestWrongKeyIsFingerprintMismatch(t *testing.T) {
	f := setup(t)
	f.keepiqSecret("db", dbItems()[:1])
	f.reconcile("db")
	before, _ := f.target()

	other := otherKeyPEM(t)
	var conn v1.KeepiqConnection
	_ = k8s.Get(f.ctx, types.NamespacedName{Namespace: f.ns, Name: "shop"}, &conn)
	conn.Spec.CertificateSecretRef = &v1.SecretKeyRef{Name: "keepiq-app-key", Key: "cert.pem"}
	if err := k8s.Update(f.ctx, &conn); err != nil {
		t.Fatal(err)
	}
	var key corev1.Secret
	_ = k8s.Get(f.ctx, types.NamespacedName{Namespace: f.ns, Name: "keepiq-app-key"}, &key)
	key.Data["key.pem"] = []byte(other)
	if err := k8s.Update(f.ctx, &key); err != nil {
		t.Fatal(err)
	}
	_ = f.events()
	f.reconcile("db")
	f.expectFailure("db", ReasonFingerprintMismatch)
	after, _ := f.target()
	if after.ResourceVersion != before.ResourceVersion {
		t.Fatal("target changed with a wrong key")
	}
}

// 2.2: without a certificate a wrong key is refused at the token exchange.
func TestRefusedTokenIsReported(t *testing.T) {
	f := setup(t)
	var key corev1.Secret
	_ = k8s.Get(f.ctx, types.NamespacedName{Namespace: f.ns, Name: "keepiq-app-key"}, &key)
	key.Data["key.pem"] = []byte(otherKeyPEM(t))
	_ = k8s.Update(f.ctx, &key)
	f.keepiqSecret("db", dbItems()[:1])
	f.reconcile("db")
	f.expectFailure("db", ReasonTokenRefused)
	if _, ok := f.target(); ok {
		t.Fatal("target created with a refused token")
	}
}

// 2.4: with leases advertised, a lease that lapses before the next loop is
// renewed; a refused renewal makes the operator read again for a new lease.
func TestLeasesAreRenewedAndRefetchedAfterRefusal(t *testing.T) {
	f := setup(t)
	f.stub.Leases = true
	f.stub.LeaseTTL = 45 * time.Second // shorter than refresh + margin
	f.keepiqSecret("db", dbItems()[:1])
	f.reconcile("db")
	st := f.status("db").Status.Items[0]
	if st.LeaseID == "" || st.LeaseExpires == nil {
		t.Fatalf("lease not recorded: %+v", st)
	}

	f.reconcile("db")
	if f.stub.Renewals != 1 {
		t.Fatalf("renewals = %d, want 1", f.stub.Renewals)
	}
	renewed := f.status("db").Status.Items[0]
	if renewed.LeaseID != st.LeaseID || renewed.LeaseExpires == nil {
		t.Fatalf("after renewal %+v", renewed)
	}

	f.stub.RefuseRenew = true
	reads := countReads(f.stub)
	f.reconcile("db")
	refetched := f.status("db").Status.Items[0]
	if countReads(f.stub) != reads+1 || refetched.LeaseID == st.LeaseID || refetched.LeaseID == "" {
		t.Fatalf("after a refused renewal: reads %d -> %d, lease %q -> %q", reads, countReads(f.stub), st.LeaseID, refetched.LeaseID)
	}
	if c := f.ready("db"); c.Status != metav1.ConditionTrue {
		t.Fatalf("Ready = %+v", c)
	}
}

// 2.4: against an instance without leases nothing about leases is recorded.
func TestWithoutLeasesNothingIsRenewed(t *testing.T) {
	f := setup(t)
	f.keepiqSecret("db", dbItems()[:1])
	f.reconcile("db")
	f.reconcile("db")
	if st := f.status("db").Status.Items[0]; st.LeaseID != "" || f.stub.Renewals != 0 {
		t.Fatalf("lease state without leases: %+v renewals=%d", st, f.stub.Renewals)
	}
}

func countReads(s *keepiqtest.Stub) int {
	s.Mu.Lock()
	defer s.Mu.Unlock()
	n := 0
	for _, r := range s.Requests {
		if strings.HasPrefix(r, "GET ") && strings.Contains(r, "/by-name/") {
			n++
		}
	}
	return n
}
