<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  StatsBlockWidget — sample/placeholder KPI card rendered on the doriath
  dashboard. Registered as `kind: "widget"` under the widgetKey
  `stats-block` (see src/registry.js); referenced from the Dashboard
  page's `widgets[]` array in src/manifest.json (4 instances).

  The library ships `CnStatsBlockWidget` for the same widgetKey, but
  it requires a `dataSource` block (manifest shorthand or raw GraphQL)
  pointing at a real OpenRegister schema to resolve the count. Doriath
  has no domain schemas registered yet (encryption suites live in
  doriath's own DB tables, not OR), so until those land we render
  static placeholder counts via this lightweight local widget.

  Replace with the lib's CnStatsBlockWidget once doriath's dashboard
  KPIs point at real OR-backed schemas and the manifest carries
  proper dataSource blocks.
-->
<template>
	<div class="doriath-stats-block" :class="iconClass">
		<div class="doriath-stats-block__inner" :class="`doriath-stats-block__inner--${variant}`">
			<div v-if="title && showTitle" class="doriath-stats-block__title">
				{{ title }}
			</div>
			<div class="doriath-stats-block__count">
				{{ count }}
			</div>
			<div v-if="countLabel" class="doriath-stats-block__label">
				{{ countLabel }}
			</div>
		</div>
	</div>
</template>

<script>
export default {
	name: 'StatsBlockWidget',

	props: {
		/**
		 * KPI tile title. Sourced from `widgets[].props.title` in the
		 * manifest. Rendered above the count when `showTitle` is true.
		 */
		title: {
			type: String,
			default: '',
		},

		/**
		 * Numeric value displayed in the centre of the tile. Static for
		 * the placeholder; comes from `widgets[].props.props.count` in
		 * the manifest's nested `props.props` block.
		 */
		count: {
			type: [Number, String],
			default: 0,
		},

		/**
		 * Optional label rendered below the count (e.g. "items", "open").
		 * Sourced from `widgets[].props.props.countLabel`.
		 */
		countLabel: {
			type: String,
			default: '',
		},

		/**
		 * Colour variant for the tile background. Maps to a CSS class
		 * (`primary`, `success`, `warning`, `error`, `default`).
		 * Sourced from `widgets[].props.props.variant`.
		 */
		variant: {
			type: String,
			default: 'default',
			validator: (value) => ['default', 'primary', 'success', 'warning', 'error'].includes(value),
		},

		/**
		 * Whether to render the `title` above the count. When false the
		 * tile shows only the count + countLabel. Sourced from
		 * `widgets[].props.showTitle`.
		 */
		showTitle: {
			type: Boolean,
			default: true,
		},

		/**
		 * Optional CSS class applied to the outermost wrapping `<div>`.
		 * Designed for Nextcloud core icon classes (`icon-folder`,
		 * `icon-calendar`, …). Sourced from `widgets[].props.iconClass`.
		 */
		iconClass: {
			type: String,
			default: '',
		},
	},
}
</script>

<style scoped>
.doriath-stats-block {
	width: 100%;
	height: 100%;
	display: flex;
}

.doriath-stats-block__inner {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 1rem;
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
}

.doriath-stats-block__inner--primary {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
}

.doriath-stats-block__inner--success {
	background-color: var(--color-success);
	color: var(--color-primary-element-text);
}

.doriath-stats-block__inner--warning {
	background-color: var(--color-warning);
	color: var(--color-primary-element-text);
}

.doriath-stats-block__inner--error {
	background-color: var(--color-error);
	color: var(--color-primary-element-text);
}

.doriath-stats-block__title {
	font-size: 0.9rem;
	font-weight: 600;
	margin-bottom: 0.25rem;
	text-align: center;
}

.doriath-stats-block__count {
	font-size: 2rem;
	font-weight: bold;
	line-height: 1;
}

.doriath-stats-block__label {
	margin-top: 0.25rem;
	font-size: 0.85rem;
	opacity: 0.85;
}
</style>
