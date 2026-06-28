import { describe, it, expect, vi } from 'vitest'
import { render, screen, within, fireEvent } from '@testing-library/react'
import { DataTable } from './DataTable'

// t returns the key, interpolating {{name}} for the sort aria-label.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string, o?: { name?: string }) => (o?.name ? `sortBy:${o.name}` : k) }),
}))

interface Row { id: number; name: string; n: number }

const rows: Row[] = [
  { id: 1, name: 'Charlie', n: 2 },
  { id: 2, name: 'Alice', n: 30 },
  { id: 3, name: 'Bob', n: 1 },
]

const columns = [
  { key: 'name', header: 'Name', render: (r: Row) => r.name, sortValue: (r: Row) => r.name },
  { key: 'n', header: 'N', render: (r: Row) => String(r.n), sortValue: (r: Row) => r.n },
  { key: 'act', header: 'Act', render: () => 'x' }, // not sortable (no sortValue)
]

function firstColumnOrder(): string[] {
  const table = screen.getByRole('table')
  const bodyRows = within(table).getAllByRole('row').slice(1) // drop header row
  return bodyRows.map((tr) => within(tr).getAllByRole('cell')[0].textContent)
}

function renderTable() {
  return render(<DataTable columns={columns} rows={rows} keyFn={(r: Row) => r.id} />)
}

describe('DataTable sorting', () => {
  it('renders rows in input order by default', () => {
    renderTable()
    expect(firstColumnOrder()).toEqual(['Charlie', 'Alice', 'Bob'])
  })

  it('cycles a sortable column asc -> desc -> off', () => {
    renderTable()
    const nameHeader = screen.getByRole('button', { name: 'sortBy:Name' })

    fireEvent.click(nameHeader) // asc
    expect(firstColumnOrder()).toEqual(['Alice', 'Bob', 'Charlie'])

    fireEvent.click(nameHeader) // desc
    expect(firstColumnOrder()).toEqual(['Charlie', 'Bob', 'Alice'])

    fireEvent.click(nameHeader) // off -> original order
    expect(firstColumnOrder()).toEqual(['Charlie', 'Alice', 'Bob'])
  })

  it('sorts numeric columns numerically, not lexically', () => {
    renderTable()
    fireEvent.click(screen.getByRole('button', { name: 'sortBy:N' })) // asc by n
    // values 2,30,1 -> asc 1,2,30 -> names Bob, Charlie, Alice
    expect(firstColumnOrder()).toEqual(['Bob', 'Charlie', 'Alice'])
  })

  it('does not make a column without sortValue clickable', () => {
    renderTable()
    expect(screen.queryByRole('button', { name: 'sortBy:Act' })).toBeNull()
  })
})
