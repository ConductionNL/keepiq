/** Runs changes one after another, so a read-modify-write never works on a stale read. */
export function createQueue(): <T>(change: () => Promise<T>) => Promise<T> {
	let tail: Promise<unknown> = Promise.resolve()
	return (change) => {
		const run = tail.then(change)
		tail = run.catch(() => {})
		return run
	}
}

/** One `createQueue` per key, such as an account id. */
export function createKeyedQueue(): <T>(key: string, change: () => Promise<T>) => Promise<T> {
	const queues = new Map<string, ReturnType<typeof createQueue>>()
	return (key, change) => {
		let queued = queues.get(key)
		if (!queued) queues.set(key, (queued = createQueue()))
		return queued(change)
	}
}
