<template>
	<NcDialog
		:open="open"
		:name="t('doriath', 'Delete folder')"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="subfolder-resolution">
			<NcNoteCard v-if="loadError" type="error">
				{{ loadError }}
			</NcNoteCard>

			<NcLoadingIcon v-else-if="loading" />

			<template v-else>
				<p v-if="directSecretCount > 0" class="subfolder-resolution__summary">
					{{ n('doriath', 'This folder contains {count} secret. What should happen to it?', 'This folder contains {count} secrets. What should happen to them?', directSecretCount, { count: directSecretCount }) }}
				</p>

				<div v-if="directSecretCount > 0" class="subfolder-resolution__field">
					<label class="subfolder-resolution__label">{{ t('doriath', 'Secrets in this folder') }}</label>
					<NcSelect
						v-model="directSecretsAction"
						:options="secretActionOptions"
						:clearable="false" />
				</div>

				<div v-if="children.length > 0" class="subfolder-resolution__subfolders">
					<p class="subfolder-resolution__label">
						{{ t('doriath', 'Subfolders') }}
					</p>
					<div
						v-for="child in children"
						:key="child.id"
						class="subfolder-resolution__subfolder">
						<span class="subfolder-resolution__subfolder-name">
							{{ child.name }}
							<span class="subfolder-resolution__subfolder-count">
								({{ n('doriath', '{count} secret', '{count} secrets', child.secretCount || 0, { count: child.secretCount || 0 }) }})
							</span>
						</span>
						<NcSelect
							v-model="subfolderActions[child.id]"
							:options="subfolderActionOptions"
							:clearable="false"
							class="subfolder-resolution__subfolder-select" />
					</div>
				</div>
			</template>
		</div>

		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton
				type="error"
				:disabled="loading || !!loadError"
				@click="submit">
				{{ t('doriath', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { useFolderStore } from '../store/modules/folder.js'

export default {
	name: 'SubfolderResolutionDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		folderId: {
			type: String,
			required: true,
		},
	},
	emits: ['update:open', 'resolve'],
	data() {
		return {
			loading: false,
			loadError: null,
			children: [],
			directSecretCount: 0,
			directSecretsAction: null,
			subfolderActions: {},
		}
	},
	computed: {
		folderStore() {
			return useFolderStore()
		},
		secretActionOptions() {
			return [
				{ label: t('doriath', 'Delete secrets'), value: 'delete' },
				{ label: t('doriath', 'Move to parent folder'), value: 'move' },
			]
		},
		subfolderActionOptions() {
			return [
				{ label: t('doriath', 'Delete subfolder'), value: 'delete' },
				{ label: t('doriath', 'Move to parent folder'), value: 'move' },
				{ label: t('doriath', 'Keep as root folder'), value: 'keep' },
			]
		},
	},
	watch: {
		open(val) {
			if (val) {
				this.loadChildren()
			}
		},
	},
	methods: {
		async loadChildren() {
			this.loading = true
			this.loadError = null
			try {
				const result = await this.folderStore.fetchChildren(this.folderId)
				this.children = result
				this.directSecretCount = result.directSecretCount ?? 0

				// Default action for direct secrets
				this.directSecretsAction = this.secretActionOptions[0]

				// Default action for each subfolder
				const actions = {}
				this.children.forEach(child => {
					actions[child.id] = this.subfolderActionOptions[0]
				})
				this.subfolderActions = actions
			} catch (e) {
				this.loadError = e.message || t('doriath', 'Failed to load subfolder details')
			} finally {
				this.loading = false
			}
		},
		submit() {
			const subfolders = {}
			Object.entries(this.subfolderActions).forEach(([id, option]) => {
				subfolders[id] = option?.value ?? 'delete'
			})

			this.$emit('resolve', {
				directSecrets: this.directSecretsAction?.value ?? 'delete',
				subfolders,
			})
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.subfolder-resolution {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.subfolder-resolution__summary {
	margin: 0;
}

.subfolder-resolution__label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

.subfolder-resolution__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.subfolder-resolution__subfolders {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.subfolder-resolution__subfolder {
	display: flex;
	align-items: center;
	gap: 12px;
}

.subfolder-resolution__subfolder-name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.subfolder-resolution__subfolder-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.subfolder-resolution__subfolder-select {
	flex-shrink: 0;
	min-width: 180px;
}
</style>
