import { useQuery } from '@tanstack/react-query'
import { useEffect, useRef, useState, type KeyboardEvent, type PointerEvent } from 'react'
import { Card } from '../../components/ui'
import { useNow } from '../../components/useNow'
import { formatRs } from '../../lib/money'
import { formatDateTime, formatRelative } from '../../lib/time'
import type { AuctionStatus } from '../../types/api'
import { auctionKeys, fetchPriceHistory } from './api'
import { axisLabel, buildChart, chartSummary, nearestMark, type ChartBox, type PricePoint } from './priceChartModel'

const HEIGHT = 200
/** Room for the price labels on the left and the times underneath. */
const BOX: Omit<ChartBox, 'width'> = { height: HEIGHT, left: 64, right: 12, top: 12, bottom: 24 }
/** Past this many bids the dots crowd the line: only yours and the latest keep one. */
const MAX_MARKERS = 60

/**
 * How the price climbed: a step line (the price holds until the next bid), a dot
 * per bid (filled for manual, hollow for automatic, a diamond for your own), and a
 * crosshair tooltip on hover or with the arrow keys. The bid history below it is
 * the same data as a table.
 */
export function PriceChart({ auctionId, status }: { auctionId: number; status: AuctionStatus }) {
  const history = useQuery({ queryKey: auctionKeys.priceHistory(auctionId), queryFn: () => fetchPriceHistory(auctionId) })
  const now = useNow(15_000)
  const [width, setWidth] = useState(640)
  const frame = useRef<HTMLDivElement>(null)
  const [active, setActive] = useState<number | null>(null)

  // The SVG is drawn at the card's real width, so text and dots keep their size on any screen.
  useEffect(() => {
    const element = frame.current
    if (!element || typeof ResizeObserver === 'undefined') {
      return
    }
    const observer = new ResizeObserver(([entry]) => setWidth(Math.max(280, Math.round(entry.contentRect.width))))
    observer.observe(element)
    return () => observer.disconnect()
  }, [history.data])

  const points = history.data?.points ?? []
  if (points.length === 0) {
    return null
  }

  const live = status === 'LIVE'
  const endMs = live ? now : Date.parse(history.data!.end_at)
  const chart = buildChart(history.data!, endMs, { ...BOX, width })
  const showAll = points.length <= MAX_MARKERS
  const hasMine = points.some((p) => p.mine)
  const hasAuto = points.some((p) => p.type === 'AUTO')
  const selected = active === null ? null : chart.marks[Math.min(active, chart.marks.length - 1)]

  const onPointerMove = (e: PointerEvent<SVGSVGElement>) => {
    const rect = e.currentTarget.getBoundingClientRect()
    setActive(nearestMark(chart.marks, e.clientX - rect.left))
  }
  const onKeyDown = (e: KeyboardEvent<SVGSVGElement>) => {
    const lastIndex = chart.marks.length - 1
    const step = { ArrowLeft: -1, ArrowRight: 1 }[e.key]
    if (step !== undefined) {
      e.preventDefault()
      setActive((i) => Math.min(lastIndex, Math.max(0, (i ?? lastIndex) + step)))
    } else if (e.key === 'Home' || e.key === 'End') {
      e.preventDefault()
      setActive(e.key === 'Home' ? 0 : lastIndex)
    } else if (e.key === 'Escape') {
      setActive(null)
    }
  }

  return (
    <Card>
      <div className="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
        <h2 className="font-semibold">Price history</h2>
        {/* What each dot shape means; the line itself needs no key (the title names it). */}
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600" aria-label="Key">
          <li className="flex items-center gap-1.5">
            <Dot kind="manual" /> Bid
          </li>
          {hasAuto && (
            <li className="flex items-center gap-1.5">
              <Dot kind="auto" /> Automatic bid
            </li>
          )}
          {hasMine && (
            <li className="flex items-center gap-1.5">
              <Dot kind="mine" /> Your bid
            </li>
          )}
        </ul>
      </div>

      <div ref={frame} className="relative">
        <svg
          width={width}
          height={HEIGHT}
          viewBox={`0 0 ${width} ${HEIGHT}`}
          className="block max-w-full touch-pan-y overflow-visible rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600"
          role="img"
          aria-label={chartSummary(points)}
          tabIndex={0}
          onPointerMove={onPointerMove}
          onPointerLeave={() => setActive(null)}
          onKeyDown={onKeyDown}
          onFocus={() => setActive((i) => i ?? chart.marks.length - 1)}
          onBlur={() => setActive(null)}
        >
          {/* Hairline gridlines and price labels: recessive, so the line is the loud part. */}
          {chart.ticks.map((tick) => (
            <g key={tick}>
              <line x1={BOX.left} x2={width - BOX.right} y1={chart.yOf(tick)} y2={chart.yOf(tick)} className="stroke-slate-200" strokeWidth={1} />
              <text x={BOX.left - 8} y={chart.yOf(tick)} dy="0.32em" textAnchor="end" className="fill-slate-500 text-[11px] tabular-nums">
                {axisLabel(tick, chart.compact)}
              </text>
            </g>
          ))}

          <path d={chart.area} className="fill-indigo-600" fillOpacity={0.1} />
          <path d={chart.line} className="stroke-indigo-600" fill="none" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />

          {chart.marks.map((mark, i) =>
            showAll || mark.point.mine || i === chart.marks.length - 1 ? <Marker key={i} x={mark.x} y={mark.y} point={mark.point} /> : null,
          )}

          {selected && (
            <g aria-hidden>
              <line x1={selected.x} x2={selected.x} y1={BOX.top} y2={HEIGHT - BOX.bottom} className="stroke-slate-400" strokeWidth={1} />
              <Marker x={selected.x} y={selected.y} point={selected.point} large />
            </g>
          )}

          <text x={chart.xStart} y={HEIGHT - 6} className="fill-slate-500 text-[11px]">
            {shortTime(points[0].at)}
          </text>
          <text x={chart.xEnd} y={HEIGHT - 6} textAnchor="end" className="fill-slate-500 text-[11px]">
            {live ? 'Now' : shortTime(history.data!.end_at)}
          </text>
        </svg>

        {selected && <Tooltip x={selected.x} y={selected.y} width={width} point={selected.point} now={now} />}
      </div>
    </Card>
  )
}

/** A bid's dot: filled for a manual bid, hollow for an automatic one, a diamond when it is yours. */
function Marker({ x, y, point, large = false }: { x: number; y: number; point: PricePoint; large?: boolean }) {
  const r = large ? 6 : 4
  // The surface-coloured ring keeps a dot readable where it sits on the line.
  const fill = point.type === 'AUTO' ? 'fill-white stroke-indigo-600' : 'fill-indigo-600 stroke-white'
  const strokeWidth = 2

  if (point.mine) {
    const d = r + 1.5
    return <path d={`M${x},${y - d}L${x + d},${y}L${x},${y + d}L${x - d},${y}Z`} className={fill} strokeWidth={strokeWidth} />
  }
  return <circle cx={x} cy={y} r={r} className={fill} strokeWidth={strokeWidth} />
}

function Dot({ kind }: { kind: 'manual' | 'auto' | 'mine' }) {
  return (
    <svg viewBox="0 0 12 12" className="h-3 w-3" aria-hidden>
      {kind === 'mine' ? (
        <path d="M6,1L11,6L6,11L1,6Z" className="fill-indigo-600" />
      ) : (
        <circle cx={6} cy={6} r={4} className={kind === 'auto' ? 'fill-white stroke-indigo-600' : 'fill-indigo-600'} strokeWidth={2} />
      )}
    </svg>
  )
}

/** The value leads, the details follow; kept inside the chart's edges. */
function Tooltip({ x, y, width, point, now }: { x: number; y: number; width: number; point: PricePoint; now: number }) {
  const left = Math.min(Math.max(x, 80), width - 80)
  const above = y > 70

  return (
    <div
      role="status"
      className="pointer-events-none absolute z-10 w-max max-w-[11rem] -translate-x-1/2 rounded-md bg-white px-2.5 py-1.5 text-xs shadow-lg ring-1 ring-slate-200"
      style={{ left, top: above ? y - 12 : y + 14, transform: `translate(-50%, ${above ? '-100%' : '0'})` }}
    >
      <div className="text-sm font-semibold text-slate-900 tabular-nums">{formatRs(point.amount)}</div>
      <div className="text-slate-600">
        {point.mine ? 'Your ' : ''}
        {point.type === 'AUTO' ? (point.mine ? 'automatic bid' : 'Automatic bid') : point.mine ? 'bid' : 'Bid'}
      </div>
      <time dateTime={point.at} title={formatDateTime(point.at)} className="text-slate-500">
        {/* "2 min ago" while recent; the full date and time after a day. */}
        {now - Date.parse(point.at) < 86_400_000 ? formatRelative(point.at, now) : formatDateTime(point.at)}
      </time>
    </div>
  )
}

/** "14:05" for today, "3 Nov 14:05" otherwise. */
function shortTime(iso: string): string {
  const date = new Date(iso)
  const time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  return date.toDateString() === new Date().toDateString() ? time : `${date.toLocaleDateString([], { day: 'numeric', month: 'short' })} ${time}`
}
