<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Vault password-health report. Runs the client-side analysis engine over the
  unlocked vault and renders an overall score plus five finding categories —
  weak, reused, stale, breached (when both breach gates are on), and
  possibly-compromised — each finding deep-linking to the secret detail. All
  value-bearing computation happens in the browser; nothing value-bearing leaves
  it. Shows an unlock prompt when the vault is locked and an "unavailable" state
  when the breach service is unreachable (password-health design D5/D6/D7).

  @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-vault-health-report
-->
<template>
	<div class="health-report" data-testid="health-report">
		<h2>{{ t('keepiq', 'Password health') }}</h2>

		<p
			v-if="locked"
			class="health-report__locked"
			data-testid="health-report-locked">
			{{ t('keepiq', 'Unlock your vault to analyse password health.') }}
		</p>

		<template v-else>
			<div class="health-report__controls">
				<NcSelect
					v-model="stalenessOption"
					:inputLabel="t('keepiq', 'Flag passwords older than')"
					:options="stalenessOptions"
					:clearable="false"
					label="label"
					data-testid="staleness-select"
					@update:modelValue="reanalyse" />

				<label
					v-if="breachGateOn"
					class="health-report__optin"
					data-testid="breach-optin">
					<input
						v-model="breachOptIn"
						type="checkbox"
						@change="onBreachToggle" />
					{{
						t(
							'keepiq',
							'Check passwords against the Have I Been Pwned breach corpus',
						)
					}}
				</label>
				<p v-if="breachGateOn" class="health-report__optin-hint">
					{{
						t(
							'keepiq',
							'Only the first 5 characters of each password’s SHA-1 hash are sent (k-anonymity); the password itself never leaves your browser.',
						)
					}}
				</p>
			</div>

			<p
				v-if="store.status === 'analysing'"
				data-testid="health-report-analysing">
				{{ t('keepiq', 'Analysing your vault…') }}
			</p>

			<div v-else-if="store.status === 'ready'" class="health-report__body">
				<div class="health-report__score" data-testid="health-score">
					<span class="health-report__score-value">{{ store.score }}</span>
					<span class="health-report__score-label">{{
						t('keepiq', 'Vault health score')
					}}</span>
				</div>

				<ul class="health-report__counts">
					<li>{{ t('keepiq', 'Weak') }}: {{ store.summary.weakCount }}</li>
					<li>
						{{ t('keepiq', 'Reused') }}: {{ store.summary.reusedCount }}
					</li>
					<li>{{ t('keepiq', 'Old') }}: {{ store.summary.staleCount }}</li>
					<li v-if="breachActive">
						{{ t('keepiq', 'Breached') }}:
						{{ store.summary.breachedCount }}
					</li>
					<li>
						{{ t('keepiq', 'Possibly compromised') }}:
						{{ store.summary.compromisedCount }}
					</li>
				</ul>

				<HealthCategory
					:title="t('keepiq', 'Weak passwords')"
					:findings="store.weakFindings"
					testid="category-weak"
					@open="openSecret" />
				<HealthCategory
					:title="t('keepiq', 'Reused passwords')"
					:description="
						t('keepiq', 'These secrets share an identical password.')
					"
					:findings="store.reusedFindings"
					testid="category-reused"
					@open="openSecret" />
				<HealthCategory
					:title="t('keepiq', 'Old passwords')"
					:findings="store.staleFindings"
					testid="category-stale"
					@open="openSecret" />
				<HealthCategory
					v-if="breachActive"
					:title="t('keepiq', 'Breached passwords')"
					:findings="store.breachedFindings"
					testid="category-breached"
					@open="openSecret" />
				<NcButton
					v-if="breachActive && store.breachedFindings.length"
					variant="secondary"
					data-testid="breach-flag-all"
					@click="flagBreached">
					{{ t('keepiq', 'Flag all breached secrets for rotation') }}
				</NcButton>
				<p
					v-else-if="store.breachStatus === 'unavailable'"
					data-testid="breach-unavailable">
					{{
						t(
							'keepiq',
							'Breach check is currently unavailable. Other findings are unaffected.',
						)
					}}
				</p>
				<HealthCategory
					:title="t('keepiq', 'Possibly compromised')"
					:description="
						t(
							'keepiq',
							'Flagged during an encryption-suite compromise recovery — rotate these values.',
						)
					"
					:findings="store.compromisedFindings"
					testid="category-compromised"
					@open="openSecret" />
				<HealthCategory
					:title="t('keepiq', 'Rotation due')"
					:description="
						t(
							'keepiq',
							'Open rotation flags — expired, breached, or manually flagged credentials awaiting rotation.',
						)
					"
					:findings="rotationFindings"
					testid="category-rotation"
					@open="openSecret" />
			</div>

			<div
				v-else-if="store.status === 'error'"
				class="health-report__error"
				data-testid="health-report-error">
				{{ store.error }}
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSelect } from '@nextcloud/vue'
import HealthCategory from '../components/HealthCategory.vue'
import { useHealthStore } from '../store/modules/health.js'
import { useRotationStore } from '../store/modules/rotation.js'
import { useSessionStore } from '../store/modules/session.js'

export default {
	name: 'HealthReportView',
	components: { NcSelect, NcButton, HealthCategory },

	data() {
		return {
			store: useHealthStore(),
			session: useSessionStore(),
			rotation: useRotationStore(),
			breachGateOn: loadState('keepiq', 'breachCheckEnabled', false),
			breachOptIn: false,
			stalenessOption: { value: '365', label: t('keepiq', '365 days') },
		}
	},

	computed: {
		/**
		 * Whether the vault is locked.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
		 */
		locked() {
			return this.session.isLocked
		},

		/**
		 * Whether breach checking is active (both gates on).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
		 */
		breachActive() {
			return this.breachGateOn && this.breachOptIn
		},

		/**
		 * Staleness threshold options for the select.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-password-age-tracking
		 */
		stalenessOptions() {
			return [
				{ value: '90', label: t('keepiq', '90 days') },
				{ value: '180', label: t('keepiq', '180 days') },
				{ value: '365', label: t('keepiq', '365 days') },
				{ value: 'never', label: t('keepiq', 'Never') },
			]
		},

		/**
		 * Open rotation flags as health findings (deep-link to secret).
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-rotation-surfaced-on-dashboard-and-health-report
		 */
		rotationFindings() {
			return this.rotation.flags.map((flag) => ({
				id: flag.secretId,
				name: flag.secretName || flag.secretId,
			}))
		},
	},

	watch: {
		/**
		 * Persist the staleness threshold whenever the select changes.
		 *
		 * @return {void}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-password-age-tracking
		 */
		stalenessOption() {
			this.persistStaleness()
		},
	},

	/**
	 * Register the lock reset, load user prefs, then analyse the vault.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-vault-health-report
	 */
	async created() {
		this.store.registerLockReset()
		await this.loadPrefs()
		try {
			await this.rotation.fetchFlags()
		} catch (e) {
			console.warn('Keepiq: failed to load rotation flags', e)
		}
		if (!this.locked) {
			await this.reanalyse()
		}
	},

	methods: {
		/**
		 * Load the user's staleness threshold and breach opt-in preferences.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-password-age-tracking
		 */
		async loadPrefs() {
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/settings/user'),
				)
				const threshold = response.data?.health_staleness_days ?? '365'
				const match = this.stalenessOptions.find(
					(o) => o.value === String(threshold),
				)
				if (match) {
					this.stalenessOption = match
				}
				this.breachOptIn =
					response.data?.breach_check_opt_in === '1'
					|| response.data?.breach_check_opt_in === true
			} catch (e) {
				console.warn('Keepiq: failed to load health prefs', e)
			}
		},

		/**
		 * Run a fresh analysis with the current threshold + breach gate.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-vault-health-report
		 */
		async reanalyse() {
			await this.store.analyseVault({
				stalenessThreshold: this.stalenessOption?.value ?? '365',
				breachEnabled: this.breachActive,
			})
		},

		/**
		 * Batch-flag all breached findings for rotation — secret IDs
		 * ONLY leave the client (no verdicts, counts, or digests).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-rotate-after-breach-and-rotate-after-compromise-flagging
		 */
		async flagBreached() {
			try {
				await this.rotation.flagSecrets(
					this.store.breachedFindings.map((f) => f.id),
				)
			} catch (e) {
				console.warn('Keepiq: failed to batch-flag breached secrets', e)
			}
		},

		/**
		 * Persist the breach opt-in and re-analyse.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
		 */
		async onBreachToggle() {
			try {
				await axios.put(generateUrl('/apps/keepiq/api/settings/user'), {
					breach_check_opt_in: this.breachOptIn ? '1' : '0',
				})
			} catch (e) {
				console.warn('Keepiq: failed to save breach opt-in', e)
			}
			await this.reanalyse()
		},

		/**
		 * Persist the staleness threshold (fired by the select) — re-analyse is
		 * triggered by the same @input handler binding to reanalyse.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-password-age-tracking
		 */
		async persistStaleness() {
			try {
				await axios.put(generateUrl('/apps/keepiq/api/settings/user'), {
					health_staleness_days: this.stalenessOption?.value ?? '365',
				})
			} catch (e) {
				console.warn('Keepiq: failed to save staleness threshold', e)
			}
		},

		/**
		 * Deep-link to a secret's detail view.
		 *
		 * @param {string} secretId The secret id.
		 * @return {void}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-vault-health-report
		 */
		openSecret(secretId) {
			this.$router
				.push({ name: 'SecretDetail', params: { id: secretId } })
				.catch(() => {})
		},
	},
}
</script>

<style scoped lang="scss">
.health-report {
	padding: 1rem;

	&__score {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		margin: 1rem 0;
	}

	&__score-value {
		font-size: 2.5rem;
		font-weight: 700;
		color: var(--color-primary-element, var(--color-primary));
	}

	&__counts {
		display: flex;
		flex-wrap: wrap;
		gap: 1rem;
		list-style: none;
		padding: 0;
		margin: 0 0 1rem;
	}

	&__optin-hint {
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}
}
</style>
