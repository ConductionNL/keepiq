<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Personal settings: the default item type a new secret starts as, and the
  view the secret list opens in (vault-20). Mounted in the user-settings
  dialog in App.vue; saves each choice as soon as it is picked.

  @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
-->
<template>
	<div class="defaults-section">
		<p class="defaults-section__hint">
			{{
				t(
					'keepiq',
					'New secrets start as this type, and your secret list opens in this view.',
				)
			}}
		</p>
		<div class="defaults-section__field">
			<NcSelect
				:modelValue="prefs.defaultSecretType"
				:options="typeOptions"
				:inputLabel="t('keepiq', 'Default item type')"
				label="label"
				:reduce="(opt) => opt.value"
				:clearable="false"
				data-testid="default-type-select"
				@update:modelValue="onTypeChange" />
		</div>
		<div class="defaults-section__field">
			<NcSelect
				:modelValue="prefs.defaultView"
				:options="viewOptions"
				:inputLabel="t('keepiq', 'Default view')"
				label="label"
				:reduce="(opt) => opt.value"
				:clearable="false"
				data-testid="default-view-select"
				@update:modelValue="onViewChange" />
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { useSecretTypeStore } from '../../store/modules/secretType.js'
import { useUserPreferencesStore } from '../../store/modules/userPreferences.js'
import { secretTypeLabel } from '../../utils/secretTypes.js'

export default {
	name: 'DefaultsSection',
	components: { NcSelect },

	computed: {
		/**
		 * The preferences store.
		 *
		 * @return {object} The store.
		 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
		 */
		prefs() {
			return useUserPreferencesStore()
		},

		/**
		 * Every secret type, by name, labelled in the user's language.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
		 */
		typeOptions() {
			return useSecretTypeStore().types.map((type) => ({
				value: type.name,
				label: secretTypeLabel(type),
			}))
		},

		/**
		 * The three views the secret list offers.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
		 */
		viewOptions() {
			return [
				{ value: 'list', label: t('keepiq', 'List') },
				{ value: 'cards', label: t('keepiq', 'Cards') },
				{ value: 'table', label: t('keepiq', 'Table') },
			]
		},
	},

	/**
	 * Read the saved defaults and the type catalogue.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
	 */
	async mounted() {
		const typeStore = useSecretTypeStore()
		await Promise.allSettled([
			this.prefs.ensureLoaded(),
			typeStore.types.length === 0 ? typeStore.fetchTypes() : null,
		])
	},

	methods: {
		t,

		/**
		 * Save a new default item type.
		 *
		 * @param {string} name The type name.
		 * @return {Promise<void>}
		 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
		 */
		async onTypeChange(name) {
			await this.saveDefault({ defaultSecretType: name })
		},

		/**
		 * Save a new default view.
		 *
		 * @param {string} view list, cards or table.
		 * @return {Promise<void>}
		 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
		 */
		async onViewChange(view) {
			await this.saveDefault({ defaultView: view })
		},

		/**
		 * Save through the store; say so when it fails.
		 *
		 * @param {object} changes The changed default.
		 * @return {Promise<void>}
		 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
		 */
		async saveDefault(changes) {
			try {
				await this.prefs.save(changes)
			} catch {
				showError(t('keepiq', 'Could not save your default'))
			}
		},
	},
}
</script>

<style scoped>
.defaults-section__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.defaults-section__field {
	margin-bottom: 12px;
}
</style>
