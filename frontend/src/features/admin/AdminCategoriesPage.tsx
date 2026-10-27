import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Alert, Button, Card, PageLoader } from '../../components/ui'
import { useToast } from '../../components/useToast'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { fieldErrors, friendlyMessage, parseApiError } from '../../lib/apiError'
import type { Category } from '../../types/api'
import { adminKeys, createCategory, deleteCategory, fetchAdminCategories, renameCategory } from './api'

/** The message to show for a failed save: the name problem if there is one. */
function saveError(error: unknown): string {
  const info = parseApiError(error)
  return fieldErrors(info).name ?? friendlyMessage(info)
}

/** Admin management of auction categories (bonus challenge: multiple auction categories). */
export function AdminCategoriesPage() {
  useDocumentTitle('Categories')
  const queryClient = useQueryClient()
  const categories = useQuery({ queryKey: adminKeys.categories, queryFn: fetchAdminCategories })
  const [name, setName] = useState('')
  const [error, setError] = useState<string | null>(null)

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: adminKeys.categories })
    queryClient.invalidateQueries({ queryKey: ['categories'] })
  }

  const create = useMutation({
    mutationFn: () => createCategory(name.trim()),
    onSuccess: () => {
      setName('')
      setError(null)
      refresh()
    },
    onError: (e) => setError(saveError(e)),
  })

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (name.trim() === '') {
      setError('Enter a category name.')
      return
    }
    create.mutate()
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Categories</h1>
        <p className="text-sm text-slate-500">Bidders filter the auction list by these. A category in use cannot be deleted.</p>
      </div>

      <Card>
        <form onSubmit={onSubmit} className="flex flex-wrap items-end gap-3" noValidate>
          <div className="min-w-0 flex-1 space-y-1">
            <label htmlFor="new-category" className="block text-sm font-medium text-slate-700">New category</label>
            <input
              id="new-category"
              value={name}
              onChange={(e) => setName(e.target.value)}
              maxLength={60}
              placeholder="e.g. Coins & stamps"
              className="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            />
          </div>
          <Button type="submit" busy={create.isPending}>Add category</Button>
        </form>
        {error && <div className="mt-3"><Alert>{error}</Alert></div>}
      </Card>

      {categories.isPending && <PageLoader />}
      {categories.isError && <Alert>{friendlyMessage(parseApiError(categories.error))}</Alert>}
      {categories.data && (
        <Card className="p-0">
          {categories.data.length === 0 ? (
            <p className="px-5 py-8 text-center text-sm text-slate-500">No categories yet. Add the first one above.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {categories.data.map((category) => (
                <CategoryRow key={category.id} category={category} onChanged={refresh} />
              ))}
            </ul>
          )}
        </Card>
      )}
    </div>
  )
}

function CategoryRow({ category, onChanged }: { category: Category; onChanged: () => void }) {
  const toast = useToast()
  const [editing, setEditing] = useState(false)
  const [name, setName] = useState(category.name)
  const [error, setError] = useState<string | null>(null)
  const used = category.auctions_count ?? 0

  const rename = useMutation({
    mutationFn: () => renameCategory(category.id, name.trim()),
    onSuccess: () => {
      setEditing(false)
      setError(null)
      onChanged()
    },
    onError: (e) => setError(saveError(e)),
  })

  const remove = useMutation({
    mutationFn: () => deleteCategory(category.id),
    onSuccess: () => {
      toast.push({ tone: 'success', title: 'Category deleted', body: category.name })
      onChanged()
    },
    onError: (e) => setError(friendlyMessage(parseApiError(e))),
  })

  return (
    <li className="space-y-2 px-5 py-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        {editing ? (
          <input
            aria-label={`New name for ${category.name}`}
            value={name}
            onChange={(e) => setName(e.target.value)}
            maxLength={60}
            className="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm"
          />
        ) : (
          <div className="min-w-0">
            <div className="font-medium">{category.name}</div>
            <div className="text-xs text-slate-500">
              {used === 1 ? '1 auction' : `${used} auctions`} · /{category.slug}
            </div>
          </div>
        )}
        <div className="flex gap-2">
          {editing ? (
            <>
              <Button variant="secondary" onClick={() => { setEditing(false); setName(category.name); setError(null) }}>Cancel</Button>
              <Button busy={rename.isPending} onClick={() => rename.mutate()}>Save</Button>
            </>
          ) : (
            <>
              <Button variant="secondary" onClick={() => setEditing(true)}>Rename</Button>
              <Button
                variant="secondary"
                busy={remove.isPending}
                disabled={used > 0}
                title={used > 0 ? 'Used by auctions: move them to another category first.' : undefined}
                onClick={() => remove.mutate()}
              >
                Delete
              </Button>
            </>
          )}
        </div>
      </div>
      {error && <Alert>{error}</Alert>}
    </li>
  )
}
