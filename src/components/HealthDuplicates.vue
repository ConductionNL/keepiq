<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The Duplicates section of the password-health report (vault-duplicate-finder):
  the exact and likely duplicate groups the health pass found in the browser,
  each with its address host, username and items, and a Merge action.
  Presentation only; the groups come from the health store.

  @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-detect-duplicates-in-the-browser
-->
<template>
	<section class="health-duplicates" data-testid="category-duplicates">
		<h3>{{ t('keepiq', 'Duplicates') }}</h3>
		<p class="health-duplicates__description">
			{{ t('keepiq', 'Items that hold the same login more than once. Merging keeps one and removes the others.') }}
		</p>
		<p v-if="!exact.length && !likely.length" data-testid="duplicates-empty">
			{{ t('keepiq', 'No duplicates found') }}
		</p>

		<template v-for="list in lists" :key="list.kind">
			<template v-if="list.groups.length">
				<h4>{{ list.title }}</h4>
				<p v-if="list.hint" class="health-duplicates__description">
					{{ list.hint }}
				</p>
				<ul class="health-duplicates__groups">
					<li
v-for="(group, index) in list.groups"
						:key="`${list.kind}-${index}`"
						class="health-duplicates__group"
						:data-testid="`duplicate-group-${list.kind}`">
						<div class="health-duplicates__head">
							<span class="health-duplicates__host">{{ group.host || group.items[0].name }}</span>
							<span v-if="group.username" class="health-duplicates__meta">{{ group.username }}</span>
							<NcButton
variant="secondary"
								:data-testid="`duplicate-merge-${list.kind}-${index}`"
								@click="merging = group">
								{{ t('keepiq', 'Merge') }}
							</NcButton>
						</div>
						<ul class="health-duplicates__items">
							<li v-for="item in group.items" :key="item.id">
								<button
type="button"
									class="health-duplicates__link"
									:data-testid="`duplicate-item-${item.id}`"
									@click="$emit('open', item.id)">
									<span>{{ item.name }}</span>
									<span v-if="item.folderId" class="health-duplicates__meta">{{ item.folderId }}</span>
								</button>
							</li>
						</ul>
					</li>
				</ul>
			</template>
		</template>

		<DuplicateMergeModal
v-if="merging"
			:open="true"
			:group="merging"
			@close="merging = null"
			@merged="onMerged" />
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import DuplicateMergeModal from '../modals/DuplicateMergeModal.vue'

export default {
	name: 'HealthDuplicates',
	components: { DuplicateMergeModal, NcButton },
	props: {
		/** Exact duplicate groups. */
		exact: {
			type: Array,
			default: () => [],
		},

		/** Likely duplicate groups. */
		likely: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['open', 'merged'],

	data() {
		return {
			merging: null,
		}
	},

	computed: {
		/**
		 * @spec exclude Display glue — the two lists with their titles.
		 */
		lists() {
			return [
				{ kind: 'exact', title: this.t('keepiq', 'Exact duplicates'), hint: '', groups: this.exact },
				{
					kind: 'likely',
					title: this.t('keepiq', 'Likely duplicates'),
					hint: this.t('keepiq', 'Same address and username, a different password. Two separate accounts can look like this, so check before you merge.'),
					groups: this.likely,
				},
			]
		},
	},

	methods: {
		/**
		 * @spec exclude UI glue — closes the dialog and lets the report analyse again.
		 *
		 * @param {object} result The merge result.
		 */
		onMerged(result) {
			this.merging = null
			this.$emit('merged', result)
		},
	},
}
</script>

<style scoped>
.health-duplicates__description,
.health-duplicates__meta {
	color: var(--color-text-maxcontrast);
}

.health-duplicates__groups,
.health-duplicates__items {
	list-style: none;
	margin: 0;
	padding: 0;
}

.health-duplicates__group {
	margin-block: 8px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.health-duplicates__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.health-duplicates__host {
	font-weight: bold;
}

.health-duplicates__link {
	display: flex;
	gap: 8px;
	min-height: 44px;
	align-items: center;
	border: none;
	background: none;
	color: var(--color-main-text);
	cursor: pointer;
}
</style>
