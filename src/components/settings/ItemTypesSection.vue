<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin settings: the item types an administrator defines for everyone,
  with the fields each carries (admin-18). Built-in types are not listed:
  they keep their own forms and cannot be changed.

  @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Item types')"
		:description="
			t(
				'keepiq',
				'Item types you define here appear in everyone’s New secret dialog, with the fields you choose.',
			)
		">
		<div class="item-types" data-testid="item-types-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<p v-if="globalTypes.length === 0" class="item-types__empty">
				{{ t('keepiq', 'No item types defined yet.') }}
			</p>
			<ul v-else class="item-types__list">
				<li
					v-for="type in globalTypes"
					:key="type.id"
					class="item-types__item"
					:data-testid="`item-type-${type.name}`">
					<span class="item-types__name">{{ type.label }}</span>
					<span class="item-types__count">
						{{
							t('keepiq', 'Fields: {count}', {
								count: fieldCount(type),
							})
						}}
					</span>
					<NcButton variant="tertiary" @click="editing = type">
						{{ t('keepiq', 'Edit') }}
					</NcButton>
					<NcButton variant="tertiary" @click="deleting = type">
						{{ t('keepiq', 'Delete') }}
					</NcButton>
				</li>
			</ul>
			<NcButton
				variant="secondary"
				data-testid="item-types-new"
				@click="creating = true">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('keepiq', 'New item type') }}
			</NcButton>
		</div>

		<SecretTypeEditorDialog
			v-if="creating"
			:open="creating"
			@update:open="creating = $event" />
		<SecretTypeEditorDialog
			v-if="editing"
			:key="editing.id"
			:open="editing !== null"
			:type="editing"
			@update:open="!$event && (editing = null)" />
		<SecretTypeDeleteDialog
			v-if="deleting"
			:open="deleting !== null"
			:type="deleting"
			@update:open="!$event && (deleting = null)" />
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import SecretTypeDeleteDialog from '../../dialogs/SecretTypeDeleteDialog.vue'
import SecretTypeEditorDialog from '../../dialogs/SecretTypeEditorDialog.vue'
import { useSecretTypeStore } from '../../store/modules/secretType.js'
import { typedFieldsOf } from '../../utils/typedFields.js'

export default {
	name: 'ItemTypesSection',

	components: {
		CnSettingsSection,
		NcButton,
		NcNoteCard,
		Plus,
		SecretTypeDeleteDialog,
		SecretTypeEditorDialog,
	},

	data() {
		return {
			creating: false,
			editing: null,
			deleting: null,
			error: '',
		}
	},

	computed: {
		/**
		 * The global types, the ones an administrator manages here.
		 *
		 * @return {Array<object>} The types.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		globalTypes() {
			return useSecretTypeStore().types.filter((ty) => ty.scope === 'global')
		},
	},

	/**
	 * Read the types.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
	 */
	async mounted() {
		try {
			await useSecretTypeStore().fetchTypes()
		} catch {
			this.error = t('keepiq', 'Could not load the item types.')
		}
	},

	methods: {
		t,

		/**
		 * @param {object} type A type.
		 * @return {number} How many fields it has.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		fieldCount(type) {
			return typedFieldsOf(type).length
		},
	},
}
</script>

<style scoped>
.item-types {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-start;
}

.item-types__empty,
.item-types__count {
	color: var(--color-text-maxcontrast);
}

.item-types__list {
	width: 100%;
}

.item-types__item {
	display: flex;
	align-items: center;
	gap: 8px;
}

.item-types__name {
	font-weight: bold;
}
</style>
