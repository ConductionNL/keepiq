<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The named-member editor for a Secret's additional fields.

  Additional fields are named members inside the Secret's single encrypted
  `additional_fields` blob (ADR-003). Until this component existed an owner could
  SEE them on the detail view but never write one: the only writers were the
  write-for-application dialog, import, and an external recipient filling a secret
  request — so obtaining an additional field on your own secret meant asking a
  stranger to submit it.

  One component rather than the same rows written into both the create and the edit
  dialog. The naming rules live in `src/utils/additionalFields.js`, shared with the
  request dialog, so `key` / `login` / `url` cannot be accepted in one place and
  refused in another.

  Deliberately dumb about encryption: it edits a name/value list and emits it. The
  store owns turning that into one encrypted blob, which is the only place that
  knows the blob is the storage unit.

  Each member also carries a schema: a preset picks it, and Advanced shows its
  type, format, limits and Hidden switch. The value is entered the way the
  schema says and refused, with the reason, when it does not fit.

  @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
  @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
-->
<template>
	<section class="keepiq-additional-fields" data-testid="additional-fields-editor">
		<h4 class="keepiq-additional-fields__title">
			{{ t('keepiq', 'Additional fields') }}
		</h4>

		<p
			v-if="members.length === 0"
			class="keepiq-additional-fields__empty"
			data-testid="additional-fields-empty">
			{{ t('keepiq', 'No additional fields yet.') }}
		</p>

		<ul v-else class="keepiq-additional-fields__list">
			<li
				v-for="(member, index) in members"
				:key="index"
				class="keepiq-additional-fields__row"
				:data-testid="`additional-field-row-${index}`">
				<div class="keepiq-additional-fields__line">
					<NcTextField
						:modelValue="member.name"
						:label="t('keepiq', 'Field name')"
						:disabled="disabled"
						:data-testid="`additional-field-name-${index}`"
						@update:modelValue="onRename(index, $event)" />
					<NcSelect
						class="keepiq-additional-fields__kind"
						:modelValue="presetOf(member.schema)"
						:options="kindOptions(member.schema)"
						:inputLabel="t('keepiq', 'Kind')"
						:reduce="(opt) => opt.value"
						:clearable="false"
						:disabled="disabled"
						:data-testid="`additional-field-kind-${index}`"
						@update:modelValue="onPreset(index, $event)" />
					<NcButton
						variant="tertiary"
						:disabled="disabled"
						:aria-label="t('keepiq', 'Remove this field')"
						:data-testid="`additional-field-remove-${index}`"
						@click="onRemove(index)">
						{{ t('keepiq', 'Remove') }}
					</NcButton>
				</div>
				<SchemaValueInput
					:modelValue="member.value"
					:schema="schemaOf(member)"
					:label="t('keepiq', 'Value')"
					:error="valueError(member)"
					:disabled="disabled"
					:testid="`additional-field-value-${index}`"
					@update:modelValue="onRevalue(index, $event)" />
				<NcButton
					class="keepiq-additional-fields__advanced-toggle"
					variant="tertiary"
					:aria-expanded="isOpen(index) ? 'true' : 'false'"
					:data-testid="`additional-field-advanced-${index}`"
					@click="toggleAdvanced(index)">
					{{ t('keepiq', 'Advanced') }}
				</NcButton>
				<SchemaAdvanced
					v-if="isOpen(index)"
					:schema="schemaOf(member)"
					:disabled="disabled"
					:testid="`additional-field-schema-${index}`"
					@update:schema="onSchema(index, $event)" />
			</li>
		</ul>

		<div class="keepiq-additional-fields__add">
			<NcTextField
				v-model="newName"
				:label="t('keepiq', 'Add a field')"
				:placeholder="t('keepiq', 'e.g. client-id')"
				:disabled="disabled"
				data-testid="additional-field-new-name"
				@keyup.enter="onAdd" />
			<NcButton
				variant="secondary"
				:disabled="disabled"
				data-testid="additional-field-add"
				@click="onAdd">
				{{ t('keepiq', 'Add') }}
			</NcButton>
		</div>

		<!--
		  The reason is shown, not just the refusal. A name silently rejected right
		  after the user typed it reads as the dialog being broken, and the whole
		  point of refusing `key` is that the user learns it addresses a different
		  field rather than being a member with that label.
		-->
		<p
			v-if="nameError"
			class="keepiq-additional-fields__error"
			data-testid="additional-field-error">
			{{ nameError }}
		</p>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import SchemaAdvanced from './SchemaAdvanced.vue'
import SchemaValueInput from './SchemaValueInput.vue'
import { memberNameError } from '../utils/additionalFields.js'
import {
	cleanSchema,
	presetOf,
	presetOptions,
	presetSchema,
	schemaError,
} from '../utils/fieldSchema.js'

export default {
	name: 'AdditionalFieldsEditor',

	components: {
		NcButton,
		NcSelect,
		NcTextField,
		SchemaAdvanced,
		SchemaValueInput,
	},

	props: {
		/**
		 * The member list being edited, as `{ name, value, schema }` entries.
		 *
		 * A list rather than an object so a rename is an edit in place: keying the
		 * rows by name would make renaming a member indistinguishable from removing
		 * one and adding another, and would lose the row's position and focus.
		 */
		members: {
			type: Array,
			default: () => [],
		},

		/** Whether editing is blocked (a locked vault, or a save in flight). */
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:members'],

	data() {
		return {
			newName: '',
			nameError: '',
			advancedOpen: [],
		}
	},

	methods: {
		presetOf,

		/**
		 * A member's schema, plain text when it has none.
		 *
		 * @param {{schema?: object}} member The member.
		 * @return {object} The schema.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
		 */
		schemaOf(member) {
			return cleanSchema(member.schema)
		},

		/**
		 * The kind options: the presets, plus Custom while the schema matches none.
		 *
		 * @param {object} schema The member's schema.
		 * @return {Array<{label: string, value: string}>} The options.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
		 */
		kindOptions(schema) {
			const options = presetOptions().map((p) => ({
				label: p.label,
				value: p.id,
			}))
			if (presetOf(schema) === 'custom') {
				options.push({ label: t('keepiq', 'Custom'), value: 'custom' })
			}
			return options
		},

		/**
		 * Why a member's value does not fit its schema, or ''.
		 *
		 * @param {{value: string, schema?: object}} member The member.
		 * @return {string} The reason.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
		 */
		valueError(member) {
			return schemaError(member.value, member.schema)
		},

		/**
		 * Whether a row's Advanced part is open.
		 *
		 * @param {number} index The row.
		 * @return {boolean} True when open.
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
		 */
		isOpen(index) {
			return this.advancedOpen.includes(index)
		},

		/**
		 * Open or close a row's Advanced part.
		 *
		 * @param {number} index The row.
		 * @return {void}
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
		 */
		toggleAdvanced(index) {
			this.advancedOpen = this.isOpen(index)
				? this.advancedOpen.filter((i) => i !== index)
				: [...this.advancedOpen, index]
		},

		/**
		 * Give a member the schema of the picked preset.
		 *
		 * @param {number} index The row.
		 * @param {string} id The preset.
		 * @return {void}
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
		 */
		onPreset(index, id) {
			if (id === 'custom') {
				return
			}
			this.onSchema(index, presetSchema(id))
		},

		/**
		 * Change a member's schema.
		 *
		 * @param {number} index The row.
		 * @param {object} schema The new schema.
		 * @return {void}
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
		 */
		onSchema(index, schema) {
			const next = this.members.map((member, position) =>
				position === index
					? { ...member, schema: cleanSchema(schema) }
					: member,
			)
			this.emitMembers(next)
		},

		/**
		 * Emit a new member list, leaving the prop untouched.
		 *
		 * @param {Array<object>} members The next member list.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
		 */
		emitMembers(members) {
			this.$emit('update:members', members)
		},

		/**
		 * Add the named member currently typed.
		 *
		 * Validated against the SHARED rule, so a name refused here is refused
		 * identically by the request dialog.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
		 */
		onAdd() {
			const name = (this.newName || '').trim()
			const error = memberNameError(
				name,
				this.members.map((member) => member.name),
			)

			if (error !== '') {
				this.nameError = error
				return
			}

			this.nameError = ''
			this.newName = ''
			this.emitMembers([
				...this.members,
				{ name, value: '', schema: { type: 'string' } },
			])
		},

		/**
		 * Rename a member in place.
		 *
		 * The same rules apply as on adding — a member renamed to `url` would be
		 * misrouted exactly as one created with that name would.
		 *
		 * @param {number} index The row being renamed.
		 * @param {string} name The new name.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
		 */
		onRename(index, name) {
			const others = this.members
				.filter((_, position) => position !== index)
				.map((member) => member.name)

			this.nameError = memberNameError(name, others)

			const next = this.members.map((member, position) =>
				position === index ? { ...member, name } : member,
			)
			this.emitMembers(next)
		},

		/**
		 * Change a member's value.
		 *
		 * @param {number} index The row being changed.
		 * @param {string} value The new value.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
		 */
		onRevalue(index, value) {
			const next = this.members.map((member, position) =>
				position === index ? { ...member, value } : member,
			)
			this.emitMembers(next)
		},

		/**
		 * Remove a member.
		 *
		 * Removing the last one leaves an EMPTY list, which the caller turns into an
		 * empty blob rather than null — "no additional fields" has to stay
		 * distinguishable from "nothing was sent".
		 *
		 * @param {number} index The row being removed.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
		 */
		onRemove(index) {
			this.nameError = ''
			// Rows after the removed one move up, so do their open states.
			this.advancedOpen = this.advancedOpen
				.filter((i) => i !== index)
				.map((i) => (i > index ? i - 1 : i))
			this.emitMembers(
				this.members.filter((_, position) => position !== index),
			)
		},
	},
}
</script>

<style scoped>
.keepiq-additional-fields {
	margin-block: 0.75rem;
}

.keepiq-additional-fields__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.keepiq-additional-fields__row {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding-block: 0.5rem;
	border-bottom: 1px solid var(--color-border);
}

.keepiq-additional-fields__line,
.keepiq-additional-fields__add {
	display: flex;
	align-items: flex-end;
	gap: 0.5rem;
	margin-block-end: 0.5rem;
}

.keepiq-additional-fields__line {
	margin-block-end: 0;
}

.keepiq-additional-fields__kind {
	flex: 0 0 12rem;
}

.keepiq-additional-fields__advanced-toggle {
	align-self: flex-start;
}

.keepiq-additional-fields__empty {
	color: var(--color-text-maxcontrast);
}

.keepiq-additional-fields__error {
	color: var(--color-error);
}
</style>
