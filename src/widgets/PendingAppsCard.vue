<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin-only dashboard card showing the number of applications waiting
  for approval. Clicking it emits a `navigate` event so the parent view
  can route the user into the admin approval queue without coupling this
  widget to the router.

  @spec openspec/changes/implement-dashboard-settings/tasks.md#task-3.3
-->
<template>
	<article
		v-if="count > 0"
		class="doriath-pending-apps-card"
		role="button"
		tabindex="0"
		data-testid="pending-apps-card"
		@click="$emit('navigate')"
		@keydown.enter="$emit('navigate')">
		<span class="doriath-pending-apps-card__count" data-testid="pending-apps-count">
			{{ count }}
		</span>
		<span class="doriath-pending-apps-card__title">
			{{ t('doriath', 'Application(s) awaiting approval') }}
		</span>
		<span class="doriath-pending-apps-card__cta">
			{{ t('doriath', 'Open the approval queue') }} →
		</span>
	</article>
</template>

<script>
export default {
	name: 'PendingAppsCard',
	props: {
		count: {
			type: [Number, String],
			required: true,
		},
	},
	emits: ['navigate'],
}
</script>

<style scoped>
.doriath-pending-apps-card {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 16px;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-warning-rest, #fdf6e3);
	border: 1px solid var(--color-warning, #c08410);
	cursor: pointer;
}
.doriath-pending-apps-card__count {
	font-size: 32px;
	font-weight: 700;
	line-height: 1;
}
.doriath-pending-apps-card__title {
	font-size: 14px;
	font-weight: 600;
}
.doriath-pending-apps-card__cta {
	font-size: 12px;
}
</style>
