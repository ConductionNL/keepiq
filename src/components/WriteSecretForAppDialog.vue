<template>
	<NcDialog :name="t('doriath', 'Write secret for application')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="write-secret">
			<NcNoteCard type="info">
				{{ t('doriath', 'The secret is encrypted with the application\'s public certificate. You will not be able to read it back.') }}
			</NcNoteCard>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcInputField :value.sync="name"
				:label="t('doriath', 'Name')"
				:required="true"
				:disabled="submitting" />

			<NcPasswordField :value.sync="secretKey"
				:label="t('doriath', 'Secret value')"
				:required="true"
				:disabled="submitting" />

			<NcInputField :value.sync="login"
				:label="t('doriath', 'Login (optional)')"
				:disabled="submitting" />

			<NcInputField :value.sync="url"
				:label="t('doriath', 'URL (optional)')"
				:disabled="submitting" />
		</div>

		<template #actions>
			<NcButton :disabled="submitting" @click="onUpdateOpen(false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="!canSubmit || submitting"
				@click="submit">
				{{ submitting ? t('doriath', 'Saving…') : t('doriath', 'Write secret') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcInputField, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import { useApplicationStore } from '../store/modules/application.js'

export default {
	name: 'WriteSecretForAppDialog',

	components: {
		NcButton,
		NcDialog,
		NcInputField,
		NcNoteCard,
		NcPasswordField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
		applicationId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			name: '',
			secretKey: '',
			login: '',
			url: '',
			submitting: false,
			error: null,
		}
	},

	computed: {
		canSubmit() {
			return this.name.trim().length > 0 && this.secretKey.length > 0
		},
	},

	methods: {
		onUpdateOpen(value) {
			this.$emit('update:open', value)
			if (!value) {
				this.reset()
			}
		},

		async submit() {
			this.submitting = true
			this.error = null
			try {
				await useApplicationStore().writeSecretForApplication(this.applicationId, {
					name: this.name.trim(),
					key: this.secretKey,
					login: this.login.trim() || null,
					url: this.url.trim() || null,
				})
				this.$emit('written')
				this.onUpdateOpen(false)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || t('doriath', 'Failed to write secret')
			} finally {
				this.submitting = false
			}
		},

		reset() {
			this.name = ''
			this.secretKey = ''
			this.login = ''
			this.url = ''
			this.error = null
		},
	},
}
</script>

<style scoped>
.write-secret {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}
</style>
