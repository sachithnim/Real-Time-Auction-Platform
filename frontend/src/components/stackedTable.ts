/**
 * Classes for a table that turns each row into a small card on phones: the
 * header hides, and every cell shows its column name from `data-label`. From
 * the md breakpoint up it is an ordinary table. One set of markup for both, so
 * there is nothing to keep in sync.
 */
export const stackedTable = {
  table: 'block w-full text-sm md:table',
  head: 'hidden bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 md:table-header-group',
  body: 'block divide-y divide-slate-100 md:table-row-group',
  row: 'block space-y-1 px-4 py-3 hover:bg-slate-50 md:table-row md:space-y-0 md:p-0',
  /** The row's title: full width on phones, no label. */
  titleCell: 'block md:table-cell md:px-4 md:py-3',
  /** A labelled value; wrap multi-part content in one element so it stays on the right. */
  cell: 'flex items-baseline justify-between gap-4 before:text-xs before:font-medium before:uppercase before:tracking-wide before:text-slate-500 before:content-[attr(data-label)] md:table-cell md:px-4 md:py-3 md:before:content-none',
  /** A message spanning the whole table, e.g. "No auctions." */
  emptyCell: 'block px-4 py-8 text-center text-slate-500 md:table-cell',
}
