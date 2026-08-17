import { useMemo } from 'react'
import ReactECharts from 'echarts-for-react'
import i18n from '../../i18n'
import { StatPoint } from '../../api/stats'

export interface ChartEvent {
  time: string
  value: number
  state: 'triggered' | 'resolved' | 'rearmed'
  // Pre-formatted, notification-style description; identical for an event shown
  // on both device lines so the tooltip can de-duplicate it.
  summary: string
}

const EVENT_COLORS: Record<ChartEvent['state'], string> = {
  triggered: '#cc0000',
  resolved: '#5a9216',
  rearmed: '#6b7280',
}

interface TimeSeriesChartProps {
  data: StatPoint[]
  label: string
  unit: string
  color?: string
  height?: number
  enabledAvgPeriods?: Period[]
  fitToData?: boolean
  showMinMax?: boolean
  data2?: StatPoint[]
  label2?: string
  color2?: string
  events?: ChartEvent[]
  eventsLabel?: string
}

interface TooltipParam {
  seriesName: string
  marker: string
  value: [string, number]
  data?: { summary?: string; originalTime?: string }
}

export type Period = 'day' | 'week' | 'month' | 'year'

const PERIOD_MS: Record<Period, number> = {
  day:   86_400_000,
  week:  7   * 86_400_000,
  month: 30  * 86_400_000,
  year:  365 * 86_400_000,
}

const AVG_KEYS: Record<Period, { key: string; color: string }> = {
  day:   { key: 'chart.avgDaily',   color: '#E8A200' },
  week:  { key: 'chart.avgWeekly',  color: '#4E9A2E' },
  month: { key: 'chart.avgMonthly', color: '#E8620D' },
  year:  { key: 'chart.avgYearly',  color: '#9B59B6' },
}

export function getAvgStyle(period: Period): { name: string; color: string } {
  const { key, color } = AVG_KEYS[period]
  return { name: i18n.t(key as never), color }
}

// Centered rolling average: each point becomes the mean of all points
// within ±half of the period window around it. Input is time-sorted ascending,
// so a forward-only two-pointer sliding window keeps this O(n) (not O(n²)).
function computeRollingAvg(data: StatPoint[], period: Period): [string, number][] {
  const half = PERIOD_MS[period] / 2
  const times = data.map((p) => new Date(p.time).getTime())
  const out: [string, number][] = []
  let left = 0, right = 0, sum = 0
  for (let i = 0; i < data.length; i++) {
    const t = times[i]
    while (right < data.length && times[right] <= t + half) { sum += data[right].value; right++ }
    while (left < right && times[left] < t - half)          { sum -= data[left].value;  left++ }
    out.push([data[i].time, sum / (right - left)])
  }
  return out
}

function buildAvgSeries(data: StatPoint[], period: Period) {
  const { name, color } = getAvgStyle(period)
  return {
    name,
    type: 'line' as const,
    smooth: true,
    showSymbol: false,
    data: computeRollingAvg(data, period),
    lineStyle: { color, width: 1.5, type: 'dashed' as const },
    itemStyle: { color },
  }
}

// ECharts' time axis silently falls back to a coarser unit at boundaries, so a
// midnight tick renders as the bare day-of-month ("2") wedged between "20:00"
// and "04:00". Label every tick ourselves, picking granularity from the span.
export function formatAxisTime(value: number, spanMs: number, locale?: string): string {
  const d = new Date(value)
  if (spanMs > 400 * PERIOD_MS.day) {
    return d.toLocaleDateString(locale, { month: 'short', year: 'numeric' })
  }
  const date = d.toLocaleDateString(locale, { day: 'numeric', month: 'short' })
  if (spanMs > 60 * PERIOD_MS.day) return date
  const time = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
  // Beyond a few days the hourly ticks thin out, so day starts carry the date.
  if (spanMs > 3 * PERIOD_MS.day) return d.getHours() === 0 && d.getMinutes() === 0 ? date : time
  return time
}

export function selectAveragePeriods(diffDays: number): Period[] {
  if (diffDays > 365) return ['year', 'month']
  if (diffDays > 30)  return ['month', 'week']
  if (diffDays > 7)   return ['week', 'day']
  return ['day']
}

/** Time covered by the chart, across both series (each is sorted ascending). */
function timeSpan(...series: (StatPoint[] | undefined)[]): number {
  const times = series.flatMap((s) =>
    s && s.length > 0 ? [new Date(s[0].time).getTime(), new Date(s[s.length - 1].time).getTime()] : [],
  )
  return times.length === 0 ? 0 : Math.max(...times) - Math.min(...times)
}

function yAxisBounds(values: number[]): { min: number; max: number } | null {
  if (values.length === 0) return null
  const lo = Math.min(...values)
  const hi = Math.max(...values)
  const range = hi - lo || 1          // avoid zero-range edge case
  const margin = range * 0.1
  return { min: Math.max(0, Math.floor(lo - margin)), max: Math.ceil(hi + margin) }
}

/**
 * Highest and lowest reading of a series; ties resolve to the earliest point.
 *
 * On an aggregated series each point carries the true extremes of its bucket,
 * so `level` is the real high or low while `point` stays the plotted vertex.
 * Without that distinction a 90-day chart would report the highest *hourly
 * average* as the maximum and quietly understate a spike; with it, the dashed
 * line sits at the real value while the dot stays on the line.
 */
export function computeExtremes(data: StatPoint[]): {
  min: StatPoint; max: StatPoint; minLevel: number; maxLevel: number
} | null {
  if (data.length === 0) return null
  let min = data[0]
  let max = data[0]
  let minLevel = data[0].min ?? data[0].value
  let maxLevel = data[0].max ?? data[0].value
  for (const p of data) {
    if (p.value < min.value) min = p
    if (p.value > max.value) max = p
    minLevel = Math.min(minLevel, p.min ?? p.value)
    maxLevel = Math.max(maxLevel, p.max ?? p.value)
  }
  return { min, max, minLevel, maxLevel }
}

// Min/max highlight: a dashed horizontal line at each extreme (so the level can be
// read off the axis) plus a dot on the exact reading (so its time is visible too).
// A flat series collapses to a single marker instead of two lines on top of each other.
function buildMinMaxMarkers(data: StatPoint[], color: string, fmtValue: (v: number) => string) {
  const ext = computeExtremes(data)
  if (!ext) return {}
  // level = the true extreme (drives the dashed line and its label);
  // point = the plotted vertex the dot must sit on, so it stays on the line.
  const marks = [{ point: ext.max, level: ext.maxLevel, key: 'chart.max', position: 'insideEndTop' }]
  if (ext.minLevel !== ext.maxLevel) {
    marks.push({ point: ext.min, level: ext.minLevel, key: 'chart.min', position: 'insideEndBottom' })
  }
  return {
    markLine: {
      silent: true,
      symbol: 'none',
      animation: false,
      lineStyle: { color, width: 1, type: 'dashed' as const, opacity: 0.6 },
      data: marks.map((m) => ({
        yAxis: m.level,
        label: {
          position: m.position,
          color,
          fontSize: 10,
          formatter: () => `${i18n.t(m.key as never)} ${fmtValue(m.level)}`,
        },
      })),
    },
    markPoint: {
      silent: true,
      symbol: 'circle',
      symbolSize: 7,
      animation: false,
      itemStyle: { color, borderColor: '#fff', borderWidth: 1 },
      label: { show: false },
      data: marks.map((m) => ({ coord: [m.point.time, m.point.value] })),
    },
  }
}

/** Decide whether to display Wh values as kWh and return the display unit + scaling factor. */
function resolveUnit(unit: string, values: number[]): { displayUnit: string; scale: number } {
  if (unit === 'Wh' && values.length > 0 && Math.max(...values) >= 1000) {
    return { displayUnit: 'kWh', scale: 1 / 1000 }
  }
  return { displayUnit: unit, scale: 1 }
}

export function TimeSeriesChart({
  data,
  label,
  unit,
  color = '#0046A8',
  height = 280,
  enabledAvgPeriods,
  fitToData = true,
  showMinMax = false,
  data2,
  label2 = '',
  color2 = '#9B59B6',
  events = [],
  eventsLabel = 'Alert events',
}: TimeSeriesChartProps) {
  const option = useMemo(() => {
    const activePeriods: Period[] = enabledAvgPeriods ?? (() => {
      if (data.length < 2) return []
      const diffDays = (new Date(data[data.length - 1].time).getTime() - new Date(data[0].time).getTime())
        / (1000 * 60 * 60 * 24)
      return selectAveragePeriods(diffDays)
    })()

    // Unit (and kWh scaling) decided over both series so they stay comparable.
    const allValues = [...data.map((p) => p.value), ...(data2?.map((p) => p.value) ?? [])]
    const { displayUnit, scale } = resolveUnit(unit, allValues)
    // The bucket extremes are in the same unit as the value, so a Wh→kWh switch
    // has to carry them along or the markers would sit 1000× off.
    const rescale = (d: StatPoint[]) => (scale === 1 ? d : d.map((p) => ({
      ...p,
      value: p.value * scale,
      min: p.min === undefined ? undefined : p.min * scale,
      max: p.max === undefined ? undefined : p.max * scale,
    })))

    const scaledData = rescale(data)
    const scaledData2 = data2 ? rescale(data2) : undefined
    const scaledValues = allValues.map((v) => v * scale)
    const scaledEvents = events.map((e) => ({ ...e, value: e.value * scale }))

    const fmtValue = (v: number) => `${Number(v.toFixed(2))} ${displayUnit}`
    const minMax = (d: StatPoint[], c: string) => (showMinMax ? buildMinMaxMarkers(d, c, fmtValue) : {})

    const avgSeriesList = scaledData.length < 2 ? [] : activePeriods.map((p) => buildAvgSeries(scaledData, p))
    // With min/max shown, the axis has to reach the true extremes as well —
    // a bucket high above every plotted average would otherwise be drawn
    // outside the visible area and simply vanish.
    const markerValues = showMinMax
      ? [...scaledData, ...(scaledData2 ?? [])]
        .flatMap((p) => [p.min, p.max])
        .filter((v): v is number => v !== undefined)
      : []
    const bounds = fitToData ? yAxisBounds([...scaledValues, ...markerValues]) : null
    const spanMs = timeSpan(data, data2)

    const legendData = [
      label,
      ...(scaledData2 ? [label2] : []),
      ...avgSeriesList.map((s) => s.name),
      ...(scaledEvents.length > 0 ? [eventsLabel] : []),
    ]
    const hasLegend = legendData.length > 1

    return {
      tooltip: {
        trigger: 'axis',
        formatter: (params: TooltipParam[]) => {
          const ts = new Date(params[0].value[0]).toLocaleString()
          // An event plotted on both device lines yields duplicate params; show it once.
          const seen = new Set<string>()
          const lines = params
            .map((p) => {
              if (p.data?.summary) {
                if (seen.has(p.data.summary)) return ''
                seen.add(p.data.summary)
                return `${p.marker}🔔 ${p.data.summary}`
              }
              const value = `${p.marker}${p.seriesName}: <b>${Number(p.value[1]).toFixed(2)} ${displayUnit}</b>`
              // A time-shifted series is plotted at a borrowed timestamp so it
              // overlays the current window. Naming the date it actually
              // happened is the whole point of the comparison.
              return p.data?.originalTime
                ? `${value} <small>(${new Date(p.data.originalTime).toLocaleString()})</small>`
                : value
            })
            .filter((l) => l !== '')
            .join('<br/>')
          return `${ts}<br/>${lines}`
        },
      },
      legend: hasLegend ? {
        data: legendData,
        bottom: 4,
        textStyle: { fontSize: 11, color: '#6B7280' },
        itemWidth: 16,
        itemHeight: 10,
      } : undefined,
      grid: { left: 60, right: 20, top: 16, bottom: hasLegend ? 52 : 40 },
      xAxis: {
        type: 'time',
        axisLabel: {
          color: '#6B7280',
          fontSize: 11,
          formatter: (v: number) => formatAxisTime(v, spanMs, i18n.language),
        },
        axisLine: { lineStyle: { color: '#D4D9E0' } },
      },
      yAxis: {
        type: 'value',
        name: displayUnit,
        nameTextStyle: { color: '#6B7280', fontSize: 11 },
        axisLabel: {
          color: '#6B7280',
          fontSize: 11,
          formatter: (v: number) => `${v} ${displayUnit}`,
        },
        splitLine: { lineStyle: { color: '#F2F4F7' } },
        ...(bounds ? { min: bounds.min, max: bounds.max } : {}),
      },
      series: [
        {
          name: label,
          type: 'line',
          smooth: false,
          showSymbol: false,
          sampling: 'lttb',
          data: scaledData.map((p) => [p.time, p.value]),
          lineStyle: { color, width: 1.5 },
          areaStyle: { color, opacity: 0.07 },
          ...minMax(scaledData, color),
        },
        ...(scaledData2 ? [{
          name: label2,
          type: 'line' as const,
          smooth: false,
          showSymbol: false,
          sampling: 'lttb' as const,
          data: scaledData2.map((p) => (p.originalTime !== undefined
            ? { value: [p.time, p.value], originalTime: p.originalTime }
            : [p.time, p.value])),
          lineStyle: { color: color2, width: 1.5 },
          ...minMax(scaledData2, color2),
        }] : []),
        ...avgSeriesList,
        ...(scaledEvents.length > 0 ? [{
          name: eventsLabel,
          type: 'scatter' as const,
          symbolSize: 9,
          z: 5,
          data: scaledEvents.map((e) => ({
            value: [e.time, e.value],
            itemStyle: { color: EVENT_COLORS[e.state], borderColor: '#fff', borderWidth: 1 },
            summary: e.summary,
          })),
        }] : []),
      ],
    }
  }, [data, label, unit, color, enabledAvgPeriods, fitToData, showMinMax, data2, label2, color2, events, eventsLabel])

  return <ReactECharts option={option} notMerge style={{ height }} />
}
