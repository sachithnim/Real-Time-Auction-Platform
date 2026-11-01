import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useId, useState, type DragEvent } from 'react'
import { Alert, Card, Spinner } from '../../components/ui'
import { useToast } from '../../components/useToast'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { MAX_PHOTOS, moveItem, PHOTO_TYPES, pickPhotos } from '../../lib/photos'
import type { AdminAuction, AuctionImage } from '../../types/api'
import { auctionKeys } from '../auctions/api'
import { adminKeys, deleteAuctionImage, reorderAuctionImages, uploadAuctionImages } from './api'

const iconButton = 'flex h-8 w-8 items-center justify-center rounded-md bg-[#ffffff]/95 text-[#0f172a] shadow ring-1 ring-black/10 hover:bg-[#ffffff] disabled:opacity-40'

/**
 * Upload, order and remove an auction's photos. Editable until the auction starts;
 * after that the photos are shown read-only (bidders rely on what they saw).
 */
export function PhotoManager({ auction }: { auction: AdminAuction }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const inputId = useId()
  const editable = auction.status === 'DRAFT' || auction.status === 'SCHEDULED'
  const [problems, setProblems] = useState<string[]>([])
  const [progress, setProgress] = useState<number | null>(null)
  const [dragging, setDragging] = useState(false)
  const [confirmRemove, setConfirmRemove] = useState<number | null>(null)

  // Every action answers with the full, ordered list: put it straight into the cache.
  const applyImages = (images: AuctionImage[]) => {
    queryClient.setQueryData<AdminAuction>(adminKeys.detail(auction.id), (current) => (current ? { ...current, images } : current))
    queryClient.invalidateQueries({ queryKey: ['admin', 'events', auction.id] })
    queryClient.invalidateQueries({ queryKey: auctionKeys.detail(auction.id) })
    queryClient.invalidateQueries({ queryKey: ['auctions', 'list'] })
  }
  const onError = (e: unknown) => {
    queryClient.invalidateQueries({ queryKey: adminKeys.detail(auction.id) })
    toast.push({ tone: 'error', title: 'Photos not changed', body: friendlyMessage(parseApiError(e)) })
  }

  const upload = useMutation({
    mutationFn: (files: File[]) => uploadAuctionImages(auction.id, files, setProgress),
    onSuccess: (images, files) => {
      applyImages(images)
      toast.push({ tone: 'success', title: files.length === 1 ? 'Photo added' : `${files.length} photos added` })
    },
    onError,
    onSettled: () => setProgress(null),
  })
  const reorder = useMutation({
    mutationFn: (ids: number[]) => reorderAuctionImages(auction.id, ids),
    // Show the new order at once; the server's answer confirms it.
    onMutate: (ids) => applyImages(ids.map((id) => auction.images.find((image) => image.id === id)!)),
    onSuccess: applyImages,
    onError,
  })
  const remove = useMutation({
    mutationFn: (imageId: number) => deleteAuctionImage(auction.id, imageId),
    onSuccess: (images) => {
      setConfirmRemove(null)
      applyImages(images)
      toast.push({ tone: 'success', title: 'Photo removed' })
    },
    onError,
  })

  const busy = upload.isPending || reorder.isPending || remove.isPending
  const images = auction.images
  const full = images.length >= MAX_PHOTOS

  const addFiles = (list: FileList | null) => {
    if (!list || list.length === 0) {
      return
    }
    const { accepted, problems } = pickPhotos(Array.from(list), images.length)
    setProblems(problems)
    if (accepted.length > 0) {
      upload.mutate(accepted as File[])
    }
  }
  const move = (from: number, to: number) => reorder.mutate(moveItem(images, from, to).map((image) => image.id))

  const onDrop = (e: DragEvent) => {
    e.preventDefault()
    setDragging(false)
    if (editable && !busy) {
      addFiles(e.dataTransfer.files)
    }
  }

  return (
    <Card className="space-y-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-semibold">
          Photos <span className="text-sm font-normal text-slate-500">({images.length} of {MAX_PHOTOS})</span>
        </h2>
        <p className="text-xs text-slate-500">
          {editable ? 'The first photo is the cover. JPG, PNG or WebP, up to 5 MB each.' : 'Photos are locked once the auction has started.'}
        </p>
      </div>

      {problems.length > 0 && (
        <Alert tone="warning">
          <ul className="list-disc space-y-0.5 pl-4">
            {problems.map((problem) => (
              <li key={problem}>{problem}</li>
            ))}
          </ul>
        </Alert>
      )}

      {images.length > 0 && (
        <ol className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4" aria-label="Photos in order">
          {images.map((image, i) => (
            <li key={image.id} className="group relative overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200">
              <img src={image.url} alt={`Photo ${i + 1}`} className="aspect-[4/3] w-full object-cover" />
              {i === 0 && <span className="absolute top-2 left-2 rounded-full bg-indigo-600 px-2 py-0.5 text-xs font-semibold text-white">Cover</span>}
              {editable && confirmRemove !== image.id && (
                <div className="absolute inset-x-2 bottom-2 flex justify-between gap-1">
                  <div className="flex gap-1">
                    <button type="button" className={iconButton} disabled={busy || i === 0} onClick={() => move(i, i - 1)} aria-label={`Move photo ${i + 1} earlier`}>
                      <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                        <path d="M15 18l-6-6 6-6" />
                      </svg>
                    </button>
                    <button type="button" className={iconButton} disabled={busy || i === images.length - 1} onClick={() => move(i, i + 1)} aria-label={`Move photo ${i + 1} later`}>
                      <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                        <path d="M9 18l6-6-6-6" />
                      </svg>
                    </button>
                    {i > 0 && (
                      <button type="button" className={`${iconButton} w-auto px-2 text-xs font-semibold`} disabled={busy} onClick={() => move(i, 0)}>
                        Make cover
                      </button>
                    )}
                  </div>
                  <button type="button" className={`${iconButton} text-[#b91c1c]`} disabled={busy} onClick={() => setConfirmRemove(image.id)} aria-label={`Remove photo ${i + 1}`}>
                    <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                      <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" />
                    </svg>
                  </button>
                </div>
              )}
              {confirmRemove === image.id && (
                <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-[#0f172a]/75 p-2 text-center">
                  <p className="text-sm font-medium text-[#ffffff]">Remove this photo?</p>
                  <div className="flex gap-2">
                    <button type="button" className="rounded-md bg-[#dc2626] px-3 py-1 text-xs font-semibold text-[#ffffff] hover:bg-[#b91c1c]" disabled={busy} onClick={() => remove.mutate(image.id)}>
                      {remove.isPending ? 'Removing…' : 'Remove'}
                    </button>
                    <button type="button" className="rounded-md bg-[#ffffff] px-3 py-1 text-xs font-semibold text-[#0f172a]" onClick={() => setConfirmRemove(null)}>
                      Keep
                    </button>
                  </div>
                </div>
              )}
            </li>
          ))}
        </ol>
      )}

      {editable && !full && (
        <label
          htmlFor={inputId}
          onDragOver={(e) => {
            e.preventDefault()
            setDragging(true)
          }}
          onDragLeave={() => setDragging(false)}
          onDrop={onDrop}
          className={`flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed px-4 py-8 text-center transition ${
            dragging ? 'border-indigo-500 bg-indigo-50' : 'border-slate-300 hover:border-indigo-400 hover:bg-slate-50'
          } ${busy ? 'pointer-events-none opacity-70' : ''}`}
        >
          {progress !== null ? (
            <div className="w-full max-w-xs space-y-2" role="status">
              <p className="flex items-center justify-center gap-2 text-sm font-medium text-slate-700">
                <Spinner /> Uploading… {Math.round(progress * 100)}%
              </p>
              <div className="h-1.5 overflow-hidden rounded-full bg-slate-200">
                <div className="h-full rounded-full bg-indigo-600 transition-all" style={{ width: `${Math.round(progress * 100)}%` }} />
              </div>
            </div>
          ) : (
            <>
              <svg viewBox="0 0 24 24" className="h-8 w-8 text-slate-400" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                <path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3" />
              </svg>
              <span className="text-sm font-medium text-slate-700">
                <span className="text-indigo-700">Choose photos</span> or drag them here
              </span>
              <span className="text-xs text-slate-500">
                {MAX_PHOTOS - images.length} more {MAX_PHOTOS - images.length === 1 ? 'photo' : 'photos'} allowed
              </span>
            </>
          )}
          <input
            id={inputId}
            type="file"
            multiple
            accept={PHOTO_TYPES.join(',')}
            className="sr-only"
            disabled={busy}
            onChange={(e) => {
              addFiles(e.target.files)
              // The same file can be picked again after a failed upload.
              e.target.value = ''
            }}
          />
        </label>
      )}

      {!editable && images.length === 0 && <p className="text-sm text-slate-500">This auction has no photos.</p>}
    </Card>
  )
}
