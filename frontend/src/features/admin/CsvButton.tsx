import { useState } from 'react'
import { Button } from '../../components/ui'
import { useToast } from '../../components/useToast'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { downloadCsv } from './api'

/** "Download CSV": fetches an admin export and saves it as a file. */
export function CsvButton({ path, fileName, label }: { path: string; fileName: string; label: string }) {
  const toast = useToast()
  const [busy, setBusy] = useState(false)

  const download = async () => {
    setBusy(true)
    try {
      await downloadCsv(path, fileName)
    } catch (e) {
      toast.push({ tone: 'error', title: 'Download failed', body: friendlyMessage(parseApiError(e)) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <Button variant="secondary" busy={busy} onClick={download}>
      {!busy && (
        <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
          <path d="M12 4v11M7 10l5 5 5-5M5 20h14" />
        </svg>
      )}
      {label}
    </Button>
  )
}
