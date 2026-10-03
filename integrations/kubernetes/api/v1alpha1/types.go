package v1alpha1

import (
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
)

// SecretKeyRef points at one key of a Kubernetes Secret in the same namespace.
type SecretKeyRef struct {
	Name string `json:"name"`
	Key  string `json:"key"`
}

// KeepiqConnectionSpec says which Keepiq instance and application to use.
type KeepiqConnectionSpec struct {
	// URL is the Keepiq (Nextcloud) address. Include /index.php when the
	// instance has no pretty URLs.
	URL string `json:"url"`
	// ApplicationID is the approved Keepiq application's id.
	ApplicationID string `json:"applicationId"`
	// PrivateKeySecretRef is the Kubernetes Secret key holding the
	// application private key (PEM). It never leaves the cluster.
	PrivateKeySecretRef SecretKeyRef `json:"privateKeySecretRef"`
	// CertificateSecretRef optionally holds the application certificate
	// (PEM). When set, envelopes encrypted to another certificate are
	// refused before decryption.
	CertificateSecretRef *SecretKeyRef `json:"certificateSecretRef,omitempty"`
}

// KeepiqConnection is one Keepiq application the operator authenticates as.
// +kubebuilder:object:root=true
type KeepiqConnection struct {
	metav1.TypeMeta   `json:",inline"`
	metav1.ObjectMeta `json:"metadata,omitempty"`

	Spec KeepiqConnectionSpec `json:"spec"`
}

// KeepiqConnectionList is a list of KeepiqConnection.
// +kubebuilder:object:root=true
type KeepiqConnectionList struct {
	metav1.TypeMeta `json:",inline"`
	metav1.ListMeta `json:"metadata,omitempty"`
	Items           []KeepiqConnection `json:"items"`
}

// KeepiqSecretItem maps one field of one Keepiq secret to one key of the
// target Kubernetes Secret.
type KeepiqSecretItem struct {
	// Name is the exact Keepiq secret name.
	Name string `json:"name"`
	// Folder narrows the name to a slash-separated folder path.
	Folder string `json:"folder,omitempty"`
	// Field is key, login, or additionalFields.<name>.
	Field string `json:"field"`
	// TargetKey is the key in the target Kubernetes Secret.
	TargetKey string `json:"targetKey"`
}

// TargetRef names the Kubernetes Secret the operator writes.
type TargetRef struct {
	Name string `json:"name"`
}

// RestartTarget is a workload to roll when a value changes.
type RestartTarget struct {
	// Kind is Deployment or StatefulSet.
	Kind string `json:"kind"`
	Name string `json:"name"`
}

// KeepiqSecretSpec says which Keepiq values go into which Kubernetes Secret.
type KeepiqSecretSpec struct {
	// ConnectionRef names the KeepiqConnection in the same namespace.
	ConnectionRef LocalObjectReference `json:"connectionRef"`
	Target        TargetRef            `json:"target"`
	// RefreshInterval is how often each item is polled (default 60s, at
	// least 10s).
	RefreshInterval *metav1.Duration   `json:"refreshInterval,omitempty"`
	RestartTargets  []RestartTarget    `json:"restartTargets,omitempty"`
	Items           []KeepiqSecretItem `json:"items"`
}

// LocalObjectReference names an object in the same namespace.
type LocalObjectReference struct {
	Name string `json:"name"`
}

// ItemStatus records what the operator last saw for one item. It never holds
// a value.
type ItemStatus struct {
	Name         string       `json:"name"`
	Folder       string       `json:"folder,omitempty"`
	SecretID     string       `json:"secretId,omitempty"`
	ETag         string       `json:"etag,omitempty"`
	LeaseID      string       `json:"leaseId,omitempty"`
	LeaseExpires *metav1.Time `json:"leaseExpires,omitempty"`
}

// KeepiqSecretStatus is the observed state.
type KeepiqSecretStatus struct {
	ObservedGeneration int64              `json:"observedGeneration,omitempty"`
	Conditions         []metav1.Condition `json:"conditions,omitempty"`
	Items              []ItemStatus       `json:"items,omitempty"`
	// Checksum is a SHA-256 over the target Secret's data, so a change can be
	// detected without keeping any value.
	Checksum   string       `json:"checksum,omitempty"`
	LastSynced *metav1.Time `json:"lastSynced,omitempty"`
}

// KeepiqSecret syncs Keepiq application secrets into a Kubernetes Secret.
// +kubebuilder:object:root=true
// +kubebuilder:subresource:status
type KeepiqSecret struct {
	metav1.TypeMeta   `json:",inline"`
	metav1.ObjectMeta `json:"metadata,omitempty"`

	Spec   KeepiqSecretSpec   `json:"spec"`
	Status KeepiqSecretStatus `json:"status,omitempty"`
}

// KeepiqSecretList is a list of KeepiqSecret.
// +kubebuilder:object:root=true
type KeepiqSecretList struct {
	metav1.TypeMeta `json:",inline"`
	metav1.ListMeta `json:"metadata,omitempty"`
	Items           []KeepiqSecret `json:"items"`
}

func init() {
	SchemeBuilder.Register(&KeepiqConnection{}, &KeepiqConnectionList{}, &KeepiqSecret{}, &KeepiqSecretList{})
}
