<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/report_catalog.php';

if (!hasPermission('analysis.view_reports')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

$catalog = getAnalysisReportCatalog();
$groups = getAnalysisReportGroups();
$page_title = 'Reports & Analytics';
require_once '../../includes/header.php';
?>

<style>
    .report-card {
        border-color: var(--bs-border-color);
        transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
    }
    .report-card:hover,
    .report-card:focus-within {
        border-color: rgba(var(--bs-primary-rgb), .45);
        box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .08);
        transform: translateY(-2px);
    }
    .report-icon {
        width: 2.5rem;
        height: 2.5rem;
        display: inline-grid;
        place-items: center;
        color: var(--bs-primary);
        background: rgba(var(--bs-primary-rgb), .1);
        border-radius: .5rem;
        flex: 0 0 auto;
    }
</style>

<main class="container mt-4 mb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1">Reports & Analytics</h2>
        </div>
        <?php if (hasPermission('analysis.view_finance_reports')): ?>
            <a class="btn btn-outline-success" href="financial_workbook.php">
                <i class="fas fa-file-excel me-2"></i>Financial workbook
            </a>
        <?php endif; ?>
    </div>

    <?php foreach ($groups as $groupName => $group): ?>
        <?php if (!hasPermission($group['permission'])) continue; ?>
        <?php
        $groupReports = array_filter(
            $catalog,
            static fn(array $report): bool => $report['group'] === $groupName
        );
        $headingId = strtolower(str_replace([' ', '&'], ['-', 'and'], $groupName)) . '-heading';
        ?>
        <section class="mb-5" aria-labelledby="<?= htmlspecialchars($headingId) ?>">
            <div class="d-flex align-items-start gap-3 mb-3">
                <span class="report-icon"><i class="fas <?= htmlspecialchars($group['icon']) ?>"></i></span>
                <div>
                    <h4 class="mb-1" id="<?= htmlspecialchars($headingId) ?>"><?= htmlspecialchars($groupName) ?></h4>
                </div>
            </div>

            <div class="row g-3">
                <?php foreach ($groupReports as $key => $report): ?>
                    <div class="col-12 col-md-6 col-xl-4">
                        <article class="card report-card h-100">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex align-items-start gap-3 mb-3">
                                    <span class="report-icon"><i class="fas <?= htmlspecialchars($report['icon']) ?>"></i></span>
                                    <div>
                                        <h5 class="card-title mb-1"><?= htmlspecialchars($report['title']) ?></h5>
                                        <span class="badge text-bg-light border fw-normal"><?= htmlspecialchars($report['scope']) ?></span>
                                    </div>
                                </div>
                                <p class="card-text text-muted small mb-2"><?= htmlspecialchars($report['description']) ?></p>
                                <a class="btn btn-outline-primary btn-sm mt-auto align-self-start" href="report.php?key=<?= urlencode($key) ?>">
                                    Open report <i class="fas fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</main>

<?php require_once '../../includes/footer.php'; ?>
