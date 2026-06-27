import React, { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { SortIcon } from './ActionIcons'

interface Column<T> {
  key: string
  header: string
  render: (row: T) => React.ReactNode
  width?: string
  sortValue?: (row: T) => string | number // present ⇒ column is sortable
}

interface DataTableProps<T> {
  columns: Column<T>[]
  rows: T[]
  keyFn: (row: T) => string | number
  emptyMessage?: string
}

type SortState = { key: string; dir: 'asc' | 'desc' } | null

export function DataTable<T>({ columns, rows, keyFn, emptyMessage }: DataTableProps<T>) {
  const { t } = useTranslation()
  const [sort, setSort] = useState<SortState>(null)

  const sortedRows = useMemo(() => {
    if (!sort) return rows
    const col = columns.find((c) => c.key === sort.key)
    if (!col?.sortValue) return rows
    const f = sort.dir === 'asc' ? 1 : -1
    return [...rows].sort((a, b) => {
      const va = col.sortValue!(a)
      const vb = col.sortValue!(b)
      return (typeof va === 'number' && typeof vb === 'number'
        ? va - vb
        : String(va).localeCompare(String(vb))) * f
    })
  }, [rows, sort, columns])

  // Cycle a column through asc → desc → unsorted; switching columns restarts at asc.
  const toggleSort = (key: string) =>
    setSort((prev) => {
      if (prev?.key !== key) return { key, dir: 'asc' }
      if (prev.dir === 'asc') return { key, dir: 'desc' }
      return null
    })

  const directionFor = (key: string): 'asc' | 'desc' | 'none' =>
    sort?.key === key ? sort.dir : 'none'

  return (
    <div className="table-wrapper">
      <table className="data-table">
        <thead>
          <tr>
            {columns.map((col) => (
              <th key={col.key} style={col.width ? { width: col.width } : undefined}>
                {col.sortValue ? (
                  <button
                    type="button"
                    className="th-sort"
                    aria-label={t('common.sortBy', { name: col.header })}
                    onClick={() => toggleSort(col.key)}
                  >
                    {col.header}
                    <SortIcon direction={directionFor(col.key)} />
                  </button>
                ) : (
                  col.header
                )}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {sortedRows.length === 0 ? (
            <tr>
              <td colSpan={columns.length} className="table-empty">
                {emptyMessage ?? t('common.noData')}
              </td>
            </tr>
          ) : (
            sortedRows.map((row) => (
              <tr key={keyFn(row)}>
                {columns.map((col) => (
                  <td key={col.key}>{col.render(row)}</td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  )
}
