<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Confirm the deletion of a global item type (admin-18). Its secrets stay
  readable: the server moves them to the Login type.

  @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Delete item type')"
		:open="open"
		size="small"
		@update:open="onUpdateOpen">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p>
			{{
				t(
					'keepiq',
					'Delete “{name}”? Secrets of this type stay readable and become Login items.',
					{ name: type.label },
				)
			}}
		</p>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="busy"
				data-testid="type-delete-confirm"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
					<TrashCanOutline v-else :size="20" />
				</template>
				{{ t('keepiq', 'Delete item type') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { useSecretTypeStore } from '../store/modules/secretType.js'

export default {
	name: 'SecretTypeDeleteDialog',

	components: { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, TrashCanOutline },

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		/** The type to delete ({id, label}). */
		type: {
			type: Object,
			required: true,
		},
	},

	emits: ['update:open', 'deleted'],

	data() {
		return { busy: false, error: '' }
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
		 * Delete the type through the type store.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
		 */
		async submit() {
			this.busy = true
			this.error = ''
			try {
				await useSecretTypeStore().deleteType(this.type.id)
				this.$emit('deleted', this.type.id)
				this.onUpdateOpen(false)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t('keepiq', 'Could not delete the item type.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>
