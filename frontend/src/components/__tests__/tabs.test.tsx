import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it } from 'vitest'
import { onTabListKeyDown } from '../tabs'

function Tabs() {
  const [selected, setSelected] = useState('All')
  return (
    <div role="tablist" aria-label="Filter" onKeyDown={onTabListKeyDown}>
      {['All', 'Live', 'Finished'].map((tab) => (
        <button key={tab} type="button" role="tab" aria-selected={selected === tab} tabIndex={selected === tab ? 0 : -1} onClick={() => setSelected(tab)}>
          {tab}
        </button>
      ))}
    </div>
  )
}

const tab = (name: string) => screen.getByRole('tab', { name })

describe('onTabListKeyDown', () => {
  it('enters the list once, on the selected tab', async () => {
    render(<Tabs />)

    await userEvent.tab()
    expect(tab('All')).toHaveFocus()
    expect(tab('Live')).toHaveAttribute('tabindex', '-1')
  })

  it('moves and selects with the arrow keys, wrapping at the ends', async () => {
    render(<Tabs />)
    await userEvent.tab()

    await userEvent.keyboard('{ArrowRight}')
    expect(tab('Live')).toHaveFocus()
    expect(tab('Live')).toHaveAttribute('aria-selected', 'true')

    await userEvent.keyboard('{ArrowLeft}{ArrowLeft}')
    expect(tab('Finished')).toHaveFocus()
    expect(tab('Finished')).toHaveAttribute('aria-selected', 'true')
  })

  it('jumps to the first and last tab with Home and End', async () => {
    render(<Tabs />)
    await userEvent.tab()

    await userEvent.keyboard('{End}')
    expect(tab('Finished')).toHaveFocus()
    await userEvent.keyboard('{Home}')
    expect(tab('All')).toHaveFocus()
    expect(tab('All')).toHaveAttribute('aria-selected', 'true')
  })
})
