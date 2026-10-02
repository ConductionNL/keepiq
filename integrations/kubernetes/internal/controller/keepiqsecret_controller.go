// Package controller reconciles KeepiqSecret resources: it reads Keepiq
// application secrets through the machine API, decrypts them in the operator
// process with the application key held in the cluster, and writes them into
// a Kubernetes Secret.
package controller

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strings"
	"sync"
	"time"

	appsv1 "k8s.io/api/apps/v1"
	corev1 "k8s.io/api/core/v1"
	apierrors "k8s.io/apimachinery/pkg/api/errors"
	"k8s.io/apimachinery/pkg/api/meta"
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/runtime"
	"k8s.io/apimachinery/pkg/types"
	"k8s.io/client-go/tools/record"
	ctrl "sigs.k8s.io/controller-runtime"
	"sigs.k8s.io/controller-runtime/pkg/builder"
	"sigs.k8s.io/controller-runtime/pkg/predicate"
	"sigs.k8s.io/controller-runtime/pkg/client"
	"sigs.k8s.io/controller-runtime/pkg/controller/controllerutil"
	"sigs.k8s.io/controller-runtime/pkg/log"

	keepiq "github.com/ConductionNL/keepiq/sdk/go"

	v1 "github.com/ConductionNL/keepiq/integrations/kubernetes/api/v1alpha1"
)

const (
	// ConditionReady is the one condition a KeepiqSecret reports.
	ConditionReady = "Ready"

	// ChecksumAnnotation is patched onto restart targets' pod templates.
	ChecksumAnnotation = "keepiq.conduction.nl/checksum"

	// DefaultRefresh and MinRefresh bound spec.refreshInterval.
	DefaultRefresh = 60 * time.Second
	MinRefresh     = 10 * time.Second

	// Reasons on the Ready condition and on events.
	ReasonSynced              = "Synced"
	ReasonConnectionNotFound  = "ConnectionNotFound"
	ReasonKeyNotFound         = "KeySecretNotFound"
	ReasonNotFound            = "SecretNotFound"
	ReasonAmbiguousName       = "AmbiguousName"
	ReasonTokenRefused        = "TokenRefused"
	ReasonFingerprintMismatch = "FingerprintMismatch"
	ReasonFieldNotFound       = "FieldNotFound"
	ReasonKeepiqError         = "KeepiqError"
	ReasonTargetConflict      = "TargetNotOwned"
)

// KeepiqClient is the part of the Go library the operator uses.
type KeepiqClient interface {
	GetByNameIfNoneMatch(name, folder, etag string) (*keepiq.Secret, error)
	LeaseSupported() (bool, error)
	RenewLease(id string) (*keepiq.Lease, error)
}

// ClientFactory builds a Keepiq client for a connection.
type ClientFactory func(url, applicationID, privateKeyPEM, certificatePEM string) (KeepiqClient, error)

// DefaultClientFactory builds a keepiq.Client.
func DefaultClientFactory(url, applicationID, privateKeyPEM, certificatePEM string) (KeepiqClient, error) {
	var opts []keepiq.Option
	if certificatePEM != "" {
		opts = append(opts, keepiq.WithCertificate(certificatePEM))
	}
	return keepiq.New(url, applicationID, privateKeyPEM, opts...)
}

// KeepiqSecretReconciler syncs KeepiqSecrets.
type KeepiqSecretReconciler struct {
	client.Client
	Scheme   *runtime.Scheme
	Recorder record.EventRecorder
	Factory  ClientFactory
	Now      func() time.Time

	mu      sync.Mutex
	clients map[string]KeepiqClient // by connection identity and key hash
}

// failure is a reconcile outcome that sets Ready=False.
type failure struct {
	reason  string
	message string
}

func (f *failure) Error() string { return f.reason + ": " + f.message }

func fail(reason, format string, args ...any) *failure {
	return &failure{reason: reason, message: fmt.Sprintf(format, args...)}
}

// +kubebuilder:rbac:groups=keepiq.conduction.nl,resources=keepiqsecrets;keepiqconnections,verbs=get;list;watch
// +kubebuilder:rbac:groups=keepiq.conduction.nl,resources=keepiqsecrets/status,verbs=get;update;patch
// +kubebuilder:rbac:groups="",resources=secrets,verbs=get;list;watch;create;update;patch
// +kubebuilder:rbac:groups="",resources=events,verbs=create;patch
// +kubebuilder:rbac:groups=apps,resources=deployments;statefulsets,verbs=get;patch

// Reconcile brings one KeepiqSecret's target Secret up to date.
func (r *KeepiqSecretReconciler) Reconcile(ctx context.Context, req ctrl.Request) (ctrl.Result, error) {
	logger := log.FromContext(ctx)
	var ks v1.KeepiqSecret
	if err := r.Get(ctx, req.NamespacedName, &ks); err != nil {
		return ctrl.Result{}, client.IgnoreNotFound(err)
	}
	interval := refreshInterval(&ks)

	result, err := r.sync(ctx, &ks, interval)
	var f *failure
	if errors.As(err, &f) {
		// Never a value in here: messages are built from names, ids and
		// reasons only.
		logger.Info("keepiq secret not synced", "reason", f.reason, "message", f.message)
		r.Recorder.Event(&ks, corev1.EventTypeWarning, f.reason, f.message)
		meta.SetStatusCondition(&ks.Status.Conditions, metav1.Condition{
			Type: ConditionReady, Status: metav1.ConditionFalse, Reason: f.reason, Message: f.message,
			ObservedGeneration: ks.Generation,
		})
		ks.Status.ObservedGeneration = ks.Generation
		if uerr := r.Status().Update(ctx, &ks); uerr != nil {
			return ctrl.Result{}, uerr
		}
		return ctrl.Result{RequeueAfter: interval}, nil
	}
	if err != nil {
		return ctrl.Result{}, err
	}
	return result, nil
}

func refreshInterval(ks *v1.KeepiqSecret) time.Duration {
	if ks.Spec.RefreshInterval == nil || ks.Spec.RefreshInterval.Duration == 0 {
		return DefaultRefresh
	}
	if ks.Spec.RefreshInterval.Duration < MinRefresh {
		return MinRefresh
	}
	return ks.Spec.RefreshInterval.Duration
}

func (r *KeepiqSecretReconciler) now() time.Time {
	if r.Now != nil {
		return r.Now()
	}
	return time.Now()
}

func (r *KeepiqSecretReconciler) sync(ctx context.Context, ks *v1.KeepiqSecret, interval time.Duration) (ctrl.Result, error) {
	kc, err := r.keepiqClient(ctx, ks)
	if err != nil {
		return ctrl.Result{}, err
	}

	// The current target, if any.
	var target corev1.Secret
	targetKey := types.NamespacedName{Namespace: ks.Namespace, Name: ks.Spec.Target.Name}
	exists := true
	if err := r.Get(ctx, targetKey, &target); err != nil {
		if !apierrors.IsNotFound(err) {
			return ctrl.Result{}, err
		}
		exists = false
	}
	if exists && !metav1.IsControlledBy(&target, ks) {
		return ctrl.Result{}, fail(ReasonTargetConflict, "Secret %s exists and is not managed by this KeepiqSecret", ks.Spec.Target.Name)
	}

	leases, err := kc.LeaseSupported()
	if err != nil {
		return ctrl.Result{}, classify(err, "discovery")
	}

	prior := map[string]v1.ItemStatus{}
	for _, it := range ks.Status.Items {
		prior[itemKey(it.Name, it.Folder)] = it
	}

	data := map[string][]byte{}
	var items []v1.ItemStatus
	now := r.now()
	for _, item := range ks.Spec.Items {
		key := itemKey(item.Name, item.Folder)
		st := prior[key]
		st.Name, st.Folder = item.Name, item.Folder

		// Send the remembered ETag only when the target still holds the value
		// it stands for; otherwise read the whole envelope again.
		etag := st.ETag
		if !exists || target.Data[item.TargetKey] == nil {
			etag = ""
		}

		// Renew a lease that would lapse before the next loop. A refused
		// renewal means the grant is gone: read again now for a fresh one.
		if leases && st.LeaseID != "" && st.LeaseExpires != nil && st.LeaseExpires.Time.Before(now.Add(interval+30*time.Second)) {
			if lease, rerr := kc.RenewLease(st.LeaseID); rerr == nil {
				st.LeaseExpires = leaseTime(lease)
			} else {
				log.FromContext(ctx).Info("lease renewal refused, reading again", "lease", st.LeaseID, "error", rerr.Error())
				st.LeaseID, st.LeaseExpires, etag = "", nil, ""
			}
		}

		secret, err := kc.GetByNameIfNoneMatch(item.Name, item.Folder, etag)
		switch {
		case errors.Is(err, keepiq.ErrNotModified):
			data[item.TargetKey] = target.Data[item.TargetKey]
		case err != nil:
			return ctrl.Result{}, classify(err, item.Name)
		default:
			value, ferr := fieldValue(secret, item.Field)
			if ferr != nil {
				return ctrl.Result{}, ferr
			}
			data[item.TargetKey] = []byte(value)
			st.SecretID, st.ETag = secret.ID, secret.ETag
			if leases && secret.Lease != nil {
				st.LeaseID, st.LeaseExpires = secret.Lease.ID, leaseTime(secret.Lease)
			}
		}
		items = append(items, st)
	}

	sum := checksum(data)
	changed := !exists || sum != checksum(target.Data)
	if !exists {
		target = corev1.Secret{ObjectMeta: metav1.ObjectMeta{Namespace: ks.Namespace, Name: ks.Spec.Target.Name}, Type: corev1.SecretTypeOpaque}
		target.Data = data
		if err := controllerutil.SetControllerReference(ks, &target, r.Scheme); err != nil {
			return ctrl.Result{}, err
		}
		if err := r.Create(ctx, &target); err != nil {
			return ctrl.Result{}, err
		}
	} else if changed {
		target.Data = data
		if err := r.Update(ctx, &target); err != nil {
			return ctrl.Result{}, err
		}
	}

	// A rotation: the target held other values before this loop.
	if exists && changed {
		for _, rt := range ks.Spec.RestartTargets {
			if err := r.restart(ctx, ks.Namespace, rt, sum); err != nil {
				return ctrl.Result{}, err
			}
		}
		r.Recorder.Event(ks, corev1.EventTypeNormal, "Rotated", fmt.Sprintf("Secret %s updated with new values", ks.Spec.Target.Name))
	}

	ks.Status.Items = items
	ks.Status.Checksum = sum
	ks.Status.ObservedGeneration = ks.Generation
	t := metav1.NewTime(now)
	ks.Status.LastSynced = &t
	meta.SetStatusCondition(&ks.Status.Conditions, metav1.Condition{
		Type: ConditionReady, Status: metav1.ConditionTrue, Reason: ReasonSynced,
		Message: fmt.Sprintf("%d item(s) synced into Secret %s", len(items), ks.Spec.Target.Name), ObservedGeneration: ks.Generation,
	})
	if err := r.Status().Update(ctx, ks); err != nil {
		return ctrl.Result{}, err
	}
	return ctrl.Result{RequeueAfter: interval}, nil
}

// keepiqClient loads the connection and the key, and returns a cached client
// for that exact identity, so tokens are reused across loops.
func (r *KeepiqSecretReconciler) keepiqClient(ctx context.Context, ks *v1.KeepiqSecret) (KeepiqClient, error) {
	var conn v1.KeepiqConnection
	if err := r.Get(ctx, types.NamespacedName{Namespace: ks.Namespace, Name: ks.Spec.ConnectionRef.Name}, &conn); err != nil {
		if apierrors.IsNotFound(err) {
			return nil, fail(ReasonConnectionNotFound, "KeepiqConnection %s not found", ks.Spec.ConnectionRef.Name)
		}
		return nil, err
	}
	pem, err := r.secretValue(ctx, ks.Namespace, conn.Spec.PrivateKeySecretRef)
	if err != nil {
		return nil, err
	}
	cert := ""
	if conn.Spec.CertificateSecretRef != nil {
		if cert, err = r.secretValue(ctx, ks.Namespace, *conn.Spec.CertificateSecretRef); err != nil {
			return nil, err
		}
	}
	h := sha256.Sum256([]byte(conn.Spec.URL + "\x00" + conn.Spec.ApplicationID + "\x00" + pem + "\x00" + cert))
	id := ks.Namespace + "/" + conn.Name + "/" + hex.EncodeToString(h[:])

	r.mu.Lock()
	defer r.mu.Unlock()
	if r.clients == nil {
		r.clients = map[string]KeepiqClient{}
	}
	if c, ok := r.clients[id]; ok {
		return c, nil
	}
	factory := r.Factory
	if factory == nil {
		factory = DefaultClientFactory
	}
	c, err := factory(conn.Spec.URL, conn.Spec.ApplicationID, pem, cert)
	if err != nil {
		if strings.Contains(err.Error(), "mismatch") {
			return nil, fail(ReasonFingerprintMismatch, "the private key in Secret %s does not match the application certificate", conn.Spec.PrivateKeySecretRef.Name)
		}
		return nil, fail(ReasonKeyNotFound, "KeepiqConnection %s: %s", conn.Name, err.Error())
	}
	r.clients[id] = c
	return c, nil
}

func (r *KeepiqSecretReconciler) secretValue(ctx context.Context, ns string, ref v1.SecretKeyRef) (string, error) {
	var s corev1.Secret
	if err := r.Get(ctx, types.NamespacedName{Namespace: ns, Name: ref.Name}, &s); err != nil {
		if apierrors.IsNotFound(err) {
			return "", fail(ReasonKeyNotFound, "Secret %s not found", ref.Name)
		}
		return "", err
	}
	v, ok := s.Data[ref.Key]
	if !ok || len(v) == 0 {
		return "", fail(ReasonKeyNotFound, "Secret %s has no key %s", ref.Name, ref.Key)
	}
	return string(v), nil
}

// restart patches the checksum annotation onto a workload's pod template.
func (r *KeepiqSecretReconciler) restart(ctx context.Context, ns string, rt v1.RestartTarget, sum string) error {
	var obj client.Object
	switch rt.Kind {
	case "Deployment":
		obj = &appsv1.Deployment{}
	case "StatefulSet":
		obj = &appsv1.StatefulSet{}
	default:
		return fail(ReasonKeepiqError, "restart target kind %s is not Deployment or StatefulSet", rt.Kind)
	}
	obj.SetNamespace(ns)
	obj.SetName(rt.Name)
	patch, _ := json.Marshal(map[string]any{
		"spec": map[string]any{"template": map[string]any{"metadata": map[string]any{"annotations": map[string]string{ChecksumAnnotation: sum}}}},
	})
	if err := r.Patch(ctx, obj, client.RawPatch(types.MergePatchType, patch)); err != nil {
		if apierrors.IsNotFound(err) {
			r.Recorder.Event(obj, corev1.EventTypeWarning, "RestartTargetNotFound", rt.Kind+" "+rt.Name+" not found")
			return nil
		}
		return err
	}
	return nil
}

// classify turns a library error into a Ready=False reason. Messages carry
// names, ids and folder paths, never a value.
func classify(err error, name string) error {
	var amb *keepiq.AmbiguousNameError
	var apiErr *keepiq.APIError
	switch {
	case errors.Is(err, keepiq.ErrNotFound):
		return fail(ReasonNotFound, "Keepiq secret %q not found in the application vault", name)
	case errors.As(err, &amb):
		parts := make([]string, 0, len(amb.Candidates))
		for _, c := range amb.Candidates {
			folder := c.FolderPath
			if folder == "" {
				folder = "/"
			}
			parts = append(parts, c.ID+" in "+folder)
		}
		return fail(ReasonAmbiguousName, "%d Keepiq secrets are named %q: %s; set a folder or rename one", len(amb.Candidates), name, strings.Join(parts, ", "))
	case errors.Is(err, keepiq.ErrUnauthorized):
		return fail(ReasonTokenRefused, "Keepiq refused the application token; check the application id and key")
	case errors.Is(err, keepiq.ErrKeyMismatch):
		return fail(ReasonFingerprintMismatch, "Keepiq secret %q is encrypted to another certificate than this connection's", name)
	case errors.As(err, &apiErr):
		return fail(ReasonKeepiqError, "Keepiq answered %d for %q", apiErr.Status, name)
	default:
		if strings.Contains(err.Error(), "decrypt") {
			return fail(ReasonFingerprintMismatch, "Keepiq secret %q does not decrypt with this connection's key", name)
		}
		return fail(ReasonKeepiqError, "reading %q: %s", name, err.Error())
	}
}

// fieldValue picks key, login or additionalFields.<name> from a secret.
func fieldValue(s *keepiq.Secret, field string) (string, error) {
	switch {
	case field == "key":
		return s.Key, nil
	case field == "login":
		return s.Login, nil
	case strings.HasPrefix(field, "additionalFields."):
		name := strings.TrimPrefix(field, "additionalFields.")
		var fields map[string]any
		if s.AdditionalFields == "" || json.Unmarshal([]byte(s.AdditionalFields), &fields) != nil {
			return "", fail(ReasonFieldNotFound, "Keepiq secret %q has no additional fields", s.Name)
		}
		v, ok := fields[name]
		if !ok {
			return "", fail(ReasonFieldNotFound, "Keepiq secret %q has no additional field %q", s.Name, name)
		}
		if str, ok := v.(string); ok {
			return str, nil
		}
		raw, _ := json.Marshal(v)
		return string(raw), nil
	default:
		return "", fail(ReasonFieldNotFound, "field %q is not key, login or additionalFields.<name>", field)
	}
}

func itemKey(name, folder string) string { return folder + "\x00" + name }

func leaseTime(l *keepiq.Lease) *metav1.Time {
	t := l.ExpiresAt()
	if t.IsZero() {
		return nil
	}
	mt := metav1.NewTime(t)
	return &mt
}

// checksum is a SHA-256 over the sorted keys and values; it identifies the
// data without keeping it.
func checksum(data map[string][]byte) string {
	keys := make([]string, 0, len(data))
	for k := range data {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	h := sha256.New()
	for _, k := range keys {
		fmt.Fprintf(h, "%d:%s=%d:", len(k), k, len(data[k]))
		h.Write(data[k])
	}
	return hex.EncodeToString(h.Sum(nil))
}

// SetupWithManager registers the reconciler and watches owned Secrets.
func (r *KeepiqSecretReconciler) SetupWithManager(mgr ctrl.Manager) error {
	return ctrl.NewControllerManagedBy(mgr).
		// Status writes do not change the generation, so they do not loop.
		For(&v1.KeepiqSecret{}, builder.WithPredicates(predicate.GenerationChangedPredicate{})).
		Owns(&corev1.Secret{}).
		Complete(r)
}
