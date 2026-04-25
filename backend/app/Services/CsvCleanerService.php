<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Str;

class CsvCleanerService
{
    private const PAYMENT_ALIASES = [
        'cash'          => 'Cash',
        'bkash'         => 'bKash',
        'nagad'         => 'Nagad',
        'bank_transfer' => 'Bank Transfer',
        'bank transfer' => 'Bank Transfer',
        'card'          => 'Card',
    ];

    private const NULL_CATEGORY_VALUES = ['', 'n/a', '-', 'null', 'none', 'na'];


    public function clean(array $raw): array
    {
        $errors = [];
        $branch = $this->normaliseBranch($raw['branch'] ?? '');
        if (empty($branch)) {
            $errors[] = "Invalid branch: " . ($raw['branch'] ?? '(empty)');
        }

        [$saleDate, $dateError] = $this->normaliseDate($raw['sale_date'] ?? '');
        if ($dateError) {
            $errors[] = $dateError;
        }

        $productName = trim($raw['product_name'] ?? '');
        if (empty($productName)) {
            $errors[] = "Missing product_name";
        }

        $quantity = (int) ($raw['quantity'] ?? 0);
        if ($quantity <= 0) {
            $errors[] = "Invalid quantity: " . ($raw['quantity'] ?? '(empty)');
        }

        [$unitPrice, $priceError] = $this->normalisePrice($raw['unit_price'] ?? '');
        if ($priceError) {
            $errors[] = $priceError;
        }

        [$discountPct, $discError] = $this->normaliseDiscount($raw['discount_pct'] ?? '0');
        if ($discError) {
            $errors[] = $discError;
        }

        $category = $this->normaliseCategory($raw['category'] ?? '');

        $paymentMethod = $this->normalisePaymentMethod($raw['payment_method'] ?? '');
        if (empty($paymentMethod)) {
            $errors[] = "Unknown payment_method: " . ($raw['payment_method'] ?? '(empty)');
        }

        $salesperson = $this->normaliseSalesperson($raw['salesperson'] ?? '');

        if (!empty($errors)) {
            return ['clean' => [], 'errors' => $errors];
        }

        $netPrice = round($unitPrice * (1 - $discountPct), 2);
        $revenue  = round($netPrice * $quantity, 2);

        $hash = hash('sha256', implode('|', array_values($raw)));

        return [
            'clean' => [
                'sale_id'        => strtoupper(trim($raw['sale_id'] ?? '')),
                'branch'         => $branch,
                'sale_date'      => $saleDate,
                'product_name'   => $productName,
                'category'       => $category,
                'quantity'       => $quantity,
                'unit_price'     => $unitPrice,
                'discount_pct'   => $discountPct,
                'net_price'      => $netPrice,
                'revenue'        => $revenue,
                'payment_method' => $paymentMethod,
                'salesperson'    => $salesperson,
                'raw_row_hash'   => $hash,
            ],
            'errors' => [],
        ];
    }


    private function normaliseBranch(string $raw): string
    {
        $trimmed = trim($raw);
        if (empty($trimmed)) return '';
        return mb_convert_case(strtolower($trimmed), MB_CASE_TITLE, 'UTF-8');
    }

    private function normaliseDate(string $raw): array
    {
        $raw = trim($raw);
        if (empty($raw)) return [null, "Missing sale_date"];

        $formats = ['Y-m-d', 'd/m/Y', 'm-d-Y'];
        foreach ($formats as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $raw);
                if ($d && $d->format($fmt) === $raw) {
                    return [$d->format('Y-m-d'), null];
                }
            } catch (InvalidFormatException) {
                // try next format
            }
        }

        try {
            return [Carbon::parse($raw)->format('Y-m-d'), null];
        } catch (\Exception) {
            return [null, "Unparseable date: $raw"];
        }
    }

    private function normalisePrice(string $raw): array
    {
        $cleaned = preg_replace('/[৳,\s]/', '', $raw);
        if (!is_numeric($cleaned) || (float)$cleaned <= 0) {
            return [null, "Invalid unit_price: $raw"];
        }
        return [(float) $cleaned, null];
    }


    private function normaliseDiscount(string $raw): array
    {
        $stripped = trim(str_replace('%', '', $raw));
        if (!is_numeric($stripped)) {
            return [null, "Invalid discount_pct: $raw"];
        }
        $val = (float) $stripped;
        if ($val < 0) {
            return [null, "Negative discount_pct: $raw"];
        }
        if ($val >= 1) {
            $val = $val / 100;
        }
        if ($val > 1) {
            return [null, "Discount > 100%: $raw"];
        }
        return [round($val, 4), null];
    }

    private function normaliseCategory(string $raw): ?string
    {
        $lower = strtolower(trim($raw));
        return in_array($lower, self::NULL_CATEGORY_VALUES, true) ? null : trim($raw);
    }

    private function normalisePaymentMethod(string $raw): string
    {
        $key = strtolower(trim($raw));
        return self::PAYMENT_ALIASES[$key] ?? '';
    }

    private function normaliseSalesperson(string $raw): string
    {
        $trimmed = trim($raw);
        return empty($trimmed) ? 'Unknown' : $trimmed;
    }
}
