import axios from 'axios';

const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api/v1',
  timeout: 60_000,
});

export default api;

// ── Types ────────────────────────────────────────────────────────────────────

export interface SaleRow {
  id: number;
  sale_id: string;
  branch: string;
  sale_date: string;
  product_name: string;
  category: string | null;
  quantity: number;
  unit_price: string;
  discount_pct: string;
  net_price: string;
  revenue: string;
  payment_method: string;
  salesperson: string;
}

export interface PaginatedSales {
  data: SaleRow[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface SalesSummary {
  overall: {
    total_transactions: number;
    total_revenue: number;
    total_quantity: number;
    avg_order_value: number;
  };
  top_5_products: Array<{ product_name: string; product_revenue: number; product_quantity: number }>;
  branch_breakdown: Array<{ branch: string; transactions: number; revenue: number; quantity: number }>;
  payment_breakdown: Array<{ payment_method: string; transactions: number; revenue: number }>;
  monthly_trend: Array<{ month: string; revenue: number; transactions: number }>;
}

export interface ImportStatus {
  job_id: string;
  status: 'pending' | 'processing' | 'done' | 'failed';
  total_rows: number;
  inserted: number;
  skipped_duplicates: number;
  skipped_invalid: number;
  error_log_url?: string;
  error_message?: string;
}

export interface ExportStatus {
  job_id: string;
  status: 'pending' | 'processing' | 'done' | 'failed';
  format: 'csv' | 'excel';
  ready: boolean;
  download_url: string | null;
}

export interface Filters {
  branch?: string;
  from?: string;
  to?: string;
  category?: string;
  payment_method?: string;
}

// ── API helpers ──────────────────────────────────────────────────────────────

export const importCsv = async (file: File) => {
  const form = new FormData();
  form.append('file', file);
  const { data } = await api.post<{ job_id: string; poll_url: string }>('/import', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data;
};

export const pollImportStatus = async (jobId: string) =>
  (await api.get<ImportStatus>(`/import/${jobId}/status`)).data;

export const fetchSales = async (filters: Filters & { page?: number }) =>
  (await api.get<PaginatedSales>('/sales', { params: filters })).data;

export const fetchSummary = async (filters: Filters) =>
  (await api.get<SalesSummary>('/sales/summary', { params: filters })).data;

export const triggerExport = async (format: 'csv' | 'excel', filters: Filters) => {
  const { data } = await api.get(`/export/${format}`, { params: filters });
  return data as { job_id?: string; poll_url?: string } | Blob;
};

export const pollExportStatus = async (jobId: string) =>
  (await api.get<ExportStatus>(`/export/${jobId}/status`)).data;

export const buildExportUrl = (format: 'csv' | 'excel', filters: Filters) => {
  const base = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api/v1';
  const params = new URLSearchParams(filters as Record<string, string>);
  return `${base}/export/${format}?${params.toString()}`;
};
