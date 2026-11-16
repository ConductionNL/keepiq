#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// check-path-case.js — refuse two tracked paths that differ only in case.
//
// WHY THIS EXISTS
//
//   Linux treats `RecipientPicker.spec.js` and `recipientPicker.spec.js` as two
//   files; Windows and macOS treat them as one. A repository holding both can
//   be committed from Linux, but on a case-insensitive checkout the two index
//   entries share one file on disk: Git shows one of them as modified forever,
//   a branch switch refuses to run, and the next `git add .` writes the content
//   of one file over the other. That last step silently lost a whole spec on
//   keepiq#1830.
//
//   It usually starts with a case-only rename done the wrong way: a file is
//   added again under its new spelling while the old spelling stays tracked.
//
// HOW TO RENAME A FILE BY CASE ONLY
//
//   Let Git move the path, so the old spelling leaves the index in the same
//   commit:
//
//     git mv Foo.vue foo.vue                # Linux; macOS and Windows on Git 2.x
//     git mv Foo.vue tmp.vue && git mv tmp.vue foo.vue   # always works
//
//   Then check that `git ls-files | grep -i foo.vue` prints ONE line. Never
//   create the new spelling as a new file next to the old one, and never
//   rename only on disk: a case-insensitive file system does not tell Git.
//
// WHAT IT CHECKS
//
//   Every path `git ls-files` reports, and every directory on those paths,
//   lowercased. Two different spellings that lowercase to the same path fail
//   the check. Exit 0 when there are none, 1 when there are.

'use strict'

const { execFileSync } = require('node:child_process')

const tracked = execFileSync('git', ['ls-files', '-z'], {
	encoding: 'utf8',
	maxBuffer: 1e8,
})
	.split('\0')
	.filter(Boolean)

// Every file and every directory above it, by lowercased spelling.
const spellings = new Map()
for (const file of tracked) {
	const parts = file.split('/')
	for (let i = 1; i <= parts.length; i++) {
		const path = parts.slice(0, i).join('/')
		const key = path.toLowerCase()
		if (!spellings.has(key)) {
			spellings.set(key, new Set())
		}
		spellings.get(key).add(path)
	}
}

// Report a clash once, at the shortest path where it starts.
const clashes = [...spellings.values()]
	.filter((set) => set.size > 1)
	.map((set) => [...set].sort())
	.filter(
		(set, _i, all) =>
			!all.some(
				(other) =>
					other !== set
					&& set[0].toLowerCase().startsWith(other[0].toLowerCase() + '/'),
			),
	)

if (clashes.length === 0) {
	console.log(
		`path-case: OK — ${tracked.length} tracked paths, no two differ only in case.`,
	)
	process.exit(0)
}

console.error(
	'path-case: FAIL — these tracked paths differ only in case. On Windows and macOS they are one file:',
)
for (const set of clashes) {
	console.error(`  • ${set.join('  ↔  ')}`)
}
console.error('')
console.error(
	'Keep one spelling. To rename by case only, let Git move the path so the old spelling leaves the index:',
)
console.error('  git mv Old.js tmp.js && git mv tmp.js old.js')
console.error(
	'If both files hold different content, give one of them a distinct name instead of a case variant.',
)
process.exit(1)
