/**
 * Chrome, Edge (`-b edge`) and any other Chromium target. Reads `BROWSER`, which tests can stub as a string,
 * rather than the boolean flags; `wxt.config.ts` passes its own `browser`.
 */
export function isChromium(target: string = import.meta.env.BROWSER): boolean {
	return target !== 'firefox' && target !== 'safari'
}

/**
 * Where a build must drop Safari-only code, test `import.meta.env.BROWSER === 'safari'` inline instead: the bundler
 * folds that, not this call.
 */
export function isSafari(target: string = import.meta.env.BROWSER): boolean {
	return target === 'safari'
}

/** Safari has no `idle`, so nothing there can tell a system lock. */
export function hasIdle(): boolean {
	return (browser as Partial<typeof browser>).idle !== undefined
}
