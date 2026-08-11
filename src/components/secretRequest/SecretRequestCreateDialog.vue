<template>
	<NcDialog
		:open="open"
		:name="title"
		size="normal"
		@update:open="onClose">
		<div class="secret-request-create-dialog">
			<p v-if="isReRequest" class="secret-request-create-dialog__note">
				{{ t('doriath', 'Existing values will be overwritten when the recipient fills in this re-request.') }}
			</p>

			<fieldset class="secret-request-create-dialog__fields">
				<legend>{{ t('doriath', 'Requested fields') }}</legend>
				<label v-for="field in availableFields" :key="field.key" class="secret-request-create-dialog__field">
					<input
						v-model="requestedFields"
						type="checkbox"
						:value="field.key">
					{{ field.label }}
				</label>
			</fieldset>

			<div class="secret-request-create-dialog__expiry">
				<label for="expiry-input">{{ t('doriath', 'Expires at (optional)') }}</label>
				<input
					id="expiry-input"
					v-model="expiresAt"
					type="datetime-local">
			</div>

			<div v-if="fillUrl" class="secret-request-create-dialog__url">
				<label for="secret-request-fill-url">{{ t('doriath', 'Share this link with the recipient') }}</label>
				<div class="secret-request-create-dialog__url-row">
					<input id="secret-request-fill-url"
						type="text"
						readonly
						:value="fillUrl">
					<button @click="copyUrl">
						{{ copied ? t('doriath', 'Copied!') : t('doriath', 'Copy') }}
					</button>
				</div>
			</div>

			<div v-if="error" class="secret-request-create-dialog__error">
				{{ error }}
			</div>
		</div>

		<template #actions>
			<button @click="onClose(false)">
				{{ t('doriath', 'Cancel') }}
			</button>
			<button v-if="!fillUrl"
				class="primary"
				:disabled="submitting"
				@click="submit">
				{{ submitting ? t('doriath', 'Creating…') : t('doriath', 'Create request') }}
			</button>
			<button v-else class="primary" @click="onClose(true)">
				{{ t('doriath', 'Done') }}
			</button>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { useSecretRequestStore } from '../../store/modules/secretRequest.js'

export default {
	name: 'SecretRequestCreateDialog',
	components: { NcDialog },

	props: {
		open: { type: Boolean, default: false },
		secret: { type: Object, required: true },
		isReRequest: { type: Boolean, default: false },
	},

	emits: ['update:open', 'created'],

	data() {
		return {
			requestedFields: ['key'],
			expiresAt: '',
			fillUrl: '',
			error: '',
			submitting: false,
			copied: false,
		}
	},

	computed: {
		title() {
			return this.isReRequest
				? t('doriath', 'Re-request secret values')
				: t('doriath', 'Request secret fill-in')
		},

		availableFields() {
			const fields = [
				{ key: 'key', label: t('doriath', 'Key / password') },
				{ key: 'login', label: t('doriath', 'Login') },
			]
			const additional = this.secret?.additional_fields_keys || []
			for (const key of additional) {
				fields.push({ key, label: key })
			}
			return fields
		},
	},

	methods: {
		/**
		 * Submit the create request to the store.
		 *
		 * @spec openspec/changes/implement-secret-requests/tasks.md#task-8.2
		 */
		async submit() {
			this.error = ''
			this.submitting = true
			try {
				const store = useSecretRequestStore()
				const expires = this.expiresAt ? new Date(this.expiresAt).toISOString() : null

				const payload = {
					secret_id: this.secret.id,
					requested_fields: this.requestedFields,
					expires_at: expires,
					is_re_request: this.isReRequest,
				}

				const request = this.isReRequest
					? await store.createReRequest(
						this.secret.id,
						this.secret.encryption_suite_id,
						this.requestedFields,
						expires,
					)
					: await store.createRequest(payload)

				if (request && request.token) {
					this.fillUrl = generateUrl(`/apps/doriath/share/request/${request.token}`, {}, { absolute: true })
				}

				this.$emit('created', request)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || t('doriath', 'Failed to create request')
			} finally {
				this.submitting = false
			}
		},

		async copyUrl() {
			try {
				await navigator.clipboard.writeText(this.fillUrl)
				this.copied = true
				setTimeout(() => { this.copied = false }, 1500)
			} catch (e) {
				console.warn('Doriath: clipboard write failed', e)
			}
		},

		onClose(emitDone = false) {
			this.$emit('update:open', false)
			if (emitDone) {
				// reset form for re-use
				this.requestedFields = ['key']
				this.expiresAt = ''
				this.fillUrl = ''
				this.error = ''
			}
		},
	},
}
</script>

<style scoped>
.secret-request-create-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.secret-request-create-dialog__note {
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 8px 12px;
}

.secret-request-create-dialog__fields {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 8px;
}

.secret-request-create-dialog__field {
	display: block;
	margin: 4px 0;
}

.secret-request-create-dialog__url-row {
	display: flex;
	gap: 8px;
}

.secret-request-create-dialog__url-row input {
	flex: 1;
}

.secret-request-create-dialog__error {
	color: var(--color-error-text);
}
</style>
