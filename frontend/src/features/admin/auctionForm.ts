import { z } from 'zod'

/** "2030-05-01T15:30" in the admin's local time zone → UTC ISO string for the API. */
export function localInputToIso(value: string): string {
  return new Date(value).toISOString()
}

/** UTC ISO string from the API → value for a datetime-local input in local time. */
export function isoToLocalInput(iso: string): string {
  const date = new Date(iso)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

const money = (label: string) =>
  z.number({ error: `Enter the ${label} in rupees.` }).int('Use whole rupees.').min(1, `The ${label} must be at least Rs. 1.`)

/** Mirrors the server's rules for a quick answer; the server validates again. */
export const auctionFormSchema = z
  .object({
    title: z.string().trim().min(1, 'Enter a title.').max(150),
    description: z.string().trim().min(1, 'Enter a description.').max(5000),
    starting_price: money('starting price'),
    reserve_price: z.number().int('Use whole rupees.').min(1).nullable(),
    min_increment: money('minimum increment'),
    start_at: z.string().min(1, 'Choose a start time.'),
    end_at: z.string().min(1, 'Choose an end time.'),
    anti_snipe_seconds: z.number({ error: 'Enter seconds (0 to disable).' }).int().min(0).max(3600),
    extension_seconds: z.number({ error: 'Enter seconds.' }).int().min(0).max(3600),
    max_extensions: z.number({ error: 'Enter a number.' }).int().min(0).max(50),
  })
  .superRefine((v, ctx) => {
    const start = Date.parse(v.start_at)
    const end = Date.parse(v.end_at)
    if (Number.isFinite(start) && Number.isFinite(end)) {
      if (end - start < 60_000) {
        ctx.addIssue({ code: 'custom', path: ['end_at'], message: 'The auction must end at least one minute after it starts.' })
      } else if (v.anti_snipe_seconds * 1000 >= end - start) {
        ctx.addIssue({ code: 'custom', path: ['anti_snipe_seconds'], message: 'The anti-sniping window must be shorter than the auction.' })
      }
    }
    if (v.reserve_price !== null && v.reserve_price < v.starting_price) {
      ctx.addIssue({ code: 'custom', path: ['reserve_price'], message: 'The reserve cannot be below the starting price.' })
    }
    if (v.anti_snipe_seconds > 0 && v.extension_seconds < 1) {
      ctx.addIssue({ code: 'custom', path: ['extension_seconds'], message: 'Set an extension when anti-sniping is on.' })
    }
    if (v.anti_snipe_seconds > 0 && v.max_extensions < 1) {
      ctx.addIssue({ code: 'custom', path: ['max_extensions'], message: 'Allow at least one extension when anti-sniping is on.' })
    }
  })

export type AuctionFormValues = z.infer<typeof auctionFormSchema>
