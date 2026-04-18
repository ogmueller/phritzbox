import React from 'react'
import { useTranslation } from 'react-i18next'

interface Column<T> {
  key: string
  header: string
  render: (row: T) => React.ReactNode
  width?: string
}

interface DataTableProps<T> {
  columns: Column<T>[]
  rows: T[]
  keyFn: (row: T) => string | number
  emptyMessage?: string
}

export function DataTable<T>({ columns, rows, keyFn, emptyMessage }: DataTableProps<T>) {
  const { t } = useTranslation()

  return (
    <div className="table-wrapper">
      <table className="data-table">
        <thead>
          <tr>
            {columns.map((col) => (
              <th key={col.key} style={col.width ? { width: col.width } : undefined}>
                {col.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td colSpan={columns.length} className="table-empty">
                {emptyMessage ?? t('common.noData')}
              </td>
            </tr>
          ) : (
            rows.map((row) => (
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
