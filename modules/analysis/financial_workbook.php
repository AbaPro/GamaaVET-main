<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';

if (!hasPermission('analysis.view_reports') || !hasPermission('analysis.view_finance_reports')) {
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

                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-download me-2"></i>Export Financial Workbook (.xlsx)
                        </button>
                        <a href="../analysis/" class="btn btn-secondary btn-lg ms-2">
                            <i class="fas fa-arrow-left me-2"></i>Back to Analysis
                        </a>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
