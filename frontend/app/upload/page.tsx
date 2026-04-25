'use client';

import { useState, useCallback, useEffect, useRef } from 'react';
import { importCsv, pollImportStatus, type ImportStatus } from '@/lib/api';
import { useDropzone } from 'react-dropzone';
import clsx from 'clsx';

type Stage = 'idle' | 'uploading' | 'processing' | 'done' | 'failed';

export default function UploadPage() {
  const [stage, setStage] = useState<Stage>('idle');
  const [jobId, setJobId] = useState<string | null>(null);
  const [status, setStatus] = useState<ImportStatus | null>(null);
  const [error, setError] = useState<string | null>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const stopPolling = () => {
    if (pollRef.current) clearInterval(pollRef.current);
  };

  const startPolling = (id: string) => {
    pollRef.current = setInterval(async () => {
      try {
        const s = await pollImportStatus(id);
        setStatus(s);
        if (s.status === 'done') { setStage('done'); stopPolling(); }
        if (s.status === 'failed') { setStage('failed'); setError(s.error_message ?? 'Import failed'); stopPolling(); }
      } catch { /* retry */ }
    }, 1500);
  };

  useEffect(() => () => stopPolling(), []);

  const onDrop = useCallback(async (accepted: File[]) => {
    const file = accepted[0];
    if (!file) return;
    setStage('uploading');
    setError(null);
    setStatus(null);
    try {
      const res = await importCsv(file);
      setJobId(res.job_id);
      setStage('processing');
      startPolling(res.job_id);
    } catch (e: unknown) {
      const errorMessage = e instanceof Error ? e.message : 'Upload failed';
      setError(errorMessage);
      setStage('failed');
    }
  }, []);

  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop,
    accept: { 'text/csv': ['.csv'], 'application/vnd.ms-excel': ['.xls'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['.xlsx'] },
    maxFiles: 1,
    disabled: stage === 'uploading' || stage === 'processing',
  });

  const reset = () => { setStage('idle'); setStatus(null); setError(null); setJobId(null); };

  return (
    <div className="max-w-2xl mx-auto space-y-8">
      <div>
        <h1 className="text-3xl font-bold text-slate-100">Import Sales Data</h1>
        <p className="text-slate-400 mt-1">Upload a CSV or Excel file to import sales records</p>
      </div>

      {/* Drop zone */}
      <div
        {...getRootProps()}
        className={clsx(
          'border-2 border-dashed rounded-2xl p-16 text-center cursor-pointer transition-all',
          isDragActive
            ? 'border-emerald-400 bg-emerald-500/10'
            : 'border-slate-700 hover:border-slate-500 bg-slate-900/50',
          (stage === 'uploading' || stage === 'processing') && 'pointer-events-none opacity-60',
        )}
      >
        <input {...getInputProps()} />
        <div className="text-5xl mb-4">{isDragActive ? '📂' : '📁'}</div>
        <p className="text-slate-300 font-medium text-lg">
          {isDragActive ? 'Drop it!' : 'Drag & drop your file here'}
        </p>
        <p className="text-slate-500 text-sm mt-2">or click to browse — CSV, XLS, XLSX accepted</p>
      </div>

      {/* Status card */}
      {stage !== 'idle' && (
        <div className="card space-y-5">
          {(stage === 'uploading' || stage === 'processing') && (
            <div className="flex items-center gap-3">
              <Spinner />
              <span className="text-slate-300 text-sm">
                {stage === 'uploading' ? 'Uploading file…' : 'Processing rows in chunks of 500…'}
              </span>
            </div>
          )}

          {status && (
            <>
              <StatusRow label="Status">
                <span className={clsx('badge',
                  status.status === 'done' ? 'bg-emerald-500/20 text-emerald-400' :
                  status.status === 'processing' ? 'bg-blue-500/20 text-blue-400' :
                  'bg-red-500/20 text-red-400'
                )}>{status.status}</span>
              </StatusRow>

              {status.total_rows > 0 && (
                <div className="grid grid-cols-2 gap-4">
                  <StatCard label="Total Rows" value={status.total_rows.toLocaleString()} color="text-slate-300" />
                  <StatCard label="Inserted" value={status.inserted.toLocaleString()} color="text-emerald-400" />
                  <StatCard label="Skipped (Duplicates)" value={status.skipped_duplicates.toLocaleString()} color="text-amber-400" />
                  <StatCard label="Skipped (Invalid)" value={status.skipped_invalid.toLocaleString()} color="text-red-400" />
                </div>
              )}

              {status.error_log_url && (
                <a
                  href={status.error_log_url}
                  className="inline-flex items-center gap-2 text-sm text-red-400 hover:text-red-300 transition-colors"
                  download
                >
                  ⬇ Download error log CSV
                </a>
              )}
            </>
          )}

          {error && (
            <div className="bg-red-500/10 border border-red-500/30 rounded-xl p-4 text-red-400 text-sm">
              {error}
            </div>
          )}

          {(stage === 'done' || stage === 'failed') && (
            <button onClick={reset} className="btn-secondary text-sm">
              Upload another file
            </button>
          )}
        </div>
      )}

      {/* Instructions */}
      <div className="card">
        <h2 className="font-semibold text-slate-200 mb-4">What the importer handles</h2>
        <ul className="space-y-2 text-sm text-slate-400">
          {[
            'Normalises branch names (mirpur / MIRPUR → Mirpur)',
            'Parses 3 date formats: d/m/Y, Y-m-d, m-d-Y',
            'Strips ৳ symbol and commas from prices',
            'Normalises discounts: "10", "10%", "0.10" → 0.10',
            'Sets blank / N/A / "-" categories to NULL',
            'Sets missing salesperson to "Unknown"',
            'Processes in chunks of 500 rows — never loads entire file',
            'Detects and skips duplicate rows via content hash',
          ].map((t) => (
            <li key={t} className="flex items-start gap-2">
              <span className="text-emerald-500 mt-0.5">✓</span> {t}
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

function StatusRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-slate-500 text-sm">{label}</span>
      {children}
    </div>
  );
}

function StatCard({ label, value, color }: { label: string; value: string; color: string }) {
  return (
    <div className="bg-slate-800/60 rounded-xl p-4">
      <p className="text-xs text-slate-500 mb-1">{label}</p>
      <p className={clsx('text-2xl font-bold tabular-nums', color)}>{value}</p>
    </div>
  );
}

function Spinner() {
  return (
    <div className="w-5 h-5 rounded-full border-2 border-slate-600 border-t-emerald-400 animate-spin shrink-0" />
  );
}
