// Package v1alpha1 holds the keepiq.conduction.nl/v1alpha1 resources:
// KeepiqConnection and KeepiqSecret.
// +kubebuilder:object:generate=true
// +groupName=keepiq.conduction.nl
package v1alpha1

import (
	"k8s.io/apimachinery/pkg/runtime/schema"
	"sigs.k8s.io/controller-runtime/pkg/scheme"
)

var (
	// GroupVersion is the API group and version of these resources.
	GroupVersion = schema.GroupVersion{Group: "keepiq.conduction.nl", Version: "v1alpha1"}

	// SchemeBuilder registers the types with a scheme.
	SchemeBuilder = &scheme.Builder{GroupVersion: GroupVersion}

	// AddToScheme adds the types to a scheme.
	AddToScheme = SchemeBuilder.AddToScheme
)
