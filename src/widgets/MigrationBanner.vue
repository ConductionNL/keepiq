<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Migration banner shown when an EncryptionSuite compromise-recovery
  migration is currently in_progress or finished with errors. Renders
  a plain styled banner (no lib dependency) so the spec/component test
  can mount without the nextcloud-vue stub.

  @spec openspec/changes/implement-dashboard-settings/tasks.md#task-3.2
-->
<template>
	<aside
		v-if="status === 'in_progress' || status === 'completed_with_errors'"
		class="doriath-migration-banner"
		:class="[`doriath-migration-banner--${variant}`]"
		role="status"
		data-testid="migration-banner"
		@click="$emit('navigate')">
		<strong class="doriath-migration-banner__title">
			{{ title }}
		</strong>
		<span class="doriath-migration-banner__detail">
			{{ detail }}
		</span>
	</aside>
</template>

<script>
export default {
	name: 'MigrationBanner',
	props: {
		status: {
			type: String,
			default: null,
		},
		remaining: {
			type: [Number, String],
			default: 0,
		},
		failed: {
			type: [Number, String],
			default: 0,
		},
	},
	emits: ['navigate'],
	computed: {
		variant() {
			return this.status === 'completed_with_errors' ? 'error' : 'warning'
		},
		title() {
			if (this.status === 'completed_with_errors') {
				return t('doriath', 'Compromise recovery completed with errors')
			}
			return t('doriath', 'Compromise recovery in progress')
		},
		detail() {
			if (this.status === 'completed_with_errors') {
				return t('doriath', '{count} suite(s) failed to migrate. Open the migration screen for details.', { count: this.failed })
			}
			return t('doriath', '{count} suite(s) remaining. Open the migration screen for live progress.', { count: this.remaining })
		},
	},
}
</script>

<style scoped>
.doriath-migration-banner {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 12px 16px;
	border-radius: var(--border-radius-large, 12px);
	cursor: pointer;
	border: 1px solid var(--color-border, #ddd);
}
.doriath-migration-banner--warning {
	background-color: var(--color-warning-rest, #fdf6e3);
	border-color: var(--color-warning, #c08410);
}
.doriath-migration-banner--error {
	background-color: var(--color-error-rest, #f8d7d4);
	border-color: var(--color-error, #e9322d);
}
.doriath-migration-banner__title {
	font-weight: 600;
}
.doriath-migration-banner__detail {
	font-size: 13px;
}
</style>
