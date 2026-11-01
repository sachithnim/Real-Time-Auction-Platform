import { useEffect, useId, useMemo, useState, type DragEvent } from 'react'
import { Alert } from '../../components/ui'
import { MAX_PHOTOS, moveItem, PHOTO_TYPES, pickPhotos } from '../../lib/photos'

const iconButton = 'flex h-8 items-center justify-center rounded-md bg-[#ffffff]/95 px-2 text-xs font-semibold text-[#0f172a] shadow ring-1 ring-black/10 hover:bg-[#ffffff]'

/**
 * Photos chosen on the "New auction" form, before the auction exists. They stay in
 * the browser (previews only) and are uploaded right after the auction is created.
 */
export function PhotoPicker({ files, onChange }: { files: File[]; onChange: (files: File[]) => void }) {
  const inputId = useId()
  const [problems, setProblems] = useState<string[]>([])
  const [dragging, setDragging] = useState(false)

  // One preview address per file; released when the file is dropped from the list.
  const previews = useMemo(() => files.map((file) => URL.createObjectURL(file)), [files])
  useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews])

  const add = (list: FileList | null) => {
    if (!list || list.length === 0) {
      return
    }
    const { accepted, problems } = pickPhotos(Array.from(list), files.length)
    setProblems(problems)
    if (accepted.length > 0) {
      onChange([...files, ...(accepted as File[])])
    }
  }
  const onDrop = (e: DragEvent) => {
    e.preventDefault()
    setDragging(false)
    add(e.dataTransfer.files)
  }

  return (
    <fieldset className="space-y-3">
      <legend className="text-sm font-semibold text-slate-700">
        Photos <span className="font-normal text-slate-500">(optional, {files.length} of {MAX_PHOTOS})</span>
      </legend>
      <p className="-mt-1 text-xs text-slate-500">The first photo is the cover. JPG, PNG or WebP, up to 5 MB each. They upload when you save.</p>

      {problems.length > 0 && (
        <Alert tone="warning">
          <ul className="list-disc space-y-0.5 pl-4">
            {problems.map((problem) => (
              <li key={problem}>{problem}</li>
            ))}
          </ul>
        </Alert>
      )}

      {files.length > 0 && (
        <ol className="grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="Chosen photos">
          {files.map((file, i) => (
            <li key={previews[i]} className="relative overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200">
              <img src={previews[i]} alt={`Chosen photo ${i + 1}: ${file.name}`} className="aspect-[4/3] w-full object-cover" />
              {i === 0 && <span className="absolute top-2 left-2 rounded-full bg-indigo-600 px-2 py-0.5 text-xs font-semibold text-white">Cover</span>}
              <div className="absolute inset-x-2 bottom-2 flex justify-between gap-1">
                {i > 0 ? (
                  <button type="button" className={iconButton} onClick={() => onChange(moveItem(files, i, 0))}>
                    Make cover
                  </button>
                ) : (
                  <span />
                )}
                <button
                  type="button"
                  className={`${iconButton} w-8 px-0 text-[#b91c1c]`}
                  onClick={() => onChange(files.filter((_, j) => j !== i))}
                  aria-label={`Remove chosen photo ${i + 1}`}
                >
                  <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" aria-hidden>
                    <path d="M6 6l12 12M18 6L6 18" />
                  </svg>
                </button>
              </div>
            </li>
          ))}
        </ol>
      )}

      {files.length < MAX_PHOTOS && (
        <label
          htmlFor={inputId}
          onDragOver={(e) => {
            e.preventDefault()
            setDragging(true)
          }}
          onDragLeave={() => setDragging(false)}
          onDrop={onDrop}
          className={`flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed px-4 py-6 text-center transition ${
            dragging ? 'border-indigo-500 bg-indigo-50' : 'border-slate-300 hover:border-indigo-400 hover:bg-slate-50'
          }`}
        >
          <svg viewBox="0 0 24 24" className="h-7 w-7 text-slate-400" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
            <path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3" />
          </svg>
          <span className="text-sm font-medium text-slate-700">
            <span className="text-indigo-700">Choose photos</span> or drag them here
          </span>
          <input
            id={inputId}
            type="file"
            multiple
            accept={PHOTO_TYPES.join(',')}
            className="sr-only"
            onChange={(e) => {
              add(e.target.files)
              e.target.value = ''
            }}
          />
        </label>
      )}
    </fieldset>
  )
}
