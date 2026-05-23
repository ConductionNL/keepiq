<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Quick actions widget — sample/placeholder hint rendered on the doriath
  dashboard. Registered as `kind: "widget"` under the widgetKey
  `doriath-quick-actions` (see src/registry.js); referenced from the
  Dashboard page's `widgets[]` array in src/manifest.json.

  Replace the hint text with real action buttons once the create/list
  routes for doriath's domain objects exist.
-->
<template>
	<div class="doriath-widget">
		<h3 v-if="title" class="doriath-widget__title">
			{{ title }}
		</h3>
		<p class="doriath-widget__hint">
			{{ t('doriath', 'Wire buttons here to create records, open lists, or deep links. Use the sidebar for Settings and Documentation.') }}
		</p>
	</div>
</template>

<script>
export default {
	name: 'QuickActionsWidget',

	props: {
		/**
		 * Optional widget title. Sourced from `widgets[].props.title` in the
		 * manifest. Rendered above the hint paragraph when present.
		 */
		title: {
			type: String,
			default: '',
		},
	},
}
</script>

<style scoped>
.doriath-widget {
	padding: 1rem;
}

.doriath-widget__title {
	margin: 0 0 0.5rem 0;
	font-size: 1rem;
	font-weight: bold;
}

.doriath-widget__hint {
	margin: 0;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}
</style>
