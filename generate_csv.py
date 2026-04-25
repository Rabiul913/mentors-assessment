#!/usr/bin/env python3
"""
ShopEase BD — Dirty CSV Generator
Generates ~20,000 rows of intentionally messy sales data.
Run: python3 generate_csv.py
Output: shopease_sales_dirty.csv
"""

import csv
import random
import string
from datetime import date, timedelta

random.seed(42)


BRANCHES_CLEAN = ["Mirpur", "Gulshan", "Dhanmondi", "Uttara", "Motijheel", "Chattogram"]

BRANCH_DIRTY_VARIANTS = {
    "Mirpur":      ["mirpur", "Mirpur ", "MIRPUR", "Mirpur"],
    "Gulshan":     ["gulshan", "Gulshan ", "GULSHAN", "Gulshan"],
    "Dhanmondi":   ["dhanmondi", "Dhanmondi ", "DHANMONDI", "Dhanmondi"],
    "Uttara":      ["uttara", "Uttara ", "UTTARA", "Uttara"],
    "Motijheel":   ["motijheel", "Motijheel ", "MOTIJHEEL", "Motijheel"],
    "Chattogram":  ["chattogram", "Chattogram ", "CHATTOGRAM", "Chattogram"],
}

PRODUCTS = [
    "Rice (Miniket 50kg)", "Rice (Nazirshail 50kg)", "Lentil (Masur)", "Lentil (Mung)",
    "Mustard Oil (5L)", "Soybean Oil (5L)", "Sugar (50kg)", "Salt (1kg)",
    "Flour (Atta 10kg)", "Semolina (Suji 1kg)", "Tea (500g)", "Milk Powder (1kg)",
    "Biscuit (Assorted)", "Noodles (Box)", "Soap (Lifebuoy 12pk)", "Detergent (Wheel 1kg)",
    "Shampoo (Sunsilk 400ml)", "Toothpaste (Colgate)", "Bottled Water (24pk)",
    "পেঁয়াজ (Onion 20kg)",
    "রসুন (Garlic 5kg)",   
    "আলু (Potato 40kg)",   
    " Chilli Powder 500g ",
    "  Turmeric Powder  ", 
]

CATEGORIES = [
    "Grains & Staples", "Oils & Fats", "Spices & Condiments",
    "Beverages", "Personal Care", "Household", "Vegetables",
    "",   
    "N/A",
    "-",  
    None, 
]

PAYMENT_METHODS = [
    "cash", "Cash", "CASH",
    "bKash", "bkash", "BKASH",
    "nagad", "Nagad", "NAGAD",
    "bank_transfer", "Bank Transfer",
    "card",
]

SALESPERSONS = [
    "Rahim Uddin", "Karim Ahmed", "Nasrin Begum", "Farhan Hossain",
    "Sumaiya Islam", "Tanvir Rahman", "Ritu Akter", "Imran Khan",
    "Shirin Sultana", "Mahbub Alam", "Fatema Khatun", "Jahangir Alam",
]


START_DATE = date(2022, 1, 1)
END_DATE   = date(2024, 1, 31)
DATE_RANGE = (END_DATE - START_DATE).days

def random_date() -> date:
    return START_DATE + timedelta(days=random.randint(0, DATE_RANGE))

def format_date_dirty(d: date) -> str:
    """Return date in one of three messy formats."""
    fmt = random.choice(["dmy", "ymd", "mdy"])
    if fmt == "dmy":
        return d.strftime("%d/%m/%Y")
    elif fmt == "ymd":
        return d.strftime("%Y-%m-%d") 
    else:
        return d.strftime("%m-%d-%Y") 


def format_price_dirty(price: float) -> str:
    if random.random() < 0.3:
        return f"৳{price:,.2f}"    
    elif random.random() < 0.5:
        return f"{price:.2f}"
    else:
        return str(int(price)) 

def format_discount_dirty(pct: float) -> str:
    """pct is 0.0–1.0 conceptually (e.g. 0.10 = 10%)"""
    choice = random.randint(0, 2)
    if choice == 0:
        return str(int(pct * 100))
    elif choice == 1:
        return f"{int(pct * 100)}%"  
    else:
        return str(pct)   


def make_sale_id(idx: int) -> str:
    return f"SLE-{idx:06d}"

def generate_rows(total: int = 20200) -> list[dict]:
    rows = []
    base_ids = list(range(1, total - 199))

    duplicate_ids = random.choices(base_ids[:500], k=200)
    all_ids = base_ids + duplicate_ids
    random.shuffle(all_ids)

    for idx, sale_idx in enumerate(all_ids[:total]):
        branch_key = random.choice(BRANCHES_CLEAN)
        variants   = BRANCH_DIRTY_VARIANTS[branch_key]
        branch     = random.choice(variants)

        product = random.choice(PRODUCTS)
        qty     = random.randint(1, 200)
        price   = round(random.uniform(50, 5000), 2)
        disc_f  = round(random.choice([0.0, 0.05, 0.10, 0.15, 0.20, 0.25]), 2)

        cat_raw = random.choice(CATEGORIES)
        category = cat_raw if cat_raw is not None else ""

        if random.random() < 0.05:
            salesperson = ""
        else:
            salesperson = random.choice(SALESPERSONS)

        row = {
            "sale_id":        make_sale_id(sale_idx),
            "branch":         branch,
            "sale_date":      format_date_dirty(random_date()),
            "product_name":   product,
            "category":       category,
            "quantity":       qty,
            "unit_price":     format_price_dirty(price),
            "discount_pct":   format_discount_dirty(disc_f),
            "payment_method": random.choice(PAYMENT_METHODS),
            "salesperson":    salesperson,
        }
        rows.append(row)

    return rows


FIELDNAMES = [
    "sale_id", "branch", "sale_date", "product_name", "category",
    "quantity", "unit_price", "discount_pct", "payment_method", "salesperson",
]

def main():
    output_file = "shopease_sales_dirty.csv"
    rows = generate_rows(20200)

    with open(output_file, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=FIELDNAMES)
        writer.writeheader()
        writer.writerows(rows)

    print(f"✅  Generated {len(rows):,} rows → {output_file}")
    print("    Intentional issues baked in:")
    print("    • Mixed branch casing (mirpur / Mirpur / MIRPUR)")
    print("    • 3 date formats (d/m/Y, Y-m-d, m-d-Y)")
    print("    • Unit prices with ৳ symbol and commas")
    print("    • Discount as integer / '10%' / 0.10")
    print("    • Mixed payment_method casing")
    print("    • ~200 duplicate sale_id rows")
    print("    • ~5% rows with missing salesperson")
    print("    • Blank / N/A / '-' categories")
    print("    • Products with Bengali script and leading/trailing spaces")

if __name__ == "__main__":
    main()
