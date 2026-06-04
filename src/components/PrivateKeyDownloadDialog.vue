<template>
	<NcDialog :name="t('doriath', 'Save your private key')"
		:open="true"
		size="normal"
		:can-close="acknowledged"
		@update:open="onClose">
		<div class="key-dialog">
			<NcNoteCard type="warning">
				{{ t('doriath', 'This is the only time this private key will be shown. Save it securely. It cannot be recovered.') }}
			</NcNoteCard>

			<label class="key-dialog__label" for="private-key-pem">
				{{ t('doriath', 'Private key (PEM)') }}
			</label>
			<textarea id="private-key-pem"
				class="key-dialog__textarea"
				:value="privateKey"
				readonly
				rows="8" />

			<div class="key-dialog__actions">
				<NcButton @click="copy">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('doriath', 'Copy to clipboard') }}
				</NcButton>
				<NcButton @click="download">
					<template #icon>
						<DownloadIcon :size="20" />
					</template>
					{{ t('doriath', 'Download .pem') }}
				</NcButton>
			</div>

			<NcCheckboxRadioSwitch :checked.sync="acknowledged">
				{{ t('doriath', 'I have saved the private key securely.') }}
			</NcCheckboxRadioSwitch>
		</div>

		<template #actions>
			<NcButton type="primary"
				:disabled="!acknowledged"
				@click="onClose(false)">
				{{ t('doriath', 'Done') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard } from '@nextcloud/vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'

export default {
	name: 'PrivateKeyDownloadDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		ContentCopy,
		DownloadIcon,
	},

	props: {
		privateKey: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			acknowledged: false,
		}
	},

	methods: {
		async copy() {
			try {
				await navigator.clipboard.writeText(this.privateKey)
			} catch {
				// Clipboard unavailable (e.g. non-secure context); no-op.
			}
		},

		download() {
			const blob = new Blob([this.privateKey], { type: 'application/x-pem-file' })
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = 'doriath-application-key.pem'
			link.click()
			URL.revokeObjectURL(url)
		},

		onClose(value) {
			if (value === false && !this.acknowledged) {
				return
			}
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.key-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.key-dialog__label {
	font-weight: bold;
	margin-bottom: -8px;
}

.key-dialog__textarea {
	width: 100%;
	font-family: monospace;
	font-size: 0.8em;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	padding: 8px;
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
	resize: vertical;
}

.key-dialog__actions {
	display: flex;
	gap: 8px;
}
</style>
