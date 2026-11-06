/** The EFF large word list, bundled under `public/wordlist/` and generated from the web app's `src/generator/eff-large-wordlist.js`. */
export const WORDLIST_SIZE = 7776

export type WordlistLoader = () => Promise<string>

const bundled: WordlistLoader = async () => {
	const response = await fetch(browser.runtime.getURL('/wordlist/eff-large.txt'))
	if (!response.ok) throw new Error(`Wordlist answered ${response.status}`)
	return response.text()
}

function parse(text: string): readonly string[] | null {
	const words = text.split('\n').filter((word) => word !== '')
	return words.length === WORDLIST_SIZE ? Object.freeze(words) : null
}

/** Loads once and shares the promise; `null` when unavailable, and a failed load is retried on the next call. */
export function createWordlist(load: WordlistLoader): () => Promise<readonly string[] | null> {
	let cached: Promise<readonly string[] | null> | null = null
	return () => {
		cached ??= load().then(parse, () => null).then((words) => {
			if (!words) cached = null
			return words
		})
		return cached
	}
}

export const wordlist = createWordlist(bundled)
