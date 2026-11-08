import { formatRs } from '../../lib/money'

export interface PricePoint {
  amount: number
  type: 'MANUAL' | 'AUTO'
  at: string
  /** The viewer's own bid. */
  mine: boolean
}

export interface PriceHistory {
  starting_price: number
  start_at: string
  end_at: string
  points: PricePoint[]
}

/**
 * Clean axis values covering [min, max]: steps of 1, 2, 2.5 or 5 times a power of
 * ten, so the axis reads 20K / 25K / 30K rather than 21,437 / 24,874.
 */
export function niceTicks(min: number, max: number, target = 4): number[] {
  // A flat price (one bid, or none changed it) still gets a band around it.
  const pad = Math.max(1, max * 0.05)
  if (max - min < pad) {
    min -= pad
    max += pad
  }
  const rough = (max - min) / target
  const power = 10 ** Math.floor(Math.log10(rough))
  const step = [1, 2, 2.5, 5, 10].map((m) => m * power).find((s) => s >= rough) ?? 10 * power

  const ticks: number[] = []
  for (let value = Math.floor(min / step) * step; value < max + step; value += step) {
    ticks.push(Math.round(value))
    if (value >= max) {
      break
    }
  }
  return ticks
}

/** Axis labels: in full under 10,000, compact above (25K, 1.2M). One style per axis. */
export function axisLabel(value: number, compact: boolean): string {
  if (!compact) {
    return formatRs(value)
  }
  if (value >= 1_000_000) {
    return `Rs. ${trim(value / 1_000_000)}M`
  }
  return `Rs. ${trim(value / 1_000)}K`
}

function trim(n: number): string {
  return Number.isInteger(n) ? String(n) : n.toFixed(1).replace(/\.0$/, '')
}

export interface ChartBox {
  width: number
  height: number
  left: number
  right: number
  top: number
  bottom: number
}

export interface ChartModel {
  ticks: number[]
  compact: boolean
  /** Screen position of each point, in the order the bids were accepted. */
  marks: { x: number; y: number; point: PricePoint }[]
  /** The price as a step line: it holds until the next bid, then jumps. */
  line: string
  /** The same shape closed down to the axis, for the soft wash under it. */
  area: string
  xStart: number
  xEnd: number
  yOf: (amount: number) => number
}

/**
 * Lays the bids out in the box. Time runs from the first bid to `endMs` (now while
 * live, the end once closed); the price axis starts at a clean value below the
 * lowest bid. A single bid, or bids in the same instant, still get some width.
 */
export function buildChart(history: PriceHistory, endMs: number, box: ChartBox): ChartModel {
  const points = history.points
  const times = points.map((p) => Date.parse(p.at))
  const first = Math.min(...times)
  // At least a minute wide, so a bid placed just now still draws a line, not a lone dot.
  const last = Math.max(endMs, first + 60_000, ...times)
  const span = last - first

  const amounts = points.map((p) => p.amount)
  const ticks = niceTicks(Math.min(...amounts), Math.max(...amounts))
  const low = ticks[0]
  const high = ticks[ticks.length - 1]

  const plotLeft = box.left
  const plotRight = box.width - box.right
  const plotTop = box.top
  const plotBottom = box.height - box.bottom

  const xOf = (ms: number) => plotLeft + ((ms - first) / span) * (plotRight - plotLeft)
  const yOf = (amount: number) => plotBottom - ((amount - low) / (high - low || 1)) * (plotBottom - plotTop)

  const marks = points.map((point, i) => ({ x: xOf(times[i]), y: yOf(point.amount), point }))
  const xEnd = xOf(last)

  let line = ''
  marks.forEach((mark, i) => {
    line += i === 0 ? `M${mark.x},${mark.y}` : `H${mark.x}V${mark.y}`
  })
  line += `H${xEnd}`
  const area = `${line}V${plotBottom}H${marks[0].x}Z`

  return { ticks, compact: high >= 10_000, marks, line, area, xStart: marks[0].x, xEnd, yOf }
}

/** The mark nearest to an x position: the crosshair snaps to a bid, not to the pointer. */
export function nearestMark(marks: ChartModel['marks'], x: number): number {
  let best = 0
  marks.forEach((mark, i) => {
    if (Math.abs(mark.x - x) < Math.abs(marks[best].x - x)) {
      best = i
    }
  })
  return best
}

/** What a screen reader hears for the whole chart. */
export function chartSummary(points: PricePoint[]): string {
  if (points.length === 0) {
    return 'No bids yet.'
  }
  const firstAmount = points[0].amount
  const lastAmount = points[points.length - 1].amount
  const bids = points.length === 1 ? '1 bid' : `${points.length} bids`
  return `Price history: ${bids}, from ${formatRs(firstAmount)} to ${formatRs(lastAmount)}. Use the left and right arrow keys to step through the bids.`
}
