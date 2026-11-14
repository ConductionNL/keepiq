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

  Each member has a kind (text, hidden or boolean). A hidden value is edited in
  a masked field with its own reveal toggle and a Copy action that copies it
  without revealing it. The kind rides in the same encrypted blob as the name
  and value; a member without one is text.

  @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
  @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-hidden-custom-fields
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
				<NcTextField
					:modelValue="member.name"
					:label="t('keepiq', 'Field name')"
					:disabled="disabled"
					:data-testid="`additional-field-name-${index}`"
					@update:modelValue="onRename(index, $event)" />
				<NcSelect
					class="keepiq-additional-fields__kind"
					:modelValue="kindOption(member.kind)"
					:options="kindOptions"
					:inputLabel="t('keepiq', 'Field kind')"
					:clearable="false"
					:disabled="disabled"
					:data-testid="`additional-field-kind-${index}`"
					@update:modelValue="onRekind(index, $event)" />
				<NcCheckboxRadioSwitch
					v-if="member.kind === 'boolean'"
					:modelValue="member.value === 'true'"
					:disabled="disabled"
					:data-testid="`additional-field-value-${index}`"
					@update:modelValue="onRevalue(index, $event ? 'true' : 'false')">
					{{ member.name || t('keepiq', 'Value') }}
				</NcCheckboxRadioSwitch>
				<template v-else-if="member.kind === 'hidden'">
					<NcPasswordField
						:modelValue="member.value"
						:label="t('keepiq', 'Value')"
						:disabled="disabled"
						autocomplete="off"
						:data-testid="`additional-field-value-${index}`"
						@update:modelValue="onRevalue(index, $event)" />
					<CopyButton
						:value="member.value"
						:label="t('keepiq', 'Copy value')"
						:data-testid="`additional-field-copy-${index}`" />
				</template>
				<NcTextField
					v-else
					:modelValue="member.value"
					:label="t('keepiq', 'Value')"
					:disabled="disabled"
					:data-testid="`additional-field-value-${index}`"
					@update:modelValue="onRevalue(index, $event)" />
				<NcButton
					variant="tertiary"
					:disabled="disabled"
					:aria-label="t('keepiq', 'Remove this field')"
					:data-testid="`additional-field-remove-${index}`"
					@click="onRemove(index)">
					{{ t('keepiq', 'Remove') }}
				</NcButton>
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
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcPasswordField,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import CopyButton from './CopyButton.vue'
import { memberNameError, normaliseKind } from '../utils/additionalFields.js'

export default {
	name: 'AdditionalFieldsEditor',

	components: {
		CopyButton,
		NcButton,
		NcCheckboxRadioSwitch,
		NcPasswordField,
		NcSelect,
		NcTextField,
	},

	props: {
		/**
		 * The member list being edited, as `{ name, value, kind }` entries. A
		 * member without a kind is text.
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
		}
	},

	computed: {
		/**
		 * The kinds a field can have, as select options.
		 *
		 * @return {Array<{value: string, label: string}>}
		 *
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-hidden-custom-fields
		 */
		kindOptions() {
			return [
				{ value: 'text', label: t('keepiq', 'Text') },
				{ value: 'hidden', label: t('keepiq', 'Hidden') },
				{ value: 'boolean', label: t('keepiq', 'Yes or no') },
			]
		},
	},

	methods: {
		/**
		 * The select option of a member's kind; a member without one is text.
		 *
		 * @param {string|undefined} kind The member's kind.
		 *
		 * @return {{value: string, label: string}}
		 *
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-hidden-custom-fields
		 */
		kindOption(kind) {
			const normalised = normaliseKind(kind)
			return this.kindOptions.find((option) => option.value === normalised)
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
			this.emitMembers([...this.members, { name, value: '', kind: 'text' }])
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
		 * Change a member's kind. The value is kept; a value turned into a yes
		 * or no field becomes `'true'` only when it already read true, so a
		 * switch never invents a yes.
		 *
		 * @param {number} index The row being changed.
		 * @param {{value: string}|string|null} option The chosen kind option.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-hidden-custom-fields
		 */
		onRekind(index, option) {
			const kind = normaliseKind(
				typeof option === 'string' ? option : option?.value,
			)
			const next = this.members.map((member, position) => {
				if (position !== index) {
					return member
				}
				const value =
					kind === 'boolean'
						? String(member.value === 'true')
						: member.value
				return { ...member, value, kind }
			})
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

.keepiq-additional-fields__row,
.keepiq-additional-fields__add {
	display: flex;
	align-items: flex-end;
	gap: 0.5rem;
	margin-block-end: 0.5rem;
}

.keepiq-additional-fields__kind {
	min-width: 10rem;
}

.keepiq-additional-fields__empty {
	color: var(--color-text-maxcontrast);
}

.keepiq-additional-fields__error {
	color: var(--color-error);
}
</style>
