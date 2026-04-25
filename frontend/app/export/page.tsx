'use client';

import { useState, useEffect, useRef } from 'react';
import { fetchSummary, pollExportStatus, buildExportUrl, type Filters, type SalesSummary } from '@/lib/api';
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts';
import clsx from 'clsx';

const BRANCHES = ['Mirpur', 'Gulshan', 'Dhanmondi', 'Uttara', 'Motijheel', 'Chattogram'];
const PAYMENTS  = ['Cash', 'bKash', 'Nagad', 'Bank Transfer', 'Card'];

type ExportState = 'idle' | 'polling' | 'ready' | 'failed';

interface LargeExportJob {
  jobId: string;
  format: 'csv' | 'excel';
  state: ExportState;
  downloadUrl: string | null;
}

export default function ExportPage() {
  const [filters, setFilters] = useState<Filters>({});
  const [summary, setSummary] = useState<SalesSummary | null>(null);
  const [loadingSummary, setLoadingSummary] = useState(true);
  const [exports, setExports] = useState<LargeExportJob[]>([]);
  const pollRef = useRef<Map<string, ReturnType<typeof setInterval>>>(new Map());

  // Load summary on filter change
  useEffect(() => {
    setLoadingSummary(true);
    fetchSummary(filters)
      .then(setSummary)
      .finally(() => setLoadingSummary(false));
  }, [filters]);

  const setFilter = (k: keyof Filters, v: string) =>
    setFilters(f => ({ ...f, [k]: v || undefined }));

  const handleExport = async (format: 'csv' | 'excel') => {
    // Build the URL with active filters
    const url = buildExportUrl(format, filters);

    // Try to stream directly first — if backend returns job_id it's a large export
    try {
      const res = await fetch(url);
      const contentType = res.headers.get('content-type') ?? '';

      if (contentType.includes('application/json')) {
        // Large export — got a job_id back
        const json = await res.json();
        const newJob: LargeExportJob = { jobId: json.job_id, format, state: 'polling', downloadUrl: null };
        setExports(prev => [newJob, ...prev]);
        startPollExport(json.job_id, format);
      } else {
        // Small export — download directly
        const blob = await res.blob();
        triggerDownload(blob, `shopease_sales.${format === 'excel' ? 'xlsx' : 'csv'}`);
      }
    } catch (e) {
      console.error('Export error', e);
    }
  };

  const startPollExport = (jobId: string, format: 'csv' | 'excel') => {
    const interval = setInterval(async () => {
      try {
        const s = await pollExportStatus(jobId);
        if (s.status === 'done' && s.download_url) {
          clearInterval(interval);
          pollRef.current.delete(jobId);
          setExports(prev => prev.map(e =>
            e.jobId === jobId ? { ...e, state: 'ready', downloadUrl: s.download_url } : e
          ));
        }
        if (s.status === 'failed') {
          clearInterval(interval);
          setExports(prev => prev.map(e => e.jobId === jobId ? { ...e, state: 'failed' } : e));
        }
      } catch { /* retry */ }
    }, 2000);
    pollRef.current.set(jobId, interval);
  };

  const triggerDownload = (blob: Blob, filename: string) => {
    const url = URL.createObjectURL(blob);
    const a   = document.createElement('a');
    a.href = url; a.download = filename; a.click();
    URL.revokeObjectURL(url);
  };

  useEffect(() => () => { pollRef.current.forEach(i => clearInterval(i)); }, []);

  const fmt = (n: number) => n.toLocaleString('en-BD', { maximumFractionDigits: 0 });
  const fmtBDT = (n: number) => '৳' + n.toLocaleString('en-BD', { maximumFractionDigits: 0 });

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-3xl font-bold text-slate-100">Export & Analytics</h1>
        <p className="text-slate-400 mt-1">Filter data and export. Exports over 10,000 rows are queued.</p>
      </div>

      {/* Filters */}
      <div className="card">
        <h2 className="font-semibold text-slate-200 mb-4">Active Filters</h2>
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
        <div className="mt-5 flex flex-wrap gap-3">
          <button className="btn-primary" onClick={() => handleExport('csv')}>⬇ Export CSV</button>
          <button className="btn-secondary" onClick={() => handleExport('excel')}>📊 Export Excel</button>
          <button className="btn-secondary text-sm opacity-60" onClick={() => setFilters({})}>Reset Filters</button>
        </div>
      </div>

      {/* Large export jobs */}
      {exports.length > 0 && (
        <div className="card space-y-3">
          <h2 className="font-semibold text-slate-200">Export Queue</h2>
          {exports.map(job => (
            <div key={job.jobId} className="flex items-center justify-between bg-slate-800/60 rounded-xl px-4 py-3">
              <div className="flex items-center gap-3">
                {job.state === 'polling' && <Spinner />}
                {job.state === 'ready' && <span className="text-emerald-400">✓</span>}
                {job.state === 'failed' && <span className="text-red-400">✕</span>}
                <span className="text-sm text-slate-300">
                  {job.format.toUpperCase()} export — {job.state === 'polling' ? 'Preparing…' : job.state === 'ready' ? 'Ready' : 'Failed'}
                </span>
              </div>
              {job.state === 'ready' && job.downloadUrl && (
                <a href={job.downloadUrl} download className="btn-primary text-xs py-1.5 px-3">⬇ Download</a>
              )}
            </div>
          ))}
        </div>
      )}

      {/* KPI cards */}
      {summary && (
        <>
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <KpiCard label="Total Revenue" value={fmtBDT(summary.overall.total_revenue)} icon="💰" />
            <KpiCard label="Transactions" value={fmt(summary.overall.total_transactions)} icon="🧾" />
            <KpiCard label="Units Sold" value={fmt(summary.overall.total_quantity)} icon="📦" />
            <KpiCard label="Avg Order Value" value={fmtBDT(summary.overall.avg_order_value)} icon="📈" />
          </div>

          {/* Branch chart */}
          <div className="card">
            <h2 className="font-semibold text-slate-200 mb-6">Revenue by Branch</h2>
            <ResponsiveContainer width="100%" height={240}>
              <BarChart data={summary.branch_breakdown} margin={{ top: 0, right: 0, bottom: 0, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#1e293b" />
                <XAxis dataKey="branch" tick={{ fill: '#94a3b8', fontSize: 12 }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fill: '#94a3b8', fontSize: 11 }} axisLine={false} tickLine={false} tickFormatter={v => '৳' + (v/1000).toFixed(0) + 'k'} />
                <Tooltip
                  contentStyle={{ background: '#0f172a', border: '1px solid #1e293b', borderRadius: 12, color: '#e2e8f0' }}
                  formatter={(v: number) => ['৳' + v.toLocaleString(), 'Revenue']}
                />
                <Bar dataKey="revenue" fill="#10b981" radius={[6, 6, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>

          {/* Top 5 products */}
          <div className="card">
            <h2 className="font-semibold text-slate-200 mb-4">Top 5 Products by Revenue</h2>
            <div className="space-y-3">
              {summary.top_5_products.map((p, i) => (
                <div key={p.product_name} className="flex items-center gap-4">
                  <span className="text-xl font-bold text-slate-600 tabular-nums w-6">{i + 1}</span>
                  <div className="flex-1 min-w-0">
                    <p className="text-slate-300 text-sm font-medium truncate">{p.product_name}</p>
                    <div className="mt-1 h-1.5 bg-slate-800 rounded-full overflow-hidden">
                      <div
                        className="h-full bg-emerald-500 rounded-full"
                        style={{ width: `${(p.product_revenue / summary.top_5_products[0].product_revenue) * 100}%` }}
                      />
                    </div>
                  </div>
                  <span className="text-emerald-400 font-semibold tabular-nums text-sm shrink-0">
                    {fmtBDT(p.product_revenue)}
                  </span>
                </div>
              ))}
            </div>
          </div>
        </>
      )}

      {loadingSummary && (
        <div className="card flex items-center justify-center py-12 gap-3 text-slate-500">
          <Spinner /> Loading analytics…
        </div>
      )}
    </div>
  );
}

function KpiCard({ label, value, icon }: { label: string; value: string; icon: string }) {
  return (
    <div className="card">
      <p className="text-2xl mb-2">{icon}</p>
      <p className="text-xs text-slate-500 uppercase tracking-wider font-semibold">{label}</p>
      <p className="text-2xl font-bold text-slate-100 mt-1 tabular-nums">{value}</p>
    </div>
  );
}

function Spinner() {
  return <div className="w-4 h-4 rounded-full border-2 border-slate-600 border-t-emerald-400 animate-spin shrink-0" />;
}
