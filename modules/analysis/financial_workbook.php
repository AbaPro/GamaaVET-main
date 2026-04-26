<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';

if (!hasPermission('analysis.view_reports')) {
    setAlert('danger', 'You do not have permission to access this page.');
    redirect('../../dashboard.php');
}

$page_title = 'Financial Workbook Export';
require_once '../../includes/header.php';
require_once '../../includes/messages.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-file-excel me-2"></i>Financial Workbook Export</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">
                        Export a multi-sheet XLSX workbook containing financial data from all supported modules.
                        Unsupported modules (General Ledger, Fixed Assets, Payroll, Budgeting, Cost Centers, Consolidation)
                        are listed on the INDEX sheet but not included as separate tabs.
                    </p>

                    <form action="financial_export.php" method="GET">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="date_from" class="form-label">Date From (optional)</label>
                                <input type="date" class="form-control" id="date_from" name="date_from">
                            </div>
                            <div class="col-md-6">
                                <label for="date_to" class="form-label">Date To (optional)</label>
                                <input type="date" class="form-control" id="date_to" name="date_to">
                            </div>
                        </div>

                        <div class="alert alert-info">
                            <strong><i class="fas fa-info-circle me-1"></i> Sheets included:</strong>
                            <ul class="mb-0 mt-2">
                                <li>INDEX - Table of contents with excluded modules noted</li>
                                <li>Accounts Receivable - Customer orders & balances</li>
                                <li>Accounts Payable - Vendor POs & balances</li>
                                <li>Cash & Bank - Combined transaction register</li>
                                <li>Inventory - Stock levels & valuation</li>
                                <li>Purchasing - PO register with line items</li>
                                <li>Sales & Billing - Order register with line items</li>
                            </ul>
                        </div>

                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-download me-2"></i>Export Financial Workbook (.xlsx)
                        </button>
                        <a href="../analysis/" class="btn btn-secondary btn-lg ms-2">
                            <i class="fas fa-arrow-left me-2"></i>Back to Analysis
                        </a>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm mt-4">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Notes</h6>
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li>This is a <strong>management-report workbook</strong>, not a statutory accounting workbook.</li>
                        <li>AR balances = <code>orders.total_amount - orders.paid_amount</code></li>
                        <li>AP balances = <code>purchase_orders.total_amount - purchase_orders.paid_amount</code></li>
                        <li>Inventory value = <code>quantity x cost_price</code> per product</li>
                        <li>Cash & Bank merges order payments, PO payments, expense payments, and finance transfers.</li>
                        <li>No due dates, aging buckets, tax columns, or chart-of-accounts structure (not modeled yet).</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
