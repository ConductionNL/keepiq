package rotate

import (
	"time"

	"github.com/ConductionNL/keepiq/integrations/runner/internal/config"
	"github.com/ConductionNL/keepiq/integrations/runner/internal/state"
)

// Due reports whether a rotation should run at now.
//
//   - Schedule: it runs when the cron schedule fired since the last run (or
//     since since, the runner's first start, so a fresh install does not
//     rotate everything at once).
//   - followExpiry: it runs once the secret's expiresAt is within the lead
//     time, and not again for the same expiry window. The write-back does not
//     move expiresAt, so the window is remembered as lastRotated.
func Due(rot config.Rotation, st state.RotationState, now, since time.Time, expiresAt string) (bool, string) {
	if rot.Schedule != "" {
		sched, err := config.CronParser.Parse(rot.Schedule)
		if err == nil {
			from := st.LastRun
			if from.IsZero() {
				from = since
			}
			if next := sched.Next(from); !next.After(now) {
				return true, "schedule"
			}
		}
	}
	if rot.FollowExpiry && expiresAt != "" {
		exp, err := time.Parse(time.RFC3339, expiresAt)
		if err == nil {
			windowStart := exp.Add(-rot.LeadTime.Duration)
			if !now.Before(windowStart) && st.LastRotated.Before(windowStart) {
				return true, "expiry"
			}
		}
	}
	return false, ""
}
