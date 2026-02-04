<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$page_title = 'Analysis';
require_once '../../includes/header.php';

$cards = [
    [
        'key' => 'sales_summary',
        'title' => 'Sales Summary',
        'desc' => 'Monthly orders, sales, paid, and balance.',
        'group' => 'Sales',
        'icon' => 'fa-chart-line'
    ],
    [
        'key' => 'gross_sales',
        'title' => 'Gross Sales',
        'desc' => 'Total sales before discounts and returns.',
        'group' => 'Sales',
        'icon' => 'fa-coins'
    ],
    [
        'key' => 'net_sales',
        'title' => 'Net Sales',
        'desc' => 'Sales after discounts and returns.',
        'group' => 'Sales',
        'icon' => 'fa-receipt'
    ],
    [
        'key' => 'sales_growth',
        'title' => 'Sales Growth',
        'desc' => 'Period-over-period sales change.',
        'group' => 'Sales',
        'icon' => 'fa-arrow-trend-up'
    ],
    [
        'key' => 'average_transaction_value',
        'title' => 'Average Transaction Value (ATV)',
        'desc' => 'Average order value per transaction.',
        'group' => 'Sales',
        'icon' => 'fa-ticket-simple'
    ],
    [
        'key' => 'items_per_transaction',
        'title' => 'Items per Transaction (IPT)',
        'desc' => 'Average items per order.',
        'group' => 'Sales',
        'icon' => 'fa-boxes-stacked'
    ],
    [
        'key' => 'revenue_per_hour',
        'title' => 'Revenue per Hour',
        'desc' => 'Hourly sales performance.',
        'group' => 'Sales',
        'icon' => 'fa-clock'
    ],
    [
        'key' => 'sales_per_day',
        'title' => 'Sales per Day',
        'desc' => 'Daily sales totals.',
        'group' => 'Sales',
        'icon' => 'fa-calendar-day'
    ],
    [
        'key' => 'sales_by_product',
        'title' => 'Sales by Product',
        'desc' => 'Top products by quantity and revenue.',
        'group' => 'Sales',
        'icon' => 'fa-box'
    ],
    [
        'key' => 'discount_rate',
        'title' => 'Discount Rate',
        'desc' => 'Average discount percentage applied.',
        'group' => 'Sales',
        'icon' => 'fa-percent'
    ],
    [
        'key' => 'discount_impact',
        'title' => 'Discount Impact',
        'desc' => 'Discount value and effect on revenue.',
        'group' => 'Sales',
        'icon' => 'fa-tags'
    ],
    [
        'key' => 'total_sales_today',
        'title' => 'Total Sales Today',
        'desc' => 'Today\'s sales total.',
        'group' => 'Sales',
        'icon' => 'fa-calendar-check'
    ],
    [
        'key' => 'total_sales_this_month',
        'title' => 'Total Sales This Month',
        'desc' => 'Month-to-date sales total.',
        'group' => 'Sales',
        'icon' => 'fa-calendar'
    ],
    [
        'key' => 'top_products_overview',
        'title' => 'Top Products Overview',
        'desc' => 'Best-selling products snapshot.',
        'group' => 'Sales',
        'icon' => 'fa-star'
    ],
    [
        'key' => 'top_categories_overview',
        'title' => 'Top Categories Overview',
        'desc' => 'Best-performing categories snapshot.',
        'group' => 'Sales',
        'icon' => 'fa-sitemap'
    ],
    [
        'key' => 'customer_lifetime_value',
        'title' => 'Customer Lifetime Value (CLV)',
        'desc' => 'Total value per customer over time.',
        'group' => 'Sales',
        'icon' => 'fa-user-clock'
    ],
    [
        'key' => 'repeat_purchase_rate',
        'title' => 'Repeat Purchase Rate',
        'desc' => 'Share of customers who buy again.',
        'group' => 'Sales',
        'icon' => 'fa-rotate'
    ],
    [
        'key' => 'customer_purchase_frequency',
        'title' => 'Customer Purchase Frequency',
        'desc' => 'Average orders per customer.',
        'group' => 'Sales',
        'icon' => 'fa-user-check'
    ],
    [
        'key' => 'average_basket_size',
        'title' => 'Average Basket Size',
        'desc' => 'Average items per order.',
        'group' => 'Sales',
        'icon' => 'fa-basket-shopping'
    ],
    [
        'key' => 'new_vs_returning_customers',
        'title' => 'New vs Returning Customers',
        'desc' => 'Sales split by new and returning.',
        'group' => 'Sales',
        'icon' => 'fa-user-plus'
    ],
    [
        'key' => 'inactive_customers',
        'title' => 'Inactive Customers',
        'desc' => 'Customers without recent orders.',
        'group' => 'Sales',
        'icon' => 'fa-user-slash'
    ],
    [
        'key' => 'top_customers',
        'title' => 'Top Customers',
        'desc' => 'Customers ranked by total sales.',
        'group' => 'Sales',
        'icon' => 'fa-users'
    ],
    [
        'key' => 'cash_sales',
        'title' => 'Cash Sales',
        'desc' => 'Sales paid by cash.',
        'group' => 'Finance',
        'icon' => 'fa-money-bill'
    ],
    [
        'key' => 'credit_sales',
        'title' => 'Credit Sales',
        'desc' => 'Sales on credit or unpaid balance.',
        'group' => 'Finance',
        'icon' => 'fa-credit-card'
    ],
    [
        'key' => 'accounts_receivable',
        'title' => 'Accounts Receivable',
        'desc' => 'Open customer balances.',
        'group' => 'Finance',
        'icon' => 'fa-file-invoice-dollar'
    ],
    [
        'key' => 'accounts_payable',
        'title' => 'Accounts Payable',
        'desc' => 'Open vendor balances.',
        'group' => 'Finance',
        'icon' => 'fa-file-contract'
    ],
    [
        'key' => 'customer_balances',
        'title' => 'Customer Wallets',
        'desc' => 'Wallet balances by customer.',
        'group' => 'Finance',
        'icon' => 'fa-wallet'
    ],
    [
        'key' => 'inventory_levels',
        'title' => 'Inventory Levels',
        'desc' => 'Stock by inventory and product.',
        'group' => 'Inventory',
        'icon' => 'fa-warehouse'
    ],
    [
        'key' => 'low_stock',
        'title' => 'Low Stock',
        'desc' => 'Products below minimum stock.',
        'group' => 'Inventory',
        'icon' => 'fa-triangle-exclamation'
    ],
    [
        'key' => 'purchase_summary',
        'title' => 'Purchase Summary',
        'desc' => 'Monthly PO totals and balances.',
        'group' => 'Purchasing',
        'icon' => 'fa-basket-shopping'
    ],
    [
        'key' => 'purchase_cost',
        'title' => 'Purchase Cost',
        'desc' => 'Total cost of purchased goods.',
        'group' => 'Purchasing',
        'icon' => 'fa-money-bill-wave'
    ],
    [
        'key' => 'purchase_volume',
        'title' => 'Purchase Volume',
        'desc' => 'Quantity purchased over time.',
        'group' => 'Purchasing',
        'icon' => 'fa-boxes-stacked'
    ],
    [
        'key' => 'purchases_by_supplier',
        'title' => 'Purchases by Supplier',
        'desc' => 'Purchases grouped by vendor.',
        'group' => 'Purchasing',
        'icon' => 'fa-truck'
    ],
    [
        'key' => 'outstanding_purchase_orders',
        'title' => 'Outstanding Purchase Orders',
        'desc' => 'Open purchase orders and balances.',
        'group' => 'Purchasing',
        'icon' => 'fa-file-invoice'
    ],
    [
        'key' => 'vendor_balances',
        'title' => 'Vendor Wallets',
        'desc' => 'Wallet balances by vendor.',
        'group' => 'Purchasing',
        'icon' => 'fa-truck'
    ],
    [
        'key' => 'sales_per_employee',
        'title' => 'Sales per Employee',
        'desc' => 'Sales attributed to each staff member.',
        'group' => 'Operations',
        'icon' => 'fa-user-tie'
    ],
    [
        'key' => 'returns_summary',
        'title' => 'Returns Summary',
        'desc' => 'Returned quantities by product.',
        'group' => 'Operations',
        'icon' => 'fa-rotate-left'
    ],
    [
        'key' => 'product_margin',
        'title' => 'Product Margin',
        'desc' => 'Revenue, estimated cost, and margin by product.',
        'group' => 'Accounting',
        'icon' => 'fa-chart-pie'
    ],
    [
        'key' => 'ar_aging',
        'title' => 'Accounts Receivable Aging',
        'desc' => 'Outstanding balances grouped by aging buckets.',
        'group' => 'Accounting',
        'icon' => 'fa-file-invoice-dollar'
    ],
    [
        'key' => 'ap_aging',
        'title' => 'Accounts Payable Aging',
        'desc' => 'Vendor balances grouped by aging buckets.',
        'group' => 'Accounting',
        'icon' => 'fa-file-contract'
    ],
    [
        'key' => 'cashflow_summary',
        'title' => 'Cashflow Transfers',
        'desc' => 'Transfers between safes, banks, and personal.',
        'group' => 'Finance',
        'icon' => 'fa-right-left'
    ],
    [
        'key' => 'payments_by_method',
        'title' => 'Payments by Method',
        'desc' => 'Incoming and outgoing payments by method.',
        'group' => 'Finance',
        'icon' => 'fa-cash-register'
    ],
    [
        'key' => 'inventory_valuation',
        'title' => 'Inventory Valuation',
        'desc' => 'Estimated stock value by inventory.',
        'group' => 'Inventory',
        'icon' => 'fa-layer-group'
    ],
    [
        'key' => 'quotation_pipeline',
        'title' => 'Quotation Pipeline',
        'desc' => 'Quotation totals grouped by status.',
        'group' => 'Sales',
        'icon' => 'fa-file-invoice'
    ],
    [
        'key' => 'sales_by_category',
        'title' => 'Sales by Category',
        'desc' => 'Revenue and volume grouped by category.',
        'group' => 'Sales',
        'icon' => 'fa-layer-group'
    ],
    [
        'key' => 'sales_by_customer_type',
        'title' => 'Sales by Customer Type',
        'desc' => 'Sales split by customer type.',
        'group' => 'Sales',
        'icon' => 'fa-user-tag'
    ],
    [
        'key' => 'order_status_mix',
        'title' => 'Order Status Mix',
        'desc' => 'Orders grouped by status.',
        'group' => 'Sales',
        'icon' => 'fa-clipboard-check'
    ],
    [
        'key' => 'po_status_mix',
        'title' => 'PO Status Mix',
        'desc' => 'Purchase orders grouped by status.',
        'group' => 'Purchasing',
        'icon' => 'fa-list-check'
    ],
    [
        'key' => 'inter_branch_transfers',
        'title' => 'Inter-Branch Transfers',
        'desc' => 'Transfers volume by status and period.',
        'group' => 'Inventory',
        'icon' => 'fa-right-left'
    ],
    [
        'key' => 'cash_positions',
        'title' => 'Cash Positions',
        'desc' => 'Balances across safes, banks, and personal.',
        'group' => 'Finance',
        'icon' => 'fa-coins'
    ],
    [
        'key' => 'customer_wallet_activity',
        'title' => 'Customer Wallet Activity',
        'desc' => 'Wallet transactions by type and period.',
        'group' => 'Finance',
        'icon' => 'fa-wallet'
    ],
    [
        'key' => 'vendor_wallet_activity',
        'title' => 'Vendor Wallet Activity',
        'desc' => 'Vendor transactions by type and period.',
        'group' => 'Purchasing',
        'icon' => 'fa-money-bill-transfer'
    ],
    [
        'key' => 'user_activity_log',
        'title' => 'User Activity Log',
        'desc' => 'System actions by user and time.',
        'group' => 'Operations',
        'icon' => 'fa-clipboard-list'
    ],
];
$reportCount = count($cards);
$lastUpdated = date('M j, Y');
$groups = [
    'Sales',
    'Inventory',
    'Purchasing',
    'Finance',
    'Accounting',
    'Operations'
];
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap');

    :root {
        --analysis-ink: #0f172a;
        --analysis-slate: #475569;
        --analysis-soft: #e2e8f0;
        --analysis-brand: #0ea5e9;
        --analysis-accent: #f59e0b;
        --analysis-bg1: #f8fafc;
        --analysis-bg2: #eef2ff;
    }

    .analysis-shell {
        font-family: 'Space Grotesk', system-ui, sans-serif;
        color: var(--analysis-ink);
    }

    .analysis-hero {
        background: linear-gradient(135deg, var(--analysis-bg2), #fef3c7 55%, var(--analysis-bg1));
        border: 1px solid var(--analysis-soft);
        border-radius: 18px;
        padding: 28px;
        position: relative;
        overflow: hidden;
    }

    .analysis-hero::after {
        content: "";
        position: absolute;
        inset: 0;
        background-image: radial-gradient(circle at 20% 20%, rgba(14, 165, 233, 0.18), transparent 40%),
            radial-gradient(circle at 80% 0%, rgba(245, 158, 11, 0.18), transparent 45%);
        pointer-events: none;
    }

    .analysis-hero h2 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .analysis-hero p {
        color: var(--analysis-slate);
        margin-bottom: 18px;
    }

    .analysis-kpi {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
    }

    .analysis-kpi .kpi {
        background: #fff;
        border: 1px solid var(--analysis-soft);
        border-radius: 14px;
        padding: 12px 16px;
        min-width: 160px;
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
    }

    .analysis-kpi .kpi .label {
        font-size: 12px;
        color: var(--analysis-slate);
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .analysis-kpi .kpi .value {
        font-size: 20px;
        font-weight: 600;
        margin-top: 4px;
    }

    .analysis-grid .card {
        border-radius: 16px;
        border: 1px solid var(--analysis-soft);
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
        transition: transform .2s ease, box-shadow .2s ease;
    }

    .analysis-grid .card:hover {
        transform: translateY(-4px);
        box-shadow: 0 14px 30px rgba(15, 23, 42, 0.12);
    }

    .analysis-card-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(14, 165, 233, 0.12);
        color: var(--analysis-brand);
        margin-bottom: 12px;
        font-size: 18px;
    }

    .analysis-cta {
        background: var(--analysis-brand);
        border: none;
    }

    .analysis-cta:hover {
        background: #0284c7;
    }

    /* 5 columns on lg using a custom width (Bootstrap doesn't have 5-col grid by default) */
    @media (min-width: 992px) {
        .col-lg-20 {
            flex: 0 0 auto;
            width: 20%;
        }
    }

    /* Make the link behave like a card (no underline, full height) */
    .analysis-card-link {
        display: block;
        height: 100%;
        text-decoration: none;
        color: inherit;
    }

    /* Smaller, tighter card */
    .analysis-card {
        border-radius: 12px;
        transition: background-color .18s ease, transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        cursor: pointer;
    }

    /* Icon box smaller */
    .analysis-card-icon {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        display: grid;
        place-items: center;
        font-size: 16px;
    }

    /* Title beside icon, slightly smaller */
    .analysis-card-title {
        font-size: 0.95rem;
        font-weight: 600;
        line-height: 1.2;
    }

    /* Desc smaller */
    .analysis-card-desc {
        font-size: 0.78rem;
        line-height: 1.25;
    }

    /* Clear clickable hover: background change + subtle lift */
    .analysis-card-link:hover .analysis-card,
    .analysis-card-link:focus .analysis-card {
        background-color: rgba(13, 110, 253, 0.08);
        /* adjust to your theme */
        border-color: rgba(13, 110, 253, 0.35);
        transform: translateY(-2px);
        box-shadow: 0 10px 22px rgba(0, 0, 0, 0.08);
    }

    /* Optional: make icon pop on hover too */
    .analysis-card-link:hover .analysis-card-icon,
    .analysis-card-link:focus .analysis-card-icon {
        transform: scale(1.03);
        transition: transform .18s ease;
    }
</style>

<div class="analysis-shell">
    <div class="analysis-hero mb-4">
        <div class="position-relative">
            <h2>Reports / Analysis Hub</h2>
            <p>One place to explore revenue, inventory health, returns, and cash movements. Filter, compare, and export reports with a single click.</p>
        </div>

        <ul class="nav nav-tabs mb-3" role="tablist">
            <?php foreach ($groups as $index => $group): ?>
                <?php $tabId = 'tab-' . strtolower(str_replace(' ', '-', $group)); ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $index === 0 ? 'active' : '' ?>" id="<?= $tabId ?>-tab" data-bs-toggle="tab" data-bs-target="#<?= $tabId ?>" type="button" role="tab">
                        <?= htmlspecialchars($group) ?>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="tab-content">
            <?php foreach ($groups as $index => $group): ?>
                <?php $tabId = 'tab-' . strtolower(str_replace(' ', '-', $group)); ?>
                <div class="tab-pane fade <?= $index === 0 ? 'show active' : '' ?>" id="<?= $tabId ?>" role="tabpanel">
                    <div class="analysis-grid row g-2"> <!-- smaller gap -->
                        <?php foreach ($cards as $card): ?>
                            <?php if ($card['group'] !== $group) continue; ?>

                            <!-- 6 per row on xl, 5 per row on lg (using custom col-20) -->
                            <div class="col-6 col-md-4 col-lg-2 col-xl-2">
                                <a
                                    class="analysis-card-link"
                                    href="report.php?key=<?= urlencode($card['key']) ?>"
                                    aria-label="<?= htmlspecialchars($card['title']) ?>">
                                    <div class="card analysis-card h-100">
                                        <div class="card-body p-2">
                                            <div class="analysis-card-head d-flex align-items-center gap-2">
                                                <div class="analysis-card-icon">
                                                    <i class="fas <?= htmlspecialchars($card['icon']) ?>"></i>
                                                </div>
                                                <h6 class="analysis-card-title mb-0">
                                                    <?= htmlspecialchars($card['title']) ?>
                                                </h6>
                                            </div>

                                            <p class="analysis-card-desc text-muted mb-0 mt-1">
                                                <?= htmlspecialchars($card['desc']) ?>
                                            </p>
                                        </div>
                                    </div>
                                </a>
                            </div>

                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

    <?php require_once '../../includes/footer.php'; ?>