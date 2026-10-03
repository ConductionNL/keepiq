<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Create or edit a global item type and its fields (admin-18). Opened from
  the Item types section of the admin settings. A field has a label, a kind
  and a required flag; its key is derived from the label when it is added
  and never changes after, so saved values keep their meaning.

  @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
-->
<template>
	<NcDialog
		:name="type ? t('keepiq', 'Edit item type') : t('keepiq', 'New item type')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="type-editor">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				v-model="label"
				:label="t('keepiq', 'Name')"
				:required="true"
				data-testid="type-editor-label" />

			<fieldset class="type-editor__fields">
				<legend>{{ t('keepiq', 'Fields') }}</legend>
				<div
					v-for="(field, index) in fields"
					:key="field.uid"
					class="type-editor__row"
					:data-testid="`type-editor-field-${index}`">
					<NcTextField
						v-model="field.label"
						class="type-editor__label"
						:label="t('keepiq', 'Field name')" />
					<NcSelect
						v-model="field.kind"
						class="type-editor__kind"
						:options="kindOptions"
						:inputLabel="t('keepiq', 'Type')"
						label="label"
						:reduce="(opt) => opt.value"
						:clearable="false" />
					<NcCheckboxRadioSwitch v-model="field.required">
						{{ t('keepiq', 'Required') }}
					</NcCheckboxRadioSwitch>
					<NcButton
						variant="tertiary-no-background"
						:aria-label="t('keepiq', 'Move up')"
						:title="t('keepiq', 'Move up')"
						:disabled="index === 0"
						@click="move(index, -1)">
						<template #icon>
							<ArrowUp :size="20" />
						</template>
					</NcButton>
					<NcButton
						variant="tertiary-no-background"
						:aria-label="t('keepiq', 'Remove this field')"
						:title="t('keepiq', 'Remove this field')"
						@click="remove(index)">
						<template #icon>
							<Close :size="20" />
						</template>
					</NcButton>
				</div>
				<NcButton
					variant="secondary"
					:disabled="fields.length >= maxFields"
					data-testid="type-editor-add-field"
					@click="addField">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('keepiq', 'Add a field') }}
				</NcButton>
			</fieldset>

			<NcNoteCard
				v-if="listError"
				type="warning"
				data-testid="type-editor-invalid">
				{{ listError }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSave"
				data-testid="type-editor-save"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				{{ t('keepiq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import {
	fieldKeyFor,
	fieldListError,
	MAX_FIELDS,
	typedFieldsOf,
} from '../utils/typedFields.js'

let uidCounter = 0

export default {
	name: 'SecretTypeEditorDialog',

	components: {
		ArrowUp,
		Close,
		ContentSave,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		Plus,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		/** The type to edit, or null to create one. */
		type: {
			type: Object,
			default: null,
		},
	},

	emits: ['update:open', 'saved'],

	data() {
		return {
			label: this.type?.label || '',
			fields: typedFieldsOf(this.type).map((f) => ({
				...f,
				required: Boolean(f.required),
				uid: ++uidCounter,
			})),

			saving: false,
			error: '',
			maxFields: MAX_FIELDS,
		}
	},

	computed: {
		/**
		 * The four field kinds, labelled.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		kindOptions() {
			return [
				{ value: 'text', label: t('keepiq', 'Text') },
				{ value: 'hidden', label: t('keepiq', 'Hidden') },
				{ value: 'url', label: t('keepiq', 'Web address') },
				{ value: 'email', label: t('keepiq', 'Email') },
			]
		},

		/**
		 * Why the field list cannot be saved yet.
		 *
		 * @return {string} The problem, or empty.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		listError() {
			return fieldListError(this.fields, t)
		},

		/**
		 * @return {boolean} Whether the type can be saved.
		 * @spec exclude Form-enablement guard; no domain behaviour.
		 */
		canSave() {
			return !this.saving && this.label.trim() !== '' && this.listError === ''
		},
	},

	methods: {
		t,

		/**
		 * @param {boolean} value The requested open state.
		 * @return {void}
		 * @spec exclude Dialog plumbing.
		 */
		onUpdateOpen(value) {
			this.$emit('update:open', value)
		},

		/**
		 * Append an empty text field.
		 *
		 * @return {void}
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		addField() {
			this.fields.push({
				key: '',
				label: '',
				kind: 'text',
				required: false,
				uid: ++uidCounter,
			})
		},

		/**
		 * Move a field one place up or down.
		 *
		 * @param {number} index The field position.
		 * @param {number} step -1 or 1.
		 * @return {void}
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		move(index, step) {
			const target = index + step
			if (target < 0 || target >= this.fields.length) {
				return
			}
			const [field] = this.fields.splice(index, 1)
			this.fields.splice(target, 0, field)
		},

		/**
		 * Remove a field.
		 *
		 * @param {number} index The field position.
		 * @return {void}
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		remove(index) {
			this.fields.splice(index, 1)
		},

		/**
		 * The field list as the server takes it; new fields get a key from
		 * their label, existing fields keep theirs.
		 *
		 * @return {Array<{key: string, label: string, kind: string, required: boolean}>} The fields.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		payloadFields() {
			const taken = this.fields.map((f) => f.key).filter((k) => k !== '')
			return this.fields.map((f) => {
				let key = f.key
				if (key === '') {
					key = fieldKeyFor(f.label, taken)
					taken.push(key)
				}
				return {
					key,
					label: f.label.trim(),
					kind: f.kind,
					required: Boolean(f.required),
				}
			})
		},

		/**
		 * Create or update the type through the type store.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		async save() {
			if (!this.canSave) {
				return
			}
			this.saving = true
			this.error = ''
			const store = useSecretTypeStore()
			const fields = this.payloadFields()
			try {
				const saved = this.type
					? await store.updateType(this.type.id, this.label.trim(), fields)
					: await store.createType({
							name: fieldKeyFor(
								this.label,
								store.types.map((ty) => ty.name),
							),
							label: this.label.trim(),
							scope: 'global',
							fields,
						})
				this.$emit('saved', saved)
				this.onUpdateOpen(false)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t('keepiq', 'Could not save the item type.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.type-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.type-editor__fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: none;
	padding: 0;
}

.type-editor__fields legend {
	font-weight: bold;
	margin-bottom: 4px;
}

.type-editor__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
}

.type-editor__label {
	flex: 2 1 160px;
}

.type-editor__kind {
	flex: 1 1 120px;
}
</style>
