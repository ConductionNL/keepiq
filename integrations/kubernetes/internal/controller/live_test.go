package controller

import (
	"context"
	"os"
	"testing"

	corev1 "k8s.io/api/core/v1"
	"k8s.io/apimachinery/pkg/api/meta"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/types"
	"k8s.io/client-go/tools/record"
	ctrl "sigs.k8s.io/controller-runtime"

	v1 "github.com/ConductionNL/keepiq/integrations/kubernetes/api/v1alpha1"
)

// TestLiveSyncFromARealInstance syncs one secret from a real Keepiq into an
// envtest Secret. It runs only with:
//
//	KEEPIQ_LIVE_URL       the instance, for example http://localhost:8080/index.php
//	KEEPIQ_LIVE_APP_ID    an approved application
//	KEEPIQ_LIVE_KEY_FILE  that application's private key (PEM)
//	KEEPIQ_LIVE_SECRET    the name of a secret in its vault
func TestLiveSyncFromARealInstance(t *testing.T) {
	url, app, keyFile, name := os.Getenv("KEEPIQ_LIVE_URL"), os.Getenv("KEEPIQ_LIVE_APP_ID"), os.Getenv("KEEPIQ_LIVE_KEY_FILE"), os.Getenv("KEEPIQ_LIVE_SECRET")
	if url == "" || app == "" || keyFile == "" || name == "" {
		t.Skip("set KEEPIQ_LIVE_URL, KEEPIQ_LIVE_APP_ID, KEEPIQ_LIVE_KEY_FILE and KEEPIQ_LIVE_SECRET to run the live sync")
	}
	pem, err := os.ReadFile(keyFile)
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	ns := "keepiq-live"
	_ = k8s.Create(ctx, &corev1.Namespace{ObjectMeta: metav1.ObjectMeta{Name: ns}})
	_ = k8s.Create(ctx, &corev1.Secret{ObjectMeta: metav1.ObjectMeta{Name: "live-key", Namespace: ns}, Data: map[string][]byte{"key.pem": pem}})
	_ = k8s.Create(ctx, &v1.KeepiqConnection{ObjectMeta: metav1.ObjectMeta{Name: "live", Namespace: ns}, Spec: v1.KeepiqConnectionSpec{
		URL: url, ApplicationID: app, PrivateKeySecretRef: v1.SecretKeyRef{Name: "live-key", Key: "key.pem"},
	}})
	ks := &v1.KeepiqSecret{ObjectMeta: metav1.ObjectMeta{Name: "live", Namespace: ns}, Spec: v1.KeepiqSecretSpec{
		ConnectionRef: v1.LocalObjectReference{Name: "live"}, Target: v1.TargetRef{Name: "live-target"},
		Items: []v1.KeepiqSecretItem{{Name: name, Field: "key", TargetKey: "VALUE"}},
	}}
	if err := k8s.Create(ctx, ks); err != nil {
		t.Fatal(err)
	}
	r := &KeepiqSecretReconciler{Client: k8s, Scheme: scheme, Recorder: record.NewFakeRecorder(10)}
	if _, err := r.Reconcile(ctx, ctrl.Request{NamespacedName: types.NamespacedName{Namespace: ns, Name: "live"}}); err != nil {
		t.Fatal(err)
	}
	var got v1.KeepiqSecret
	_ = k8s.Get(ctx, types.NamespacedName{Namespace: ns, Name: "live"}, &got)
	if c := meta.FindStatusCondition(got.Status.Conditions, ConditionReady); c == nil || c.Status != metav1.ConditionTrue {
		t.Fatalf("Ready = %+v", c)
	}
	var target corev1.Secret
	if err := k8s.Get(ctx, types.NamespacedName{Namespace: ns, Name: "live-target"}, &target); err != nil || len(target.Data["VALUE"]) == 0 {
		t.Fatalf("target: %v", err)
	}
	t.Logf("synced %d bytes from %s", len(target.Data["VALUE"]), url)
}
