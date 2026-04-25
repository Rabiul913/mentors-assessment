'use client';

import { useState, useEffect, useCallback } from 'react';
import { fetchSales, type SaleRow, type Filters, type PaginatedSales } from '@/lib/api';

const BRANCHES = ['Mirpur', 'Gulshan', 'Dhanmondi', 'Uttara', 'Motijheel', 'Chattogram'];
const PAYMENTS = ['Cash', 'bKash', 'Nagad', 'Bank Transfer', 'Card'];

export default function SalesPage() {
  const [filters, setFilters] = useState<Filters>({});
  const [page, setPage]     = useState(1);
  const [data, setData]     = useState<PaginatedSales | null>(null);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetchSales({ ...filters, page });
      setData(res);
    } finally {
      setLoading(false);
    }
  }, [filters, page]);

  useEffect(() => { load(); }, [load]);

  const setFilter = (k: keyof Filters, v: string) => {
    setFilters(f => ({ ...f, [k]: v || undefined }));
    setPage(1);
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold text-slate-100">Sales Records</h1>
          <p className="text-slate-400 mt-1">
            {data ? `${data.meta.total.toLocaleString()} records found` : 'Loading…'}
          </p>
        </div>
      </div>

      {/* Filter bar */}
      <div className="card">
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
          <div>
            <label className="label">Branch</label>
            <select className="input" value={filters.branch ?? ''} onChange={e => setFilter('branch', e.target.value)}>
              <option value="">All Branches</option>
              {BRANCHES.map(b => <option key={b}>{b}</option>)}
            </select>
          </div>
          <div>
            <label className="label">From</label>
            <input type="date" className="input" value={filters.from ?? ''} onChange={e => setFilter('from', e.target.value)} />
          </div>
          <div>
            <label className="label">To</label>
            <input type="date" className="input" value={filters.to ?? ''} onChange={e => setFilter('to', e.target.value)} />
          </div>
          <div>
            <label className="label">Category</label>
            <input className="input" placeholder="e.g. Grains" value={filters.category ?? ''} onChange={e => setFilter('category', e.target.value)} />
          </div>
          <div>
            <label className="label">Payment</label>
            <select className="input" value={filters.payment_method ?? ''} onChange={e => setFilter('payment_method', e.target.value)}>
              <option value="">All Methods</option>
              {PAYMENTS.map(p => <option key={p}>{p}</option>)}
            </select>
          </div>
        </div>
        <div className="mt-4 flex gap-3">
          <button className="btn-primary text-sm" onClick={() => load()}>Apply Filters</button>
          <button className="btn-secondary text-sm" onClick={() => { setFilters({}); setPage(1); }}>Reset</button>
        </div>
      </div>

      {/* Table */}
      <div className="card p-0 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-800 bg-slate-800/50">
                {['Sale ID','Branch','Date','Product','Category','Qty','Unit Price','Discount','Revenue','Payment','Salesperson'].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider whitespace-nowrap">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800/60">
              {loading && (
                <tr><td colSpan={11} className="px-4 py-12 text-center text-slate-500">Loading…</td></tr>
              )}
              {!loading && data?.data.length === 0 && (
                <tr><td colSpan={11} className="px-4 py-12 text-center text-slate-500">No records found</td></tr>
              )}
              {!loading && data?.data.map(row => (
                <SaleRow key={row.id} row={row} />
              ))}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {data && data.meta.last_page > 1 && (
          <div className="px-6 py-4 border-t border-slate-800 flex items-center justify-between">
            <span className="text-xs text-slate-500">
              Page {data.meta.current_page} of {data.meta.last_page}
            </span>
            <div className="flex gap-2">
              <button
                className="btn-secondary text-xs py-1.5 px-3"
                disabled={page === 1}
                onClick={() => setPage(p => p - 1)}
              >← Prev</button>
              <button
                className="btn-secondary text-xs py-1.5 px-3"
                disabled={page === data.meta.last_page}
                onClick={() => setPage(p => p + 1)}
              >Next →</button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function SaleRow({ row }: { row: SaleRow }) {
  const PAYMENT_COLORS: Record<string, string> = {
    'Cash': 'bg-slate-700 text-slate-300',
    'bKash': 'bg-pink-500/20 text-pink-400',
    'Nagad': 'bg-orange-500/20 text-orange-400',
    'Bank Transfer': 'bg-blue-500/20 text-blue-400',
    'Card': 'bg-violet-500/20 text-violet-400',
  };

  return (
    <tr className="hover:bg-slate-800/40 transition-colors">
      <td className="px-4 py-3 font-mono text-xs text-slate-400">{row.sale_id}</td>
      <td className="px-4 py-3 text-slate-300 font-medium whitespace-nowrap">{row.branch}</td>
      <td className="px-4 py-3 text-slate-400 whitespace-nowrap">{row.sale_date}</td>
      <td className="px-4 py-3 text-slate-300 max-w-[180px] truncate" title={row.product_name}>{row.product_name}</td>
      <td className="px-4 py-3 text-slate-500">{row.category ?? <span className="text-slate-700 italic">—</span>}</td>
      <td className="px-4 py-3 text-slate-300 tabular-nums text-right">{row.quantity.toLocaleString()}</td>
      <td className="px-4 py-3 text-slate-300 tabular-nums text-right">৳{Number(row.unit_price).toLocaleString()}</td>
      <td className="px-4 py-3 text-slate-400 tabular-nums text-right">{(Number(row.discount_pct) * 100).toFixed(0)}%</td>
      <td className="px-4 py-3 text-emerald-400 font-semibold tabular-nums text-right">৳{Number(row.revenue).toLocaleString()}</td>
      <td className="px-4 py-3">
        <span className={`badge ${PAYMENT_COLORS[row.payment_method] ?? 'bg-slate-700 text-slate-400'}`}>
          {row.payment_method}
        </span>
      </td>
      <td className="px-4 py-3 text-slate-400 whitespace-nowrap">{row.salesperson}</td>
    </tr>
  );
}
