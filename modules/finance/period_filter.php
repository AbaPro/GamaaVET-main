<?php
// Reporting period picker shared by the finance dashboard and its drill-down pages.
// Expects $periods, $period, $dateFrom, $dateTo and $periodLabel from dashboard_reporting.php.
?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body py-3">
    <form method="get" class="row g-2 align-items-end">
        <?php if (!empty($metric)): ?><input type="hidden" name="metric" value="<?= e($metric); ?>"><?php endif; ?>
        <div class="col-md-3">
            <label for="fdPeriod" class="form-label mb-1">Period</label>
            <select class="form-select" id="fdPeriod" name="period">
                <?php foreach ($periods as $key => $label): ?>
                    <option value="<?= e($key); ?>" <?= $period === $key ? 'selected' : ''; ?>><?= e($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 fd-custom-range <?= $period === 'custom' ? '' : 'd-none'; ?>">
            <label for="fdFrom" class="form-label mb-1">From</label>
            <input type="date" class="form-control" id="fdFrom" name="from" value="<?= e($period === 'custom' ? ($dateFrom ?? '') : ''); ?>">
        </div>
        <div class="col-md-2 fd-custom-range <?= $period === 'custom' ? '' : 'd-none'; ?>">
            <label for="fdTo" class="form-label mb-1">To</label>
            <input type="date" class="form-control" id="fdTo" name="to" value="<?= e($period === 'custom' ? ($dateTo ?? '') : ''); ?>">
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Apply</button></div>
        <div class="col text-end text-muted small align-self-center"><i class="far fa-calendar me-1"></i><?= e($periodLabel); ?></div>
    </form>
</div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const period = document.getElementById('fdPeriod');
    if (period) {
        period.addEventListener('change', function () {
            document.querySelectorAll('.fd-custom-range').forEach(function (el) {
                el.classList.toggle('d-none', period.value !== 'custom');
            });
        });
    }
});
</script>
