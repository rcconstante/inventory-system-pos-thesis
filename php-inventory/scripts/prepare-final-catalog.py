"""Read the two supplied workbooks; emit the validated application catalog (no workbook edits)."""
import hashlib
import json
from datetime import datetime
from pathlib import Path

import openpyxl

ROOT = Path(__file__).resolve().parents[2]
PRODUCTS = ROOT / 'Five_Brothers_Trading_220_Dates_Formatted.xlsx'
BATCHES = ROOT / 'Five_Brothers_Trading_Batches_Per_Product.xlsx'


def records(path, sheet):
    workbook = openpyxl.load_workbook(path, read_only=True, data_only=True)
    rows = list(workbook[sheet].values)
    workbook.close()
    return [dict(zip(rows[0], row)) for row in rows[1:] if any(v is not None for v in row)]


def date(value, batch=False):
    if value is None:
        return None
    if isinstance(value, datetime):
        return value.date().isoformat()
    return datetime.strptime(value, '%d/%m/%Y' if batch else '%Y-%m-%d %H:%M:%S').date().isoformat()


def integer(value):
    if not isinstance(value, (int, float)) or value < 0 or int(value) != value:
        raise ValueError(f'Invalid quantity: {value!r}')
    return int(value)


products = []
for number, row in enumerate(records(PRODUCTS, 'Products'), 1):
    products.append({
        'number': number, 'category': row['Category'], 'name': row['Product Name'],
        'brand': row['Brand Name'], 'type': row['Product Type'],
        'specification': row['Additional Details'] or '', 'description': row['Description'] or '',
        'fitment': {key: (str(row[column]).lower() if key == 'position' else str(row[column])) for key, column in [
            ('size', 'Tire Size'), ('position', 'Position'), ('voltage', 'Voltage'), ('viscosity', 'Oil Viscosity')
        ] if row[column] is not None},
        'compatibility': [name.strip() for name in row['Compatibility (select multiple)'].split(';')],
        'manufacturing_date': date(row['Manufacturing Date']), 'expiration_date': date(row['Expiration Date']),
        'minimum': integer(row['Critical Stock (Reorder Level)']), 'quantity': integer(row['Quantity']),
        'retail_price': row['Retail Price (PHP)'], 'cost': row['Acquisition Cost (PHP)'],
        'price': row['Selling Price (PHP)'], 'batches': [],
    })

seen = set()
for row in records(BATCHES, 'Product Batches'):
    number = integer(row['Product No.'])
    if not 1 <= number <= len(products):
        raise ValueError(f'Unknown product number {number}')
    product = products[number - 1]
    for column, key in [('Category', 'category'), ('Product Name', 'name'), ('Brand', 'brand'), ('Product Type', 'type')]:
        if row[column] != product[key]:
            raise ValueError(f'Product {number}: batch {column} mismatch')
    expected_specification = ' | '.join([product['specification']] + [
        product['fitment'][key] for key in ['size', 'voltage', 'viscosity'] if key in product['fitment']
    ])
    if ' '.join(expected_specification.lower().split()) != ' '.join(row['Specifications'].lower().split()):
        raise ValueError(f'Product {number}: batch specification mismatch')
    batch_key = (number, row['Batch Number'])
    if batch_key in seen:
        raise ValueError(f'Duplicate batch: {batch_key}')
    seen.add(batch_key)
    product['batches'].append({
        'number': row['Batch Number'], 'quantity': integer(row['Quantity']), 'cost': row['Cost Price (PHP)'],
        'manufacturing_date': date(row['Manufacturing Date'], True),
        'expiration_date': date(row['Expiration Date'], True),
    })

for product in products:
    if sum(batch['quantity'] for batch in product['batches']) != product['quantity']:
        raise ValueError(f"Stock does not reconcile for product {product['number']}")

result = {
    'sources': {path.name: hashlib.sha256(path.read_bytes()).hexdigest() for path in [PRODUCTS, BATCHES]},
    'product_count': len(products), 'batch_count': len(seen),
    'total_quantity': sum(product['quantity'] for product in products), 'products': products,
}
destination = Path(__file__).resolve().parents[1] / 'catalog' / 'final-products.php'
destination.parent.mkdir(exist_ok=True)
destination.write_text("<?php\ndeclare(strict_types=1);\nreturn json_decode(<<<'FINAL_CATALOG_JSON'\n" + json.dumps(result, ensure_ascii=False, indent=2) + "\nFINAL_CATALOG_JSON, true, 64, JSON_THROW_ON_ERROR);\n", encoding='utf-8')
print(f"Validated {result['product_count']} products, {result['batch_count']} batches, {result['total_quantity']} units.")
