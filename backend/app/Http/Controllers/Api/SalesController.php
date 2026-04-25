<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesController extends Controller
{
    /**
     * GET /api/v1/sales
     *
     * Query params (all optional, all combinable):
     *   branch, from, to, category, payment_method, page, per_page (max 100)
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 100), 100);

        $query = Sale::query()
            ->branch($request->get('branch'))
            ->dateRange($request->get('from'), $request->get('to'))
            ->category($request->get('category'))
            ->paymentMethod($request->get('payment_method'))
            ->orderBy('sale_date', 'desc')
            ->orderBy('id', 'desc');

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data'  => $paginator->items(),
            'meta'  => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/sales/summary
     *
     * Returns aggregated KPIs respecting the same filters as /sales.
     * Uses cursor-based aggregation — no full table load.
     */
    public function summary(Request $request): JsonResponse
    {
        $base = Sale::query()
            ->branch($request->get('branch'))
            ->dateRange($request->get('from'), $request->get('to'))
            ->category($request->get('category'))
            ->paymentMethod($request->get('payment_method'));

        // ── Overall KPIs ────────────────────────────────────────────────
        $overall = (clone $base)->selectRaw('
            COUNT(*)          AS total_transactions,
            SUM(revenue)      AS total_revenue,
            SUM(quantity)     AS total_quantity,
            AVG(revenue)      AS avg_order_value
        ')->first();

        // ── Top 5 products by revenue ────────────────────────────────────
        $top5 = (clone $base)
            ->selectRaw('product_name, SUM(revenue) AS product_revenue, SUM(quantity) AS product_quantity')
            ->groupBy('product_name')
            ->orderByDesc('product_revenue')
            ->limit(5)
            ->get();

        // ── Per-branch breakdown ─────────────────────────────────────────
        $branchBreakdown = (clone $base)
            ->selectRaw('branch, COUNT(*) AS transactions, SUM(revenue) AS revenue, SUM(quantity) AS quantity')
            ->groupBy('branch')
            ->orderByDesc('revenue')
            ->get();

        // ── Revenue by payment method ────────────────────────────────────
        $paymentBreakdown = (clone $base)
            ->selectRaw('payment_method, COUNT(*) AS transactions, SUM(revenue) AS revenue')
            ->groupBy('payment_method')
            ->orderByDesc('revenue')
            ->get();

        // ── Monthly trend (last 12 months) ───────────────────────────────
        $monthlyTrend = (clone $base)
            ->selectRaw("DATE_FORMAT(sale_date, '%Y-%m') AS month, SUM(revenue) AS revenue, COUNT(*) AS transactions")
            ->groupByRaw("DATE_FORMAT(sale_date, '%Y-%m')")
            ->orderBy('month', 'asc')
            ->limit(24)
            ->get();

        return response()->json([
            'overall' => [
                'total_transactions' => (int)   $overall->total_transactions,
                'total_revenue'      => (float) $overall->total_revenue,
                'total_quantity'     => (int)   $overall->total_quantity,
                'avg_order_value'    => (float) round($overall->avg_order_value, 2),
            ],
            'top_5_products'    => $top5,
            'branch_breakdown'  => $branchBreakdown,
            'payment_breakdown' => $paymentBreakdown,
            'monthly_trend'     => $monthlyTrend,
        ]);
    }
}
