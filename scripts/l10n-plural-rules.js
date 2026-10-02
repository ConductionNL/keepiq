// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// l10n-plural-rules.js: the plural rule of every locale, as the gettext
// `pluralForm` string Nextcloud catalogues carry.
//
// WHY THIS EXISTS
//
//   `n('keepiq', singular, plural, count)` looks up ONE key,
//   `_<singular>_::_<plural>_`, whose value is an array with one form per
//   plural category of the language. In the browser, @nextcloud/l10n picks
//   the index with its own per-language table (`getPlural()` in
//   @nextcloud/l10n/dist/chunks/translation-*.mjs), not with the catalogue's
//   pluralForm. So an array is only right when it has exactly as many forms
//   as that table has categories for the language. This file mirrors that
//   table, so the extraction can size the arrays and the build can write a
//   pluralForm that agrees with what the browser does (PHP's IL10N reads the
//   pluralForm from the .json).
//
//   A language the table does not know (Romansh, `rm`, in keepiq) gets index
//   0 for every count in the browser, so ANY plural entry would show the
//   singular for every count. For such a locale the right move is to carry NO
//   plural entry: translatePlural then falls back to the plain singular and
//   plural keys, "singular when n is 1, plural otherwise". `browserKnows()`
//   tells the extraction and the parity check to leave those locales alone.

'use strict'

/** gettext rule per language, mirroring @nextcloud/l10n 3.x getPlural(). */
const RULES = {}

/**
 * Assign one rule to several languages.
 *
 * @param {string[]} langs - language codes
 * @param {string} rule - gettext pluralForm string
 */
function set(langs, rule) {
	for (const lang of langs) {
		RULES[lang] = rule
	}
}

set(
	[
		'az',
		'bo',
		'dz',
		'id',
		'ja',
		'jv',
		'ka',
		'km',
		'kn',
		'ko',
		'ms',
		'th',
		'tr',
		'vi',
		'zh',
	],
	'nplurals=1; plural=0;',
)
set(
	[
		'af',
		'bn',
		'bg',
		'ca',
		'da',
		'de',
		'el',
		'en',
		'eo',
		'es',
		'et',
		'eu',
		'fa',
		'fi',
		'fo',
		'fur',
		'fy',
		'gl',
		'gu',
		'ha',
		'he',
		'hu',
		'is',
		'it',
		'ku',
		'lb',
		'ml',
		'mn',
		'mr',
		'nah',
		'nb',
		'ne',
		'nl',
		'nn',
		'no',
		'oc',
		'om',
		'or',
		'pa',
		'pap',
		'ps',
		'pt',
		'so',
		'sq',
		'sv',
		'sw',
		'ta',
		'te',
		'tk',
		'ur',
		'zu',
	],
	'nplurals=2; plural=(n != 1);',
)
set(
	[
		'am',
		'bh',
		'fil',
		'fr',
		'gun',
		'hi',
		'hy',
		'ln',
		'mg',
		'nso',
		'xbr',
		'ti',
		'wa',
	],
	'nplurals=2; plural=(n > 1);',
)
set(
	['be', 'bs', 'hr', 'ru', 'sh', 'sr', 'uk'],
	'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
)
set(['cs', 'sk'], 'nplurals=3; plural=(n==1 ? 0 : (n>=2 && n<=4) ? 1 : 2);')
set(['ga'], 'nplurals=3; plural=(n==1 ? 0 : n==2 ? 1 : 2);')
set(
	['lt'],
	'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && (n%100<10 || n%100>=20) ? 1 : 2);',
)
set(
	['sl'],
	'nplurals=4; plural=(n%100==1 ? 0 : n%100==2 ? 1 : n%100==3 || n%100==4 ? 2 : 3);',
)
set(['mk'], 'nplurals=2; plural=(n%10==1 ? 0 : 1);')
set(
	['mt'],
	'nplurals=4; plural=(n==1 ? 0 : n==0 || (n%100>1 && n%100<11) ? 1 : (n%100>10 && n%100<20) ? 2 : 3);',
)
set(['lv'], 'nplurals=3; plural=(n==0 ? 0 : n%10==1 && n%100!=11 ? 1 : 2);')
set(
	['pl'],
	'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);',
)
set(['cy'], 'nplurals=4; plural=(n==1 ? 0 : n==2 ? 1 : (n==8 || n==11) ? 2 : 3);')
set(
	['ro'],
	'nplurals=3; plural=(n==1 ? 0 : (n==0 || (n%100>0 && n%100<20)) ? 1 : 2);',
)
set(
	['ar'],
	'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 && n%100<=99 ? 4 : 5);',
)

/** What the browser does for a language it has no rule for: always index 0. */
const UNKNOWN_RULE = 'nplurals=1; plural=0;'

/**
 * The language @nextcloud/l10n would resolve a catalogue file name to.
 * `pt_BR` and `pt-BR` map to `xbr`; any other region is dropped.
 *
 * @param {string} locale - catalogue name, e.g. `pl`, `pt_BR`, `en_GB`
 * @return {string} the language key of RULES
 */
function languageOf(locale) {
	const norm = locale.replace('_', '-')
	if (norm === 'pt-BR') {
		return 'xbr'
	}
	return norm.includes('-') ? norm.slice(0, norm.indexOf('-')) : norm
}

/**
 * The gettext pluralForm for a catalogue.
 *
 * @param {string} locale - catalogue name
 * @return {string} the rule string
 */
function pluralFormFor(locale) {
	return RULES[languageOf(locale)] || UNKNOWN_RULE
}

/**
 * Whether @nextcloud/l10n has a plural rule for this catalogue's language.
 *
 * @param {string} locale - catalogue name
 * @return {boolean} false for a language it would always index at 0
 */
function browserKnows(locale) {
	return Object.hasOwn(RULES, languageOf(locale))
}

/**
 * True for a `_<singular>_::_<plural>_` catalogue key.
 *
 * @param {string} key - catalogue key
 * @return {boolean} whether it is a plural entry
 */
function isPluralKey(key) {
	return /^_.*_::_.*_$/s.test(key)
}

/**
 * Number of forms a pluralForm string declares.
 *
 * @param {string} pluralForm - e.g. `nplurals=3; plural=(...);`
 * @return {number|null} nplurals, or null when the string declares none
 */
function npluralsOf(pluralForm) {
	const m = /nplurals\s*=\s*(\d+)/.exec(pluralForm || '')
	return m === null ? null : Number(m[1])
}

/**
 * The catalogue key `n()` looks up, exactly as @nextcloud/l10n builds it.
 *
 * @param {string} singular - singular source string
 * @param {string} plural - plural source string
 * @return {string} `_<singular>_::_<plural>_`
 */
function pluralKey(singular, plural) {
	return '_' + singular + '_::_' + plural + '_'
}

module.exports = {
	RULES,
	UNKNOWN_RULE,
	languageOf,
	browserKnows,
	isPluralKey,
	pluralFormFor,
	npluralsOf,
	pluralKey,
}
