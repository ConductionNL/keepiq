<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Dashboard password-health summary card. Shows the vault health score and the
  top-line finding counts and links to the full report. Because the dashboard is
  reachable before the vault is unlocked, it renders an "Unlock to analyse"
  placeholder when there is no session — health analysis only runs in an unlocked
  browser (password-health design D7).

  @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-vault-health-report
-->
<template>
	<article class="health-card" data-testid="dashboard-health-card">
		<h3 class="health-card__title">{{ t('doriath', 'Password health') }}</h3>

		<p v-if="locked" class="health-card__placeholder" data-testid="health-card-locked">
			{{ t('doriath', 'Unlock to analyse') }}
		</p>

		<template v-else>
			<p v-if="store.status === 'analysing'" data-testid="health-card-analysing">
				{{ t('doriath', 'Analysing…') }}
			</p>
			<template v-else-if="store.status === 'ready'">
				<span class="health-card__score" data-testid="health-card-score">{{ store.score }}</span>
				<ul class="health-card__counts">
					<li>{{ t('doriath', 'Weak') }}: {{ store.summary.weakCount }}</li>
					<li>{{ t('doriath', 'Reused') }}: {{ store.summary.reusedCount }}</li>
					<li>{{ t('doriath', 'Old') }}: {{ store.summary.staleCount }}</li>
				</ul>
			</template>
		</template>

		<button type="button" class="health-card__link" data-testid="health-card-open" @click="openReport">
			{{ t('doriath', 'View report') }}
		</button>
	</article>
</template>

<script>
import { useHealthStore } from '../store/modules/health.js'
import { useSessionStore } from '../store/modules/session.js'

export default {
	name: 'HealthSummaryCard',

	data() {
		return {
			store: useHealthStore(),
			session: useSessionStore(),
		}
	},

	computed: {
		/**
		 * Whether the vault is locked.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-vault-health-report
		 */
		locked() {
			return this.session.isLocked
		},
	},

	methods: {
		/**
		 * Navigate to the full password-health report.
		 *
		 * @return {void}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-vault-health-report
		 */
		openReport() {
			this.$router.push({ name: 'PasswordHealth' }).catch(() => {})
		},
	},
}
</script>

<style scoped lang="scss">
.health-card {
	padding: 1rem;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);

	&__score {
		font-size: 2rem;
		font-weight: 700;
		color: var(--color-primary-element, var(--color-primary));
	}

	&__counts {
		display: flex;
		gap: 0.75rem;
		list-style: none;
		padding: 0;
		margin: 0.5rem 0;
		font-size: 0.85rem;
	}

	&__link {
		background: none;
		border: none;
		color: var(--color-primary-element, var(--color-primary));
		cursor: pointer;
		padding: 0;
	}
}
</style>
