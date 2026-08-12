<template>
	<CnSettingsSection
		:name="t('doriath', 'Password Policy')"
		:description="t('doriath', 'Configure master password requirements for all users')">
		<div class="password-policy">
			<div class="password-policy__field">
				<label for="min-length">{{ t('doriath', 'Minimum length') }}</label>
				<input
					id="min-length"
					v-model.number="minLength"
					type="number"
					min="12"
					max="20"
					@change="save">
				<span class="password-policy__hint">{{ t('doriath', '12–20 characters') }}</span>
			</div>

			<div class="password-policy__field">
				<label for="min-score">{{ t('doriath', 'Minimum strength score') }}</label>
				<select id="min-score" v-model.number="minScore" @change="save">
					<option :value="3">
						{{ t('doriath', 'Strong (score 3)') }}
					</option>
					<option :value="4">
						{{ t('doriath', 'Very strong (score 4)') }}
					</option>
				</select>
			</div>

			<p v-if="error"
				class="password-policy__error"
				role="alert"
				data-testid="password-policy-error">
				{{ error }}
			</p>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { resetPolicyCache } from '../../policy/policy.js'

export default {
	name: 'PasswordPolicySection',
	components: { CnSettingsSection },

	data() {
		return {
			minLength: 12,
			minScore: 3,
			error: '',
		}
	},

	/**
	 * Load the current master-password policy floors from the API.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
	 */
	async created() {
		try {
			const response = await axios.get(generateUrl('/apps/doriath/api/settings'))
			const settings = response.data
			this.minLength = parseInt(settings.master_password_min_length) || 12
			this.minScore = parseInt(settings.master_password_min_score) || 3
		} catch (e) {
			this.error = t('doriath', 'Could not load the password policy.')
		}
	},

	methods: {
		/**
		 * Persist the master-password policy, clamping length to 12-20 and
		 * score to 3-4 (the app-minimum floors cannot be lowered below).
		 *
		 * The server enforces the same bounds and answers 400 when they are
		 * violated, so a rejected write is reported instead of being read as
		 * a silent success — before #192 this endpoint returned
		 * `{success: true}` for a write it had discarded.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
		 */
		async save() {
			this.error = ''
			try {
				const response = await axios.post(generateUrl('/apps/doriath/api/settings'), {
					master_password_min_length: String(Math.min(20, Math.max(12, this.minLength))),
					master_password_min_score: String(Math.min(4, Math.max(3, this.minScore))),
				})
				// Read the stored values back, not the submission: the panel must
				// show what the server actually kept.
				const stored = response?.data?.config ?? {}
				this.minLength = parseInt(stored.master_password_min_length) || this.minLength
				this.minScore = parseInt(stored.master_password_min_score) || this.minScore
				// The strength meters cache the policy per page load; the floor
				// just changed, so drop it or the new floor takes effect only
				// after a reload.
				resetPolicyCache()
			} catch (e) {
				this.error = e?.response?.data?.message
					|| t('doriath', 'Could not save the password policy.')
			}
		},
	},
}
</script>

<style scoped>
.password-policy__field {
	margin-bottom: 1rem;
}

.password-policy__field label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

.password-policy__hint {
	color: var(--color-text-lighter);
	font-size: 0.85rem;
}

.password-policy__error {
	color: var(--color-error-text);
	font-size: 0.85rem;
	margin: 0;
}
</style>
