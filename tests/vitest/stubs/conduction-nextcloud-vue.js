/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test alias stub for `@conduction/nextcloud-vue`.
 *
 * The published library ships a fat bundle (its own @nextcloud/vue copy,
 * apexcharts, leaflet, codemirror, …) that neither resolves nor renders under
 * vitest's jsdom env. Views under test import only a handful of primitives
 * (CnIndexPage, CnFolderSidebar, CnStatusBadge) and assert on emitted events +
 * the slots they pass down — not on the library's real markup. So we swap the
 * whole package for minimal Vue 3 render-function stubs.
 *
 * Rules (same as the @nextcloud/vue stub):
 *  - No template strings (vite ships the runtime-only build).
 *  - Forward the events the views listen to via `this.$emit`.
 *  - Render the slots the views provide so tests can probe the rows.
 *
 * ⚠️ Vue 3 conversion notes:
 *  - `render(h)` gets NO `h`; import it from `vue`.
 *  - vnode data is FLAT — `{ attrs: {...}, on: { click } }` becomes
 *    `{ 'data-testid': ..., onClick }`.
 *  - `this.$scopedSlots` was REMOVED; all slots (scoped or not) live on
 *    `this.$slots` and are functions. Reading `$scopedSlots` yields
 *    `undefined`, so a row would silently render with no badges and the
 *    assertion would quietly stop covering anything.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#15.2
 */

import { h } from 'vue'

/**
 * CnIndexPage stub — renders a primary add button (emits `add`), a per-object
 * list rendering the `#list-item` slot (or a `.cn-object-row` with the
 * `#row-badges` slot), and an empty-state div when there are no objects.
 */
const CnIndexPage = {
	name: 'CnIndexPage',
	props: [
		'objects',
		'schema',
		'listConfig',
		'loading',
		'pagination',
		'viewMode',
		'availableViewModes',
		'selectable',
		'addLabel',
		'addIcon',
		'inlineSearch',
		'searchValue',
		'searchPlaceholder',
		'showSortSelect',
		'sortSelectOptions',
		'sortSelectValue',
		'listLabel',
		'rowKey',
		'emptyText',
	],
	emits: ['add', 'row-click'],
	render() {
		const objects = this.objects || []
		const children = [
			h(
				'button',
				{
					'data-testid': 'cn-cta-primary',
					type: 'button',
					onClick: () => this.$emit('add'),
				},
				this.addLabel,
			),
		]

		if (objects.length === 0) {
			children.push(
				h('div', { class: 'cn-index-page__empty' }, this.emptyText),
			)
		} else {
			const rows = objects.map((object) => {
				const listItem = this.$slots['list-item']
				if (listItem) {
					return h('div', { class: 'cn-index-page__item' }, [
						listItem({ object }),
					])
				}
				const badges = this.$slots['row-badges']
				return h(
					'div',
					{
						class: 'cn-object-row',
						onClick: () => this.$emit('row-click', object),
					},
					badges ? [badges({ object })] : [],
				)
			})
			children.push(h('div', { class: 'cn-index-page__rows' }, rows))
		}

		return h(
			'div',
			{ class: 'cn-index-page', 'data-testid': 'cn-index-page' },
			children,
		)
	},
}

/**
 * CnFolderSidebar stub — an "All" button (emits `select` null) and an optional
 * New-folder button (emits `create`).
 */
const CnFolderSidebar = {
	name: 'CnFolderSidebar',
	props: [
		'folders',
		'selectedId',
		'allLabel',
		'allowCreate',
		'createLabel',
		'source',
		'objects',
		'groupBy',
	],
	emits: ['select', 'create'],
	render() {
		const children = [
			h(
				'button',
				{
					class: 'cn-folder-sidebar__all',
					onClick: () => this.$emit('select', null),
				},
				this.allLabel,
			),
		]
		if (this.allowCreate) {
			children.push(
				h(
					'button',
					{
						class: 'cn-folder-sidebar__new',
						onClick: () =>
							this.$emit('create', { parentId: this.selectedId }),
					},
					this.createLabel,
				),
			)
		}
		return h('div', { class: 'cn-folder-sidebar' }, children)
	},
}

/** CnStatusBadge stub — a pill span carrying the label. */
const CnStatusBadge = {
	name: 'CnStatusBadge',
	props: ['label', 'variant', 'size', 'solid', 'colorMap'],
	render() {
		return h(
			'span',
			{ class: 'cn-status-badge', 'data-variant': this.variant },
			this.label,
		)
	},
}

export { CnIndexPage, CnFolderSidebar, CnStatusBadge }
