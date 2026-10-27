import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../../components/toast'
import type { Category } from '../../../types/api'
import { AdminCategoriesPage } from '../AdminCategoriesPage'
import { createCategory, deleteCategory, fetchAdminCategories, renameCategory } from '../api'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchAdminCategories: vi.fn(),
  createCategory: vi.fn(),
  renameCategory: vi.fn(),
  deleteCategory: vi.fn(),
}))

const art: Category = { id: 1, name: 'Art', slug: 'art', auctions_count: 2 }
const coins: Category = { id: 2, name: 'Coins', slug: 'coins', auctions_count: 0 }

/** An axios-shaped API error, as the client receives it. */
function apiError(status: number, code: string, message: string, details: Record<string, unknown> = {}) {
  return Object.assign(new Error(message), {
    isAxiosError: true,
    response: { status, data: { error: { code, message, details } } },
  })
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <AdminCategoriesPage />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

const row = async (name: string) => within((await screen.findByText(name)).closest('li')!)

beforeEach(() => {
  vi.mocked(fetchAdminCategories).mockReset().mockResolvedValue([art, coins])
  vi.mocked(createCategory).mockReset()
  vi.mocked(renameCategory).mockReset()
  vi.mocked(deleteCategory).mockReset()
})

describe('<AdminCategoriesPage>', () => {
  it('lists categories with how many auctions use them', async () => {
    renderPage()

    expect((await row('Art')).getByText('2 auctions · /art')).toBeInTheDocument()
    expect((await row('Coins')).getByText('0 auctions · /coins')).toBeInTheDocument()
  })

  it('only lets an unused category be deleted', async () => {
    vi.mocked(deleteCategory).mockResolvedValue(undefined)
    renderPage()

    expect((await row('Art')).getByRole('button', { name: 'Delete' })).toBeDisabled()
    await userEvent.click((await row('Coins')).getByRole('button', { name: 'Delete' }))

    expect(deleteCategory).toHaveBeenCalledWith(2)
  })

  it('adds a category and shows the server reason when the name is taken', async () => {
    vi.mocked(createCategory).mockRejectedValueOnce(
      apiError(422, 'VALIDATION_FAILED', 'The given data was invalid.', { errors: { name: ['A category with a very similar name already exists.'] } }),
    )
    renderPage()

    await userEvent.type(await screen.findByLabelText('New category'), 'art!')
    await userEvent.click(screen.getByRole('button', { name: 'Add category' }))

    expect(createCategory).toHaveBeenCalledWith('art!')
    expect(await screen.findByText('A category with a very similar name already exists.')).toBeInTheDocument()
  })

  it('asks for a name instead of sending an empty one', async () => {
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: 'Add category' }))

    expect(await screen.findByText('Enter a category name.')).toBeInTheDocument()
    expect(createCategory).not.toHaveBeenCalled()
  })

  it('renames a category', async () => {
    vi.mocked(renameCategory).mockResolvedValue({ ...coins, name: 'Coins & stamps' })
    renderPage()

    await userEvent.click((await row('Coins')).getByRole('button', { name: 'Rename' }))
    const input = screen.getByLabelText('New name for Coins')
    await userEvent.clear(input)
    await userEvent.type(input, 'Coins & stamps')
    await userEvent.click(screen.getByRole('button', { name: 'Save' }))

    expect(renameCategory).toHaveBeenCalledWith(2, 'Coins & stamps')
  })
})
