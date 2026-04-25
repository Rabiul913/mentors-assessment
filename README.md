# ShopEase BD — Sales Data Management System

Full-stack web application built with **Laravel 10** (REST API) + **Next.js 16** (React frontend).  
Imports, cleans, stores, and exports ~20,000 rows of messy wholesale sales data across 6 Bangladesh branches.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Local Setup](#local-setup)
3. [Environment Variables](#environment-variables)
4. [Running the Data Generator](#running-the-data-generator)
5. [Running the Importer](#running-the-importer)
6. [API Documentation](#api-documentation)
7. [Data Cleaning Decision Log](#data-cleaning-decision-log)
8. [Known Traps & How We Solved Them](#known-traps--how-we-solved-them)

---

## Architecture Overview

```
mentors-assessment/
├── generate_csv.py              # Standalone dirty-data generator (Python)
├── backend/             # REST API
│   ├── app/
│   │   ├── Http/Controllers/Api/
│   │   │   ├── ImportController.php
│   │   │   ├── SalesController.php
│   │   │   └── ExportController.php
│   │   ├── Jobs/
│   │   │   ├── ProcessCsvImportJob.php   # Chunked import (500 rows)
│   │   │   └── ProcessExportJob.php      # Background export for >10k rows
│   │   ├── Models/
│   │   │   ├── Sale.php
│   │   │   ├── ImportJob.php
│   │   │   └── ExportJob.php
│   │   └── Services/
│   │       └── CsvCleanerService.php     # All field normalisation logic
│   ├── database/migrations/
│   └── routes/api.php
└── frontend/             # React dashboard
    └── app/
        ├── upload/page.tsx      # Drag-and-drop CSV upload + import summary
        ├── sales/page.tsx       # Paginated sales table with filters
        └── export/page.tsx      # Export controls + analytics dashboard
```

---

## Local Setup

### Prerequisites

- PHP 8.2+, Composer
- Node.js 20+, npm
- MySQL 8+ (or MariaDB 10.6+)
- Redis (for Laravel queues)
- Python 3.11+ (for data generator only)

### 1. Clone the repository

```bash
git clone https://github.com/Rabiul913/mentors-assessment.git
cd mentors-assessment
```

### 2. Laravel Backend

```bash
cd backend

# Install dependencies
composer install

# Copy and configure environment
cp .env.example .env
# Edit .env — see Environment Variables section below

# Generate app key
php artisan key:generate

# Run migrations
php artisan migrate

# Start the queue worker (required for imports and large exports)
php artisan queue:work --queue=default --tries=3

# Start the API server
php artisan serve   # runs on http://localhost:8000
```

### 3. Next.js Frontend

```bash
cd ../frontend

npm install

# Copy and configure environment
cp .env.local.example .env.local
# Set NEXT_PUBLIC_API_URL=http://localhost:8000/api/v1

npm run dev   # runs on http://localhost:3000
```

---

## Environment Variables

### Laravel (`backend/.env`)

| Variable | Example | Description |
|---|---|---|
| `APP_NAME` | `ShopEase BD` | App name |
| `APP_ENV` | `local` | Environment |
| `APP_KEY` | generated | Run `php artisan key:generate` |
| `DB_CONNECTION` | `mysql` | Database driver |
| `DB_HOST` | `127.0.0.1` | Database host |
| `DB_PORT` | `3306` | Database port |
| `DB_DATABASE` | `shopease_bd` | Database name |
| `DB_USERNAME` | `root` | Database user |
| `DB_PASSWORD` | `secret` | Database password |
| `QUEUE_CONNECTION` | `redis` | Queue driver (`redis` or `database`) |
| `REDIS_HOST` | `127.0.0.1` | Redis host |
| `FILESYSTEM_DISK` | `local` | Storage disk for uploads/exports |

### Next.js (`nextjs-frontend/.env.local`)

| Variable | Example | Description |
|---|---|---|
| `NEXT_PUBLIC_API_URL` | `http://localhost:8000/api/v1` | Laravel API base URL |

---

## Running the Data Generator

The generator produces a ~20,200-row dirty CSV with all specified issues baked in.

```bash
# From project root
python3 generate_csv.py

# Output: shopease_sales_dirty.csv (≈ 6–8 MB)
```

**What gets generated:**

| Issue | Implementation |
|---|---|
| Mixed branch casing | `"mirpur"`, `"Mirpur "`, `"MIRPUR"` all present |
| Three date formats | `d/m/Y`, `Y-m-d`, `m-d-Y` randomly distributed |
| Currency symbols | `৳1,200.00` vs `1200.00` vs `1200` |
| Discount formats | `"10"`, `"10%"`, `"0.10"` — all meaning 10% |
| Payment casing | `"cash"`, `"Cash"`, `"CASH"`, `"bKash"`, `"nagad"` |
| ~200 duplicate IDs | Reused `sale_id` values from first 500 IDs |
| ~5% missing salesperson | Empty string in salesperson column |
| Blank/N/A categories | `""`, `"N/A"`, `"-"` scattered throughout |
| Bengali product names | `"পেঁয়াজ (Onion 20kg)"`, `"রসুন (Garlic 5kg)"` |
| Leading/trailing spaces | `" Chilli Powder 500g "`, `"  Turmeric Powder  "` |

---

## Running the Importer

### Via API (recommended)

```bash
curl -X POST http://localhost:8000/api/v1/import \
  -F "file=@shopease_sales_dirty.csv"
```

Response:
```json
{
  "message": "Import queued",
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "pending",
  "poll_url": "http://localhost:8000/api/v1/import/550e8400.../status"
}
```

### Poll for status

```bash
curl http://localhost:8000/api/v1/import/550e8400-e29b-41d4-a716-446655440000/status
```

Response when done:
```json
{
  "job_id": "550e8400-...",
  "status": "done",
  "total_rows": 20200,
  "inserted": 19987,
  "skipped_duplicates": 200,
  "skipped_invalid": 13,
  "error_log_url": "http://localhost:8000/api/v1/import/550e8400.../errors"
}
```

---

## API Documentation

### POST `/api/v1/import`

Upload a CSV or Excel file for import.

**Request:** `multipart/form-data`
- `file` — required, `.csv` / `.xls` / `.xlsx`, max 50 MB

**Response `202`:**
```json
{ "job_id": "uuid", "status": "pending", "poll_url": "..." }
```

---

### GET `/api/v1/import/{jobId}/status`

Poll import job status.

**Response:**
```json
{
  "job_id": "uuid",
  "status": "done",          // pending | processing | done | failed
  "total_rows": 20200,
  "inserted": 19987,
  "skipped_duplicates": 200,
  "skipped_invalid": 13,
  "error_log_url": "..."     // present only when there are invalid rows
}
```

---

### GET `/api/v1/sales`

Paginated sales list.

**Query params (all optional, all combinable):**

| Param | Example | Description |
|---|---|---|
| `branch` | `Gulshan` | Filter by branch |
| `from` | `2023-01-01` | Start date (inclusive) |
| `to` | `2023-12-31` | End date (inclusive) |
| `category` | `Grains & Staples` | Filter by category |
| `payment_method` | `bKash` | Filter by payment method |
| `page` | `2` | Page number |
| `per_page` | `50` | Rows per page (max 100) |

**Example:**
```bash
curl "http://localhost:8000/api/v1/sales?branch=Gulshan&from=2023-01-01&to=2023-06-30&page=1"
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "sale_id": "SLE-000042",
      "branch": "Gulshan",
      "sale_date": "2023-03-15",
      "product_name": "Rice (Miniket 50kg)",
      "category": "Grains & Staples",
      "quantity": 10,
      "unit_price": "2500.00",
      "discount_pct": "0.1000",
      "net_price": "2250.00",
      "revenue": "22500.00",
      "payment_method": "bKash",
      "salesperson": "Rahim Uddin"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 200,
    "per_page": 100,
    "total": 19987
  }
}
```

---

### GET `/api/v1/sales/summary`

Aggregated KPIs. Accepts the same filter params as `/sales`.

**Response:**
```json
{
  "overall": {
    "total_transactions": 19987,
    "total_revenue": 1284763200.50,
    "total_quantity": 2004321,
    "avg_order_value": 64279.84
  },
  "top_5_products": [
    { "product_name": "Rice (Miniket 50kg)", "product_revenue": 98423100, "product_quantity": 45231 }
  ],
  "branch_breakdown": [
    { "branch": "Gulshan", "transactions": 3412, "revenue": 234567890, "quantity": 341200 }
  ],
  "payment_breakdown": [
    { "payment_method": "bKash", "transactions": 6012, "revenue": 412345678 }
  ],
  "monthly_trend": [
    { "month": "2023-01", "revenue": 45321000, "transactions": 1204 }
  ]
}
```

---

### GET `/api/v1/export/csv` and `/api/v1/export/excel`

Export data. Accepts the same filter params as `/sales`.

**Small exports (≤ 10,000 rows):** File streams directly.

**Large exports (> 10,000 rows):** Returns `202` with a `job_id`:
```json
{
  "message": "Large export queued",
  "job_id": "uuid",
  "status": "pending",
  "poll_url": "http://localhost:8000/api/v1/export/uuid/status",
  "download_url": "http://localhost:8000/api/v1/export/uuid/download"
}
```

Excel exports include two sheets:
1. **Sales Data** — all filtered rows
2. **Summary** — per-branch revenue/quantity totals

CSV exports include a UTF-8 BOM (`\xEF\xBB\xBF`) for Bengali character compatibility in Excel.

---

### GET `/api/v1/export/{jobId}/status`

Poll export job.

```json
{ "job_id": "uuid", "status": "done", "ready": true, "download_url": "..." }
```

### GET `/api/v1/export/{jobId}/download`

Download the completed export file.

---

## Data Cleaning Decision Log

All decisions in `CsvCleanerService.php` are documented here:

### 1. Branch Normalisation
**Problem:** `"mirpur"`, `"MIRPUR"`, `"Mirpur "` (note trailing space).  
**Decision:** `trim()` → `strtolower()` → `mb_convert_case(MB_CASE_TITLE)`.  
`mb_convert_case` is used instead of `ucfirst()` because it handles multibyte characters safely.

### 2. Date Parsing
**Problem:** Three formats coexist — `d/m/Y` (15/03/2023), `Y-m-d` (2023-03-15), `m-d-Y` (03-15-2023).  
**Decision:** Try `Carbon::createFromFormat()` with each format in priority order. We use `createFromFormat` (not `Carbon::parse()`) to avoid ambiguous auto-detection — `parse()` would misread `03-15-2023` as `Y-m-d` and fail silently.  
As a last resort, `Carbon::parse()` handles edge cases like `2023-1-5`.

### 3. Unit Price
**Problem:** `৳1,200.00`, `1200.00`, `1200`.  
**Decision:** `preg_replace('/[৳,\s]/', '', $raw)` strips the Taka symbol, commas, and whitespace. Then cast to float and validate > 0.

### 4. Discount Normalisation ⚠️ CRITICAL
**Problem:** `"10"`, `"10%"`, `"0.10"` all mean 10% discount. Using wrong interpretation inflates/deflates revenue by 100×.  
**Decision:**  
1. Strip `%` character.  
2. If numeric value `>= 1` → divide by 100 (it was a whole-number percentage).  
3. If `< 1` → already a decimal (leave as-is).  
**Why this is safe:** No valid discount would be expressed as `0.10` meaning 10 percent (which would then become 0.001). A value of `0.10` will always mean 10%.

### 5. Category Nullification
**Problem:** Blank `""`, `"N/A"`, `"-"`, `"null"`, `"none"`.  
**Decision:** Normalise all to SQL `NULL`. The canonical null values list is in `CsvCleanerService::NULL_CATEGORY_VALUES`.

### 6. Missing Salesperson
**Problem:** ~5% of rows have an empty `salesperson` field.  
**Decision:** Set to `"Unknown"` per spec.

### 7. Duplicate Detection
**Problem:** ~200 intentionally duplicate `sale_id` rows. We cannot rely on `sale_id` alone because valid re-runs could submit the same ID with different data.  
**Decision:** Hash the entire raw row with `SHA-256` (`raw_row_hash` column with `UNIQUE` index). `insertOrIgnore()` silently discards hash collisions. This correctly catches identical rows regardless of ID.

### 8. Payment Method Canonicalisation
**Problem:** `"cash"`, `"Cash"`, `"CASH"`, `"bKash"`, `"bkash"`, `"BKASH"`.  
**Decision:** Lowercase the input and look up against a canonical alias map to a clean stored value (`Cash`, `bKash`, `Nagad`, `Bank Transfer`, `Card`). Unknown methods are treated as invalid.

---

## Known Traps & How We Solved Them

### Memory: `Sale::all()` on 20k rows
**Trap:** Loading all rows at once exhausts PHP memory.  
**Solution:**
- **Import:** `League\Csv\Reader` streams records lazily via `getRecords()`. We flush to DB in chunks of 500 using `DB::table('sales')->insertOrIgnore($chunk)`.
- **Export:** `Sale::query()->chunk(500, ...)` — Laravel's ORM-level chunking. Never calls `->get()` on the full dataset.
- **Summary:** Uses raw `selectRaw()` aggregations — the database does the math, only the result set is returned to PHP.

### Discount Off-by-100x
**Trap:** Row has `discount_pct = 10` (meaning 10%). If you multiply `unit_price * (1 - 10)` you get a negative revenue.  
**Solution:** Documented in Decision Log #4. The `>= 1` threshold check in `normaliseDiscount()` handles all three formats correctly.

### Bengali Characters in CSV
**Trap:** Bengali product names corrupt in Excel if the CSV isn't UTF-8 BOM encoded.  
**Solution:** CSV exports write `"\xEF\xBB\xBF"` as the first bytes. The generator also writes with `utf-8-sig` encoding (Python's name for UTF-8 with BOM).

### Date Ambiguity
**Trap:** `Carbon::parse("03-15-2023")` would throw. `Carbon::parse("01-05-2023")` would silently read as Jan 5th (Y-m-d) when it should be May 1st (m-d-Y).  
**Solution:** Explicit `createFromFormat()` with format validation before `parse()` fallback.
