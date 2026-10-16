const formatter = new Intl.NumberFormat('en-LK', { maximumFractionDigits: 0 })

/** Money is stored as whole rupees; formatting happens only for display. */
export function formatRs(amount: number | null | undefined): string {
  if (amount === null || amount === undefined) {
    return '—'
  }

  return `Rs. ${formatter.format(amount)}`
}
