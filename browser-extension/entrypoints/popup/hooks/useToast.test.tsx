// @vitest-environment happy-dom
import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useToast } from './useToast'

beforeEach(() => {
	vi.useFakeTimers()
})
afterEach(() => {
	vi.useRealTimers()
})

describe('useToast', () => {
	it('shows a message and clears it after a while', () => {
		const { result } = renderHook(() => useToast())
		expect(result.current.message).toBeNull()
		act(() => result.current.show('Item saved'))
		expect(result.current.message).toBe('Item saved')
		act(() => vi.advanceTimersByTime(2500))
		expect(result.current.message).toBeNull()
	})

	it('restarts the timer when the same message comes again', () => {
		const { result } = renderHook(() => useToast())
		act(() => result.current.show('Password copied'))
		act(() => vi.advanceTimersByTime(2000))
		act(() => result.current.show('Password copied'))
		act(() => vi.advanceTimersByTime(2000))
		expect(result.current.message).toBe('Password copied')
		act(() => vi.advanceTimersByTime(500))
		expect(result.current.message).toBeNull()
	})
})
