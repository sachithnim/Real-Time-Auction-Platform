import { describe, expect, it } from 'vitest'
import { axisLabel, buildChart, chartSummary, nearestMark, niceTicks, type PriceHistory } from '../priceChartModel'

const box = { width: 600, height: 200, left: 60, right: 10, top: 10, bottom: 20 }

const history = (amounts: number[], minutesApart = 10): PriceHistory => ({
  starting_price: 10_000,
  start_at: '2030-01-01T10:00:00Z',
  end_at: '2030-01-01T12:00:00Z',
  points: amounts.map((amount, i) => ({
    amount,
    type: i % 2 ? 'AUTO' : 'MANUAL',
    at: new Date(Date.parse('2030-01-01T10:00:00Z') + i * minutesApart * 60_000).toISOString(),
    mine: i === 0,
  })),
})

describe('niceTicks', () => {
  it('covers the range with clean steps', () => {
    expect(niceTicks(40_000, 59_000)).toEqual([40_000, 45_000, 50_000, 55_000, 60_000])
    expect(niceTicks(10_000, 10_600)).toEqual([10_000, 10_200, 10_400, 10_600])
  })

  it('gives a flat price a band around it', () => {
    const ticks = niceTicks(25_000, 25_000)
    expect(ticks[0]).toBeLessThan(25_000)
    expect(ticks[ticks.length - 1]).toBeGreaterThan(25_000)
  })
})

describe('axisLabel', () => {
  it('is compact on an axis that reaches 10,000 and in full below', () => {
    expect(axisLabel(25_000, true)).toBe('Rs. 25K')
    expect(axisLabel(1_200_000, true)).toBe('Rs. 1.2M')
    expect(axisLabel(9_500, false)).toBe('Rs. 9,500')
  })
})

describe('buildChart', () => {
  it('draws the price as steps that hold until the next bid, up to the end', () => {
    const chart = buildChart(history([10_000, 12_000]), Date.parse('2030-01-01T10:20:00Z'), box)
    const [a, b] = chart.marks

    expect(a.x).toBe(60)
    expect(b.x).toBeGreaterThan(a.x)
    expect(b.y).toBeLessThan(a.y) // higher price, higher on screen
    expect(chart.line).toBe(`M${a.x},${a.y}H${b.x}V${b.y}H${chart.xEnd}`)
    expect(chart.xEnd).toBe(590)
    expect(chart.area.endsWith('Z')).toBe(true)
  })

  it('keeps every bid inside the plot', () => {
    const chart = buildChart(history([10_000, 10_500, 11_000, 30_000]), Date.parse('2030-01-01T11:00:00Z'), box)

    for (const mark of chart.marks) {
      expect(mark.x).toBeGreaterThanOrEqual(60)
      expect(mark.x).toBeLessThanOrEqual(590)
      expect(mark.y).toBeGreaterThanOrEqual(10)
      expect(mark.y).toBeLessThanOrEqual(180)
    }
  })

  it('still has width for a single bid', () => {
    const chart = buildChart(history([25_000]), Date.parse('2030-01-01T10:00:00Z'), box)
    expect(chart.xEnd).toBeGreaterThan(chart.xStart)
  })
})

describe('nearestMark', () => {
  it('snaps to the bid closest to the pointer', () => {
    const marks = [10, 100, 300].map((x) => ({ x, y: 0, point: history([1])!.points[0] }))
    expect(nearestMark(marks, 0)).toBe(0)
    expect(nearestMark(marks, 180)).toBe(1)
    expect(nearestMark(marks, 260)).toBe(2)
  })
})

describe('chartSummary', () => {
  it('tells a screen reader how the price moved and how to explore it', () => {
    expect(chartSummary(history([10_000, 12_500]).points)).toBe(
      'Price history: 2 bids, from Rs. 10,000 to Rs. 12,500. Use the left and right arrow keys to step through the bids.',
    )
  })
})
