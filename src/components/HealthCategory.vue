<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  One finding category in the password-health report. Renders a titled list of
  affected secrets (name + folder path) that deep-link to the secret detail, or
  an "all clear" line when empty, and optionally one action button per finding.
  Presentation only — all analysis is upstream in the health store.

  @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-vault-health-report
-->
<template>
	<section class="health-category" :data-testid="testid">
		<h3>{{ title }}</h3>
		<p v-if="description" class="health-category__description">
			{{ description }}
		</p>

		<p v-if="findings.length === 0" class="health-category__empty">
			{{ t('keepiq', 'No findings in this category.') }}
		</p>

		<ul v-else class="health-category__list">
			<li
				v-for="finding in findings"
				:key="finding.id"
				class="health-category__item">
				<button
					type="button"
					class="health-category__link"
					:data-testid="`finding-${finding.id}`"
					@click="$emit('open', finding.id)">
					<span class="health-category__name">{{ finding.name }}</span>
					<span v-if="finding.folderPath" class="health-category__path">{{
						finding.folderPath
					}}</span>
					<span
						v-if="finding.shareCount > 1"
						class="health-category__meta">
						{{
							n(
								'keepiq',
								'shared with %n secret',
								'shared with %n secrets',
								finding.shareCount,
							)
						}}
					</span>
					<span
						v-if="finding.breach && finding.breach.count > 0"
						class="health-category__meta">
						{{
							n(
								'keepiq',
								'seen %n time in breaches',
								'seen %n times in breaches',
								finding.breach.count,
							)
						}}
					</span>
				</button>
				<NcButton
					v-if="actionLabel"
					variant="secondary"
					:data-testid="`finding-action-${finding.id}`"
					@click="$emit('action', finding)">
					{{ actionLabel }}
				</NcButton>
			</li>
		</ul>
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'HealthCategory',
	components: { NcButton },

	props: {
		title: {
			type: String,
			required: true,
		},

		description: {
			type: String,
			default: '',
		},

		findings: {
			type: Array,
			default: () => [],
		},

		testid: {
			type: String,
			default: 'health-category',
		},

		/**
		 * Label of a button beside each finding that emits `action` with the
		 * finding; no button when empty.
		 */
		actionLabel: {
			type: String,
			default: '',
		},
	},

	emits: ['open', 'action'],
}
</script>

<style scoped lang="scss">
.health-category {
	margin-bottom: 1.5rem;

	&__link {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		background: none;
		border: none;
		padding: 0.5rem;
		width: 100%;
		text-align: start;
		cursor: pointer;
		border-radius: var(--border-radius);

		&:hover {
			background-color: var(--color-background-hover);
		}
	}

	&__path,
	&__meta {
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
	}
}
</style>
