<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Subfolder resolution dialog. Shown when deleting a non-empty folder that
  contains subfolders: the user chooses, per subfolder, whether to delete,
  move-out, or keep it. Lives under src/dialogs/ (NcDialog-based) per the
  modal-isolation rule (ADR-004) — never inlined in the parent.
-->
<template>
	<NcDialog
		:open="open"
		:name="t('doriath', 'Delete folder')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="resolution">
			<p>
				{{ n('doriath', 'This folder holds %n secret directly.', 'This folder holds %n secrets directly.', children.directSecretCount) }}
			</p>

			<NcSelect
				v-model="directAction"
				:options="directOptions"
				:input-label="t('doriath', 'Direct secrets')"
				label="label"
				:reduce="opt => opt.value"
				:clearable="false" />

			<div v-for="sub in children.subfolders" :key="sub.id" class="resolution__sub">
				<span class="resolution__sub-name">{{ sub.name }}</span>
				<span class="resolution__sub-count">
					{{ n('doriath', '%n secret', '%n secrets', sub.secretCount) }}
				</span>
				<NcSelect
					v-model="subActions[sub.id]"
					:options="subOptions"
					:input-label="t('doriath', 'Action for {name}', { name: sub.name })"
					label="label"
					:reduce="opt => opt.value"
					:clearable="false" />
			</div>
		</div>

		<template #actions>
			<NcButton type="secondary" @click="$emit('update:open', false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton type="error" @click="submit">
				{{ t('doriath', 'Delete folder') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'

export default {
	name: 'SubfolderResolutionDialog',

	components: {
		NcButton,
		NcDialog,
		NcSelect,
	},

	props: {
		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			default: false,
		},
		/** The children summary: { directSecretCount, subfolders }. */
		children: {
			type: Object,
			default: () => ({ directSecretCount: 0, subfolders: [] }),
		},
	},

	data() {
		return {
			directAction: 'delete',
			subActions: {},
		}
	},

	computed: {
		/**
		 * Options for the direct-secrets action.
		 *
		 * @return {Array} The select options.
		 */
		directOptions() {
			return [
				{ value: 'delete', label: t('doriath', 'Delete them') },
				{ value: 'move', label: t('doriath', 'Move to parent') },
			]
		},
		/**
		 * Options for a per-subfolder action.
		 *
		 * @return {Array} The select options.
		 */
		subOptions() {
			return [
				{ value: 'delete', label: t('doriath', 'Delete') },
				{ value: 'move', label: t('doriath', 'Move secrets out') },
				{ value: 'keep', label: t('doriath', 'Keep (re-parent)') },
			]
		},
	},

	watch: {
		children: {
			immediate: true,
			handler(children) {
				const actions = {}
				;(children.subfolders || []).forEach((sub) => {
					actions[sub.id] = 'keep'
				})
				this.subActions = actions
			},
		},
	},

	methods: {
		/**
		 * Emit the resolution plan to the parent.
		 */
		submit() {
			this.$emit('resolve', {
				directSecrets: this.directAction,
				subfolders: { ...this.subActions },
			})
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.resolution__sub {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	margin-top: 0.75rem;
	padding-top: 0.75rem;
	border-top: 1px solid var(--color-border);
}

.resolution__sub-name {
	font-weight: bold;
}

.resolution__sub-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
