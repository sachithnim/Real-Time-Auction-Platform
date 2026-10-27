import { describe, expect, it } from 'vitest'
import { emptyListMessage } from '../emptyList'

describe('emptyListMessage', () => {
  it('explains the watchlist and how to fill it', () => {
    const message = emptyListMessage('WATCHING', null)

    expect(message.title).toBe('You are not watching any auctions yet')
    expect(message.body).toContain('☆ Watch')
    expect(message.clear).toBe('status')
  })

  it('offers to clear the category first when one is chosen', () => {
    expect(emptyListMessage('LIVE', 'art').clear).toBe('category')
    expect(emptyListMessage('WATCHING', 'art').clear).toBe('category')
  })

  it.each([
    ['LIVE', 'No auctions are live right now'],
    ['SCHEDULED', 'No upcoming auctions'],
    ['COMPLETED', 'No finished auctions yet'],
  ] as const)('names the empty %s tab and offers to show all', (status, title) => {
    const message = emptyListMessage(status, null)

    expect(message.title).toBe(title)
    expect(message.clear).toBe('status')
  })

  it('has nothing to clear when there are no auctions at all', () => {
    expect(emptyListMessage('ALL', null)).toMatchObject({ title: 'No auctions yet', clear: null })
  })
})
