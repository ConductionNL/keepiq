<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Recent activity widget — sample/placeholder list rendered on the
  doriath dashboard. Registered as `kind: "widget"` under the widgetKey
  `doriath-recent-activity` (see src/registry.js); referenced from the
  Dashboard page's `widgets[]` array in src/manifest.json.

  Replace the placeholder list with a real activity feed once an
  underlying activity schema lands.
-->
<template>
	<div class="doriath-widget">
		<h3 v-if="title" class="doriath-widget__title">
			{{ title }}
		</h3>
		<ul class="doriath-widget__list">
			<li>{{ t('doriath', 'Placeholder: user opened a record') }}</li>
			<li>{{ t('doriath', 'Placeholder: status changed to Review') }}</li>
			<li>{{ t('doriath', 'Placeholder: comment added') }}</li>
		</ul>
	</div>
</template>

<script>
export default {
	name: 'RecentActivityWidget',

	props: {
		/**
		 * Optional widget title. Sourced from `widgets[].props.title` in the
		 * manifest. Rendered above the placeholder list when present.
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

.doriath-widget__list {
	margin: 0;
	padding-left: 1.2em;
	line-height: 1.6;
}
</style>
