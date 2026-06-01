<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  MigrationBanner — dashboard banner surfaced when the user has a suite
  key migration in progress or one that completed with errors. Rendered
  as a `kind: "widget"` (widgetKey `doriath-migration-banner`) on the
  dashboard body slot. Reads migration_status from the shared dashboard
  summary store; renders nothing when there is no active migration.

  @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
-->
<template>
	<NcNoteCard
		v-if="migration"
		:type="noteType"
		class="doriath-migration-banner"
		@click="onClick">
		{{ message }}
	</NcNoteCard>
</template>

<script>
import { mapState } from 'pinia'
import { NcNoteCard } from '@nextcloud/vue'
import { translate as ncT } from '@nextcloud/l10n'
import { useDashboardStore } from '../../store/modules/dashboard.js'

export default {
	name: 'MigrationBanner',

	components: { NcNoteCard },

	computed: {
		...mapState(useDashboardStore, ['summary']),

		/**
		 * The migration status block from the summary, or null.
		 *
		 * @return {object|null} Migration status.
		 * @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
		 */
		migration() {
			return this.summary?.migration_status ?? null
		},

		/**
		 * NcNoteCard type: warning while in progress, error when the
		 * migration completed with errors.
		 *
		 * @return {string} NcNoteCard type.
		 * @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
		 */
		noteType() {
			return this.migration?.status === 'completed_with_errors' ? 'error' : 'warning'
		},

		/**
		 * Human-readable, translated banner message derived from the
		 * migration status and its (optional) remaining / failed counts.
		 *
		 * @return {string} The banner message.
		 * @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
		 */
		message() {
			if (this.migration?.status === 'completed_with_errors') {
				const failed = this.migration?.failed_count ?? 0
				return ncT('doriath', '{count} secrets failed migration — retry required', { count: failed })
			}
			const remaining = this.migration?.remaining_count
			if (remaining !== undefined && remaining !== null) {
				return ncT('doriath', 'Key migration in progress — {count} secrets remaining', { count: remaining })
			}
			return ncT('doriath', 'Key migration in progress')
		},
	},

	methods: {
		/**
		 * Navigate to the migration resume screen when the banner is
		 * clicked. Guarded so it is a no-op if no migration route exists.
		 *
		 * @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
		 */
		onClick() {
			if (this.$router && this.$router.hasRoute && this.$router.hasRoute('Migration')) {
				this.$router.push({ name: 'Migration' })
			}
		},
	},
}
</script>

<style scoped>
.doriath-migration-banner {
	cursor: pointer;
}
</style>
