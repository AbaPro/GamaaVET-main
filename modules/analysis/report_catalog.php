<?php

/**
 * Curated report catalogue.
 *
 * The scope field is intentionally explicit. Sales reports work with final
 * products and customers, while purchasing reports work with raw materials
 * and vendors. Keeping that rule here prevents unrelated filters from being
 * added to a report later.
 */
function getAnalysisReportCatalog(): array
{
    return [
        'sales_overview' => [
            'group' => 'Sales & Customers',
            'title' => 'Sales Overview',
            'description' => 'Monthly orders, final-product units, discounts, invoiced sales, payments, and open balance.',
            'icon' => 'fa-chart-line',
            'scope' => 'Final Products',
            'filters' => ['date', 'customer'],
            'customer_source' => 'orders',
        ],
        'final_product_sales' => [
            'group' => 'Sales & Customers',
            'title' => 'Final Product Sales',
            'description' => 'Sold quantity and line value by customer-owned final product.',
            'icon' => 'fa-box',
            'scope' => 'Final Products',
            'filters' => ['date', 'customer', 'category_final'],
            'customer_source' => 'final_products',
        ],
        'customer_sales' => [
            'group' => 'Sales & Customers',
            'title' => 'Customer Sales',
            'description' => 'Orders, invoiced sales, paid amount, balance, and latest order by customer.',
            'icon' => 'fa-users',
            'scope' => 'Customers',
            'filters' => ['date', 'customer'],
            'customer_source' => 'orders',
        ],
        'open_receivables' => [
            'group' => 'Sales & Customers',
            'title' => 'Open Receivables',
            'description' => 'Current unpaid customer orders, grouped by customer and currency.',
            'icon' => 'fa-file-invoice-dollar',
            'scope' => 'Receivables',
            'filters' => ['customer'],
            'customer_source' => 'open_orders',
        ],
        'returns_by_product' => [
            'group' => 'Sales & Customers',
            'title' => 'Returns by Final Product',
            'description' => 'Returned quantity and recorded return value by final product and customer.',
            'icon' => 'fa-rotate-left',
            'scope' => 'Final Products',
            'filters' => ['date', 'customer', 'category_final'],
            'customer_source' => 'returns',
        ],
        'final_product_stock' => [
            'group' => 'Inventory',
            'title' => 'Final Product Stock',
            'description' => 'Finished-goods quantity by customer, product, and inventory location.',
            'icon' => 'fa-boxes-stacked',
            'scope' => 'Final Products',
            'filters' => ['customer', 'category_final', 'inventory'],
            'customer_source' => 'final_products',
        ],
        'raw_material_stock' => [
            'group' => 'Inventory',
            'title' => 'Raw Material Stock',
            'description' => 'Raw-material quantity by material and inventory location.',
            'icon' => 'fa-layer-group',
            'scope' => 'Raw Materials',
            'filters' => ['category_material', 'inventory'],
        ],
        'stock_alerts' => [
            'group' => 'Inventory',
            'title' => 'Stock Alerts',
            'description' => 'Products whose total stock is at or below their configured minimum.',
            'icon' => 'fa-triangle-exclamation',
            'scope' => 'Stock Levels',
            'filters' => ['inventory'],
        ],
        'purchase_overview' => [
            'group' => 'Purchasing',
            'title' => 'Purchase Overview',
            'description' => 'Monthly purchase orders, raw-material quantities, receipts, spend, payments, and balance.',
            'icon' => 'fa-basket-shopping',
            'scope' => 'Raw Materials',
            'filters' => ['date', 'vendor'],
        ],
        'material_purchases' => [
            'group' => 'Purchasing',
            'title' => 'Material Purchases by Vendor',
            'description' => 'Ordered and received raw-material quantity by vendor and material.',
            'icon' => 'fa-truck-ramp-box',
            'scope' => 'Vendors & Materials',
            'filters' => ['date', 'vendor', 'category_material'],
        ],
        'open_purchase_orders' => [
            'group' => 'Purchasing',
            'title' => 'Open Purchase Orders',
            'description' => 'New, ordered, and partially received POs with receipt progress and balance.',
            'icon' => 'fa-clipboard-list',
            'scope' => 'Open Purchase Orders',
            'filters' => ['vendor'],
        ],
        'vendor_payables' => [
            'group' => 'Purchasing',
            'title' => 'Vendor Payables',
            'description' => 'Current unpaid purchase-order balance by vendor.',
            'icon' => 'fa-file-contract',
            'scope' => 'Payables',
            'filters' => ['vendor'],
        ],
        'payments_by_method' => [
            'group' => 'Finance',
            'title' => 'Payments by Method',
            'description' => 'Customer receipts and vendor payments by method, direction, period, and currency.',
            'icon' => 'fa-cash-register',
            'scope' => 'Payments',
            'filters' => ['date'],
        ],
        'cash_positions' => [
            'group' => 'Finance',
            'title' => 'Cash Positions',
            'description' => 'Current balances held in safes, bank accounts, and personal accounts.',
            'icon' => 'fa-coins',
            'scope' => 'Accounts',
            'filters' => [],
        ],
    ];
}

function getAnalysisReportGroups(): array
{
    return [
        'Sales & Customers' => [
            'icon' => 'fa-cart-shopping',
            'permission' => 'analysis.view_sales_summary',
        ],
        'Inventory' => [
            'icon' => 'fa-warehouse',
            'permission' => 'analysis.view_inventory_levels',
        ],
        'Purchasing' => [
            'icon' => 'fa-truck',
            'permission' => 'analysis.view_purchase_summary',
        ],
        'Finance' => [
            'icon' => 'fa-money-bill-transfer',
            'permission' => 'analysis.view_finance_reports',
        ],
    ];
}
