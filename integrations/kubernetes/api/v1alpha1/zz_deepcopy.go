// Hand-written deep copies (the shape controller-gen would generate).

package v1alpha1

import (
	metav1 "k8s.io/apimachinery/pkg/apis/meta/v1"
	"k8s.io/apimachinery/pkg/runtime"
)

func (in *KeepiqConnection) DeepCopyInto(out *KeepiqConnection) {
	*out = *in
	out.TypeMeta = in.TypeMeta
	in.ObjectMeta.DeepCopyInto(&out.ObjectMeta)
	out.Spec = in.Spec
	if in.Spec.CertificateSecretRef != nil {
		ref := *in.Spec.CertificateSecretRef
		out.Spec.CertificateSecretRef = &ref
	}
}

func (in *KeepiqConnection) DeepCopy() *KeepiqConnection {
	if in == nil {
		return nil
	}
	out := new(KeepiqConnection)
	in.DeepCopyInto(out)
	return out
}

func (in *KeepiqConnection) DeepCopyObject() runtime.Object { return in.DeepCopy() }

func (in *KeepiqConnectionList) DeepCopyInto(out *KeepiqConnectionList) {
	*out = *in
	out.TypeMeta = in.TypeMeta
	in.ListMeta.DeepCopyInto(&out.ListMeta)
	if in.Items != nil {
		out.Items = make([]KeepiqConnection, len(in.Items))
		for i := range in.Items {
			in.Items[i].DeepCopyInto(&out.Items[i])
		}
	}
}

func (in *KeepiqConnectionList) DeepCopy() *KeepiqConnectionList {
	if in == nil {
		return nil
	}
	out := new(KeepiqConnectionList)
	in.DeepCopyInto(out)
	return out
}

func (in *KeepiqConnectionList) DeepCopyObject() runtime.Object { return in.DeepCopy() }

func (in *KeepiqSecretSpec) DeepCopyInto(out *KeepiqSecretSpec) {
	*out = *in
	if in.RefreshInterval != nil {
		d := *in.RefreshInterval
		out.RefreshInterval = &d
	}
	if in.RestartTargets != nil {
		out.RestartTargets = append([]RestartTarget(nil), in.RestartTargets...)
	}
	if in.Items != nil {
		out.Items = append([]KeepiqSecretItem(nil), in.Items...)
	}
}

func (in *KeepiqSecretStatus) DeepCopyInto(out *KeepiqSecretStatus) {
	*out = *in
	if in.Conditions != nil {
		out.Conditions = make([]metav1.Condition, len(in.Conditions))
		for i := range in.Conditions {
			in.Conditions[i].DeepCopyInto(&out.Conditions[i])
		}
	}
	if in.Items != nil {
		out.Items = make([]ItemStatus, len(in.Items))
		for i := range in.Items {
			out.Items[i] = in.Items[i]
			if in.Items[i].LeaseExpires != nil {
				out.Items[i].LeaseExpires = in.Items[i].LeaseExpires.DeepCopy()
			}
		}
	}
	if in.LastSynced != nil {
		out.LastSynced = in.LastSynced.DeepCopy()
	}
}

func (in *KeepiqSecret) DeepCopyInto(out *KeepiqSecret) {
	*out = *in
	out.TypeMeta = in.TypeMeta
	in.ObjectMeta.DeepCopyInto(&out.ObjectMeta)
	in.Spec.DeepCopyInto(&out.Spec)
	in.Status.DeepCopyInto(&out.Status)
}

func (in *KeepiqSecret) DeepCopy() *KeepiqSecret {
	if in == nil {
		return nil
	}
	out := new(KeepiqSecret)
	in.DeepCopyInto(out)
	return out
}

func (in *KeepiqSecret) DeepCopyObject() runtime.Object { return in.DeepCopy() }

func (in *KeepiqSecretList) DeepCopyInto(out *KeepiqSecretList) {
	*out = *in
	out.TypeMeta = in.TypeMeta
	in.ListMeta.DeepCopyInto(&out.ListMeta)
	if in.Items != nil {
		out.Items = make([]KeepiqSecret, len(in.Items))
		for i := range in.Items {
			in.Items[i].DeepCopyInto(&out.Items[i])
		}
	}
}

func (in *KeepiqSecretList) DeepCopy() *KeepiqSecretList {
	if in == nil {
		return nil
	}
	out := new(KeepiqSecretList)
	in.DeepCopyInto(out)
	return out
}

func (in *KeepiqSecretList) DeepCopyObject() runtime.Object { return in.DeepCopy() }
