/**
 * The single frontend registry of metric types.
 *
 * This used to be three separate lists — Reports' STAT_TYPES (value/label/unit/
 * colour), Alerts' METRIC_TYPES + UNITS, and the chart.* i18n keys — which meant
 * adding a metric involved editing all of them and noticing none were wrong
 * until something rendered blank. The backend equivalent is
 * app/src/Service/MetricUnits.php; keep the two in step.
 */

export type MetricType = 'temperature' | 'power' | 'energy' | 'voltage' | 'battery' | 'presence'

export interface Metric {
  value: MetricType
  labelKey: 'chart.temperature' | 'chart.power' | 'chart.energy' | 'chart.voltage' | 'chart.battery' | 'chart.presence'
  /** Display unit. Empty for presence, which is a 0/1 flag. */
  unit: string
  color: string
}

export const METRICS: readonly Metric[] = [
  { value: 'temperature', labelKey: 'chart.temperature', unit: '°C', color: '#E8620D' },
  { value: 'power',       labelKey: 'chart.power',       unit: 'W',  color: '#0046A8' },
  { value: 'energy',      labelKey: 'chart.energy',      unit: 'Wh', color: '#4E9A2E' },
  { value: 'voltage',     labelKey: 'chart.voltage',     unit: 'V',  color: '#6B7280' },
  { value: 'battery',     labelKey: 'chart.battery',     unit: '%',  color: '#7C3AED' },
  { value: 'presence',    labelKey: 'chart.presence',    unit: '',   color: '#BE123C' },
] as const

export const DEFAULT_METRIC: MetricType = 'temperature'

/** Falls back to the first metric so an unknown persisted value cannot blank the page. */
export function metric(type: string): Metric {
  return METRICS.find((m) => m.value === type) ?? METRICS[0]
}

export function metricUnit(type: string): string {
  return METRICS.find((m) => m.value === type)?.unit ?? ''
}
