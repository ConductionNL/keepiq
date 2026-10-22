/** Runs changes one after another, so a read-modify-write never works on a stale read. */
export function createQueue(): <T>(change: () => Promise<T>) => Promise<T> {
	let tail: Promise<unknown> = Promise.resolve()
	return (change) => {
		const run = tail.then(change)
		tail = run.catch(() => {})
		return run
	}
}
