import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Pagination } from '../../components/Pagination'
import { TableSkeleton } from '../../components/Skeleton'
import { stackedTable } from '../../components/stackedTable'
import { onTabListKeyDown } from '../../components/tabs'
import { Alert, Button, Card } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { useToast } from '../../components/useToast'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import type { AdminBidder } from '../../types/api'
import { adminKeys, disableBidder, enableBidder, fetchBidders, type BidderFilter } from './api'

const tabs: { value: BidderFilter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'disabled', label: 'Disabled' },
]

/** Bidder accounts: find one, and block or unblock it. */
export function AdminBiddersPage() {
  useDocumentTitle('Bidders')
  const [status, setStatus] = useState<BidderFilter>('all')
  const [draft, setDraft] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const bidders = useQuery({
    queryKey: adminKeys.bidders(status, search, page),
    queryFn: () => fetchBidders(status, search, page),
    placeholderData: keepPreviousData,
  })

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Bidders</h1>
          <p className="max-w-2xl text-sm text-slate-500">
            Disabling an account signs it out everywhere and stops its automatic bids. Bids already placed stay in the history.
          </p>
        </div>
        <div className="flex gap-1 rounded-lg bg-white p-1 ring-1 ring-slate-200" role="tablist" aria-label="Show bidders" onKeyDown={onTabListKeyDown}>
          {tabs.map((tab) => (
            <button
              key={tab.value}
              type="button"
              role="tab"
              aria-selected={status === tab.value}
              tabIndex={status === tab.value ? 0 : -1}
              onClick={() => {
                setStatus(tab.value)
                setPage(1)
              }}
              className={`rounded-md px-3 py-1.5 text-sm font-medium ${status === tab.value ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      <form
        role="search"
        className="flex gap-2"
        onSubmit={(e) => {
          e.preventDefault()
          setSearch(draft)
          setPage(1)
        }}
      >
        <input
          type="search"
          aria-label="Search bidders"
          placeholder="Search by name or email"
          maxLength={100}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          className="block w-full max-w-md rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:ring-2 focus:ring-indigo-200 focus:outline-none"
        />
        <Button type="submit" variant="secondary">
          Search
        </Button>
      </form>

      {bidders.isPending && <TableSkeleton rows={5} label="Loading bidders" />}
      {bidders.isError && <Alert>{friendlyMessage(parseApiError(bidders.error))}</Alert>}

      {bidders.data && (
        <div className="space-y-3">
          <Card className={`overflow-hidden p-0 transition-opacity ${bidders.isPlaceholderData ? 'opacity-50' : ''}`}>
            <table className={stackedTable.table}>
              <thead className={stackedTable.head}>
                <tr>
                  <th className="px-4 py-3">Bidder</th>
                  <th className="px-4 py-3">Joined</th>
                  <th className="px-4 py-3 text-right">Bids</th>
                  <th className="px-4 py-3 text-right">Wins</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody className={stackedTable.body}>
                {bidders.data.data.length === 0 ? (
                  <tr>
                    <td colSpan={6} className={stackedTable.emptyCell}>
                      {search ? `No bidders match “${search}”.` : 'No bidders here.'}
                    </td>
                  </tr>
                ) : (
                  bidders.data.data.map((bidder) => <BidderRow key={bidder.id} bidder={bidder} />)
                )}
              </tbody>
            </table>
          </Card>
          {bidders.data.data.length > 0 && (
            <Pagination
              page={bidders.data.meta.current_page}
              lastPage={bidders.data.meta.last_page}
              perPage={bidders.data.meta.per_page}
              total={bidders.data.meta.total}
              noun={['bidder', 'bidders']}
              onChange={setPage}
            />
          )}
        </div>
      )}
    </div>
  )
}

function BidderRow({ bidder }: { bidder: AdminBidder }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [confirming, setConfirming] = useState(false)
  const disabled = bidder.disabled_at !== null

  const change = useMutation({
    mutationFn: () => (disabled ? enableBidder(bidder.id) : disableBidder(bidder.id)),
    onSuccess: (saved) => {
      setConfirming(false)
      queryClient.invalidateQueries({ queryKey: ['admin', 'bidders'] })
      toast.push(
        saved.disabled_at
          ? { tone: 'success', title: `${saved.name} is disabled`, body: 'Signed out everywhere; automatic bids stopped.' }
          : { tone: 'success', title: `${saved.name} can log in again`, body: 'Their automatic bids stay off until they set new ones.' },
      )
    },
    onError: (e) => toast.push({ tone: 'error', title: 'Not changed', body: friendlyMessage(parseApiError(e)) }),
  })

  return (
    <tr className={stackedTable.row}>
      <td className={stackedTable.titleCell}>
        <span className="font-medium">{bidder.name}</span>
        <span className="block text-xs text-slate-500">{bidder.email}</span>
      </td>
      <td className={stackedTable.cell} data-label="Joined">
        {new Date(bidder.joined_at).toLocaleDateString()}
      </td>
      <td className={`${stackedTable.cell} tabular-nums md:text-right`} data-label="Bids">
        {bidder.bids_count}
      </td>
      <td className={`${stackedTable.cell} tabular-nums md:text-right`} data-label="Wins">
        {bidder.wins_count}
      </td>
      <td className={stackedTable.cell} data-label="Status">
        {disabled ? (
          <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800" title={`Since ${new Date(bidder.disabled_at!).toLocaleString()}`}>
            Disabled
          </span>
        ) : (
          <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">Active</span>
        )}
      </td>
      <td className="block pt-2 md:table-cell md:px-4 md:py-3 md:text-right">
        {disabled ? (
          <Button variant="secondary" busy={change.isPending} onClick={() => change.mutate()}>
            Enable
          </Button>
        ) : confirming ? (
          <span className="inline-flex flex-wrap items-center justify-end gap-2" role="group" aria-label={`Confirm disabling ${bidder.name}`}>
            <span className="text-xs text-red-800">Disable {bidder.name}?</span>
            <Button variant="secondary" onClick={() => setConfirming(false)}>
              Keep
            </Button>
            <Button variant="danger" busy={change.isPending} onClick={() => change.mutate()}>
              Yes, disable
            </Button>
          </span>
        ) : (
          <Button variant="secondary" onClick={() => setConfirming(true)}>
            Disable
          </Button>
        )}
      </td>
    </tr>
  )
}
