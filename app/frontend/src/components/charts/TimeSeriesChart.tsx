import { useMemo } from 'react'
import ReactECharts from 'echarts-for-react'
import i18n from '../../i18n'
import { StatPoint } from '../../api/stats'

interface TimeSeriesChartProps {
  data: StatPoint[]
  label: string
  unit: string
  color?: string
  height?: number
  enabledAvgPeriods?: Period[]
  fitToData?: boolean
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
  return { name: i18n.t(key as any), color }
}

// Centered rolling average: each point becomes the mean of all points
// within ±half of the period window around it.
function computeRollingAvg(data: StatPoint[], period: Period): [string, number][] {
  const half = PERIOD_MS[period] / 2
  const times = data.map((p) => new Date(p.time).getTime())
  return data.map((p, i) => {
    const t = times[i]
    let sum = 0, count = 0
    for (let j = 0; j < data.length; j++) {
      if (Math.abs(times[j] - t) <= half) { sum += data[j].value; count++ }
    }
    return [p.time, sum / count]
  })
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

export function selectAveragePeriods(diffDays: number): Period[] {
  if (diffDays > 365) return ['year', 'month']
  if (diffDays > 30)  return ['month', 'week']
  if (diffDays > 7)   return ['week', 'day']
  return ['day']
}

function yAxisBounds(values: number[]): { min: number; max: number } | null {
  if (values.length === 0) return null
  const lo = Math.min(...values)
  const hi = Math.max(...values)
  const range = hi - lo || 1          // avoid zero-range edge case
  const margin = range * 0.1
  return { min: Math.max(0, Math.floor(lo - margin)), max: Math.ceil(hi + margin) }
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
}: TimeSeriesChartProps) {
  const option = useMemo(() => {
    const activePeriods: Period[] = enabledAvgPeriods ?? (() => {
      if (data.length < 2) return []
      const diffDays = (new Date(data[data.length - 1].time).getTime() - new Date(data[0].time).getTime())
        / (1000 * 60 * 60 * 24)
      return selectAveragePeriods(diffDays)
    })()

    const rawValues = data.map((p) => p.value)
    const { displayUnit, scale } = resolveUnit(unit, rawValues)

    const scaledData = scale === 1 ? data : data.map((p) => ({ ...p, value: p.value * scale }))
    const scaledValues = scale === 1 ? rawValues : rawValues.map((v) => v * scale)

    const avgSeriesList = scaledData.length < 2 ? [] : activePeriods.map((p) => buildAvgSeries(scaledData, p))

    const bounds = fitToData ? yAxisBounds(scaledValues) : null

    const hasLegend = avgSeriesList.length > 0

    return {
      tooltip: {
        trigger: 'axis',
        formatter: (params: { seriesName: string; value: [string, number]; marker: string }[]) => {
          const ts = new Date(params[0].value[0]).toLocaleString()
          const lines = params
            .map((p) => `${p.marker}${p.seriesName}: <b>${Number(p.value[1]).toFixed(2)} ${displayUnit}</b>`)
            .join('<br/>')
          return `${ts}<br/>${lines}`
        },
      },
      legend: hasLegend ? {
        data: [label, ...avgSeriesList.map((s) => s.name)],
        bottom: 4,
        textStyle: { fontSize: 11, color: '#6B7280' },
        itemWidth: 16,
        itemHeight: 10,
      } : undefined,
      grid: { left: 60, right: 20, top: 16, bottom: hasLegend ? 52 : 40 },
      xAxis: {
        type: 'time',
        axisLabel: { color: '#6B7280', fontSize: 11 },
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
          data: scaledData.map((p) => [p.time, p.value]),
          lineStyle: { color, width: 1.5 },
          areaStyle: { color, opacity: 0.07 },
        },
        ...avgSeriesList,
      ],
    }
  }, [data, label, unit, color, enabledAvgPeriods, fitToData])

  return <ReactECharts option={option} notMerge style={{ height }} />
}
