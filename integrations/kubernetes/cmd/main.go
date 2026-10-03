// Command keepiq-operator runs the Keepiq Kubernetes operator.
package main

import (
	"flag"
	"os"

	clientgoscheme "k8s.io/client-go/kubernetes/scheme"
	"k8s.io/apimachinery/pkg/runtime"
	ctrl "sigs.k8s.io/controller-runtime"
	"sigs.k8s.io/controller-runtime/pkg/cache"
	"sigs.k8s.io/controller-runtime/pkg/healthz"
	"sigs.k8s.io/controller-runtime/pkg/log/zap"
	metricsserver "sigs.k8s.io/controller-runtime/pkg/metrics/server"

	v1 "github.com/ConductionNL/keepiq/integrations/kubernetes/api/v1alpha1"
	"github.com/ConductionNL/keepiq/integrations/kubernetes/internal/controller"
)

var version = "dev"

func main() {
	var metricsAddr, probeAddr, watchNamespace string
	var leaderElect bool
	flag.StringVar(&metricsAddr, "metrics-bind-address", ":8080", "Address the metrics endpoint binds to; 0 turns it off.")
	flag.StringVar(&probeAddr, "health-probe-bind-address", ":8081", "Address the health probes bind to.")
	flag.StringVar(&watchNamespace, "watch-namespace", os.Getenv("WATCH_NAMESPACE"), "Namespace to watch; empty watches every namespace (needs the cluster-wide RBAC).")
	flag.BoolVar(&leaderElect, "leader-elect", false, "Use leader election, for more than one replica.")
	opts := zap.Options{}
	opts.BindFlags(flag.CommandLine)
	flag.Parse()
	ctrl.SetLogger(zap.New(zap.UseFlagOptions(&opts)))
	setup := ctrl.Log.WithName("setup")

	scheme := runtime.NewScheme()
	if err := clientgoscheme.AddToScheme(scheme); err != nil {
		setup.Error(err, "scheme")
		os.Exit(1)
	}
	if err := v1.AddToScheme(scheme); err != nil {
		setup.Error(err, "scheme")
		os.Exit(1)
	}

	options := ctrl.Options{
		Scheme:                 scheme,
		Metrics:                metricsserver.Options{BindAddress: metricsAddr},
		HealthProbeBindAddress: probeAddr,
		LeaderElection:         leaderElect,
		LeaderElectionID:       "keepiq-operator.keepiq.conduction.nl",
	}
	if watchNamespace != "" {
		options.Cache = cache.Options{DefaultNamespaces: map[string]cache.Config{watchNamespace: {}}}
	}
	mgr, err := ctrl.NewManager(ctrl.GetConfigOrDie(), options)
	if err != nil {
		setup.Error(err, "manager")
		os.Exit(1)
	}
	if err := (&controller.KeepiqSecretReconciler{
		Client:   mgr.GetClient(),
		Scheme:   mgr.GetScheme(),
		Recorder: mgr.GetEventRecorderFor("keepiq-operator"),
	}).SetupWithManager(mgr); err != nil {
		setup.Error(err, "controller")
		os.Exit(1)
	}
	_ = mgr.AddHealthzCheck("healthz", healthz.Ping)
	_ = mgr.AddReadyzCheck("readyz", healthz.Ping)
	setup.Info("starting keepiq-operator", "version", version, "namespace", watchNamespace)
	if err := mgr.Start(ctrl.SetupSignalHandler()); err != nil {
		setup.Error(err, "manager stopped")
		os.Exit(1)
	}
}
