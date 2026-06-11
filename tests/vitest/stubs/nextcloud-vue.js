/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test alias stub for the entire `@nextcloud/vue` design-system package.
 *
 * The published `@nextcloud/vue` ships a fat ESM bundle that pulls in
 * `@nextcloud/capabilities` + `@nextcloud/initial-state` + an `imagePath`
 * helper from the NC window globals. None of that is set up under
 * vitest's jsdom env, so importing `@nextcloud/vue` blows up with
 * `TypeError: imagePath is not a function`.
 *
 * Tests that mount components which import from `@nextcloud/vue` don't
 * care about its rendered markup — they assert on emitted events and
 * surrounding markup. So we replace the whole package with a flat dict
 * of minimal Vue 2 stub components. The stubs:
 *
 *  - DON'T use template strings (the runtime-only Vue 2 build vite
 *    ships doesn't include the template compiler).
 *  - DO forward all standard events (`click`, `update:open`, `input`)
 *    via `this.$emit` from a simple `render(h) {}` function.
 *  - DO render their default slot inside a div so the test can probe
 *    nested markup.
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#13
 */

const passthrough = (name) => ({
	name,
	props: ['value', 'open', 'type', 'size', 'disabled', 'inputLabel', 'options', 'reduce', 'clearable', 'ariaLabel', 'name'],
	render(h) {
		return h('div', { attrs: { 'data-stub': name } }, [
			this.$slots.default,
			this.$slots.actions,
			this.$slots.icon,
		])
	},
})

const buttonLike = (name) => ({
	name,
	props: ['type', 'disabled', 'ariaLabel'],
	render(h) {
		return h(
			'button',
			{
				attrs: { disabled: this.disabled, 'data-stub': name, 'aria-label': this.ariaLabel },
				on: { click: (e) => this.$emit('click', e) },
			},
			[this.$slots.icon, this.$slots.default],
		)
	},
})

export const NcDialog = {
	name: 'NcDialog',
	props: ['name', 'open', 'size'],
	render(h) {
		return h('div', { attrs: { 'data-stub': 'NcDialog', 'data-open': String(!!this.open) } }, [
			this.$slots.default,
			this.$slots.actions,
		])
	},
}

export const NcModal = passthrough('NcModal')
export const NcButton = buttonLike('NcButton')
export const NcSelect = passthrough('NcSelect')
export const NcNoteCard = passthrough('NcNoteCard')
export const NcLoadingIcon = {
	name: 'NcLoadingIcon',
	props: ['size'],
	render(h) {
		return h('span', { attrs: { 'data-stub': 'NcLoadingIcon' } })
	},
}
export const NcInputField = passthrough('NcInputField')
export const NcTextField = passthrough('NcTextField')
export const NcEmptyContent = passthrough('NcEmptyContent')
export const NcAppNavigation = passthrough('NcAppNavigation')
export const NcAppContent = passthrough('NcAppContent')
export const NcCheckboxRadioSwitch = passthrough('NcCheckboxRadioSwitch')

export default {
	NcDialog,
	NcModal,
	NcButton,
	NcSelect,
	NcNoteCard,
	NcLoadingIcon,
	NcInputField,
	NcTextField,
	NcEmptyContent,
	NcAppNavigation,
	NcAppContent,
	NcCheckboxRadioSwitch,
}
