import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useNavigate, useParams } from 'react-router'
import { Alert, Button, Card, Field, PageLoader } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { fieldErrors, friendlyMessage, parseApiError } from '../../lib/apiError'
import type { AdminAuction } from '../../types/api'
import { adminKeys, createAuction, fetchAdminAuction, fetchAdminCategories, updateAuction, type AuctionInput } from './api'
import { auctionFormSchema, isoToLocalInput, localInputToIso, type AuctionFormValues } from './auctionForm'

export function AdminAuctionFormPage() {
  const { id: idParam } = useParams()
  useDocumentTitle(idParam ? `Edit auction #${idParam}` : 'New auction')
  const id = idParam ? Number(idParam) : null
  const existing = useQuery({ queryKey: adminKeys.detail(id ?? 0), queryFn: () => fetchAdminAuction(id!), enabled: id !== null })

  if (id !== null && existing.isPending) {
    return <PageLoader />
  }
  if (existing.isError) {
    return <Alert>{friendlyMessage(parseApiError(existing.error))}</Alert>
  }

  return <AuctionForm auction={existing.data ?? null} />
}

function defaults(auction: AdminAuction | null): Partial<AuctionFormValues> {
  if (!auction) {
    return { anti_snipe_seconds: 120, extension_seconds: 120, max_extensions: 3, min_increment: 100, reserve_price: null, category_id: null }
  }
  return {
    title: auction.title,
    description: auction.description,
    category_id: auction.category_id,
    starting_price: auction.starting_price,
    reserve_price: auction.reserve_price,
    min_increment: auction.min_increment,
    start_at: isoToLocalInput(auction.start_at),
    end_at: isoToLocalInput(auction.end_at),
    anti_snipe_seconds: auction.anti_snipe_seconds,
    extension_seconds: auction.extension_seconds,
    max_extensions: auction.max_extensions,
  }
}

const numberField = { valueAsNumber: true } as const

function AuctionForm({ auction }: { auction: AdminAuction | null }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const form = useForm<AuctionFormValues>({ resolver: zodResolver(auctionFormSchema), defaultValues: defaults(auction) })
  const errors = form.formState.errors
  const categories = useQuery({ queryKey: adminKeys.categories, queryFn: fetchAdminCategories })

  const save = (status: 'DRAFT' | 'SCHEDULED') =>
    form.handleSubmit(async (values) => {
      setError(null)
      const input: AuctionInput = {
        ...values,
        start_at: localInputToIso(values.start_at),
        end_at: localInputToIso(values.end_at),
        status: auction ? undefined : status,
      }
      try {
        const saved = auction ? await updateAuction(auction.id, input) : await createAuction(input)
        queryClient.invalidateQueries({ queryKey: adminKeys.all })
        navigate(`/admin/auctions/${saved.id}`)
      } catch (e) {
        const info = parseApiError(e)
        const fields = fieldErrors(info)
        const known = Object.keys(fields).filter((f) => f in values) as (keyof AuctionFormValues)[]
        known.forEach((field) => form.setError(field, { message: fields[field] }))
        if (known.length === 0) {
          setError(friendlyMessage(info))
        }
      }
    })

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <h1 className="text-2xl font-bold">{auction ? `Edit auction #${auction.id}` : 'New auction'}</h1>
      <Card>
        <form className="space-y-5" noValidate onSubmit={save('DRAFT')}>
          {error && <Alert>{error}</Alert>}
          <Field label="Title" {...form.register('title')} error={errors.title?.message} />
          <div className="space-y-1">
            <label htmlFor="description" className="block text-sm font-medium text-slate-700">Description</label>
            <textarea
              id="description"
              rows={4}
              aria-invalid={errors.description ? true : undefined}
              aria-describedby={errors.description ? 'description-error' : undefined}
              className="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
              {...form.register('description')}
            />
            {errors.description && <p id="description-error" role="alert" className="text-xs text-red-600">{errors.description.message}</p>}
          </div>
          <div className="space-y-1">
            <label htmlFor="category_id" className="block text-sm font-medium text-slate-700">Category</label>
            <select
              // Re-attach once the options exist, so an edited auction keeps its saved category.
              key={categories.data ? 'loaded' : 'loading'}
              id="category_id"
              className="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
              {...form.register('category_id', { setValueAs: (v) => (v === '' || v === null ? null : Number(v)) })}
            >
              <option value="">No category</option>
              {categories.data?.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
            {errors.category_id && <p role="alert" className="text-xs text-red-600">{errors.category_id.message}</p>}
          </div>

          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Starting price (Rs.)" type="number" step={1} {...form.register('starting_price', numberField)} error={errors.starting_price?.message} />
            <Field
              label="Reserve price (Rs., private)"
              type="number"
              step={1}
              {...form.register('reserve_price', { setValueAs: (v) => (v === '' || v === null ? null : Number(v)) })}
              error={errors.reserve_price?.message}
              hint="Optional. Never shown to bidders."
            />
            <Field label="Minimum increment (Rs.)" type="number" step={1} {...form.register('min_increment', numberField)} error={errors.min_increment?.message} hint="Price tiers may require more." />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Starts (your local time)" type="datetime-local" {...form.register('start_at')} error={errors.start_at?.message} />
            <Field label="Ends (your local time)" type="datetime-local" {...form.register('end_at')} error={errors.end_at?.message} />
          </div>

          <fieldset className="grid gap-4 sm:grid-cols-3">
            <legend className="mb-2 text-sm font-semibold text-slate-700">Anti-sniping</legend>
            <Field label="Window (seconds)" type="number" step={1} {...form.register('anti_snipe_seconds', numberField)} error={errors.anti_snipe_seconds?.message} hint="0 turns it off" />
            <Field label="Extension (seconds)" type="number" step={1} {...form.register('extension_seconds', numberField)} error={errors.extension_seconds?.message} />
            <Field label="Max extensions" type="number" step={1} {...form.register('max_extensions', numberField)} error={errors.max_extensions?.message} />
          </fieldset>

          <div className="flex flex-wrap justify-end gap-3 border-t border-slate-100 pt-4">
            <Button type="button" variant="secondary" onClick={() => navigate(-1)}>Cancel</Button>
            <Button type="submit" variant={auction ? 'primary' : 'secondary'} busy={form.formState.isSubmitting}>
              {auction ? 'Save changes' : 'Save as draft'}
            </Button>
            {!auction && (
              <Button type="button" busy={form.formState.isSubmitting} onClick={save('SCHEDULED')}>
                Save and schedule
              </Button>
            )}
          </div>
        </form>
      </Card>
    </div>
  )
}
