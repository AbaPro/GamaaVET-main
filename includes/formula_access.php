<?php
require_once __DIR__ . '/functions.php';

function formulaPasswordStorageAvailable() {
    global $pdo;
    static $available = null;
    if ($available === null) {
        $stmt = $pdo->query("SHOW TABLES LIKE 'manufacturing_formula_password'");
        $available = (bool)$stmt->fetchColumn();
    }
    return $available;
}

function getFormulaPasswordHash() {
    global $pdo;
    static $loaded = false;
    static $hash = null;
    if (!$loaded) {
        if (formulaPasswordStorageAvailable()) {
            $hash = $pdo->query('SELECT password_hash FROM manufacturing_formula_password WHERE id = 1')->fetchColumn() ?: null;
        }
        $loaded = true;
    }
    return $hash;
}

function formulaPasswordVersion() {
    return hash('sha256', getFormulaPasswordHash() ?? 'legacy-formula-password');
}

function unlockFormulas($password) {
    if (!hasPermission('manufacturing.formula.view_all') || !is_string($password)) {
        return false;
    }
    $hash = getFormulaPasswordHash();
    $valid = $hash !== null ? password_verify($password, $hash) : hash_equals('123456', $password);
    if ($valid) {
        $_SESSION['formula_unlocked'] = true;
        $_SESSION['formula_password_version'] = formulaPasswordVersion();
    }
    return $valid;
}

function areFormulasUnlocked() {
    $version = $_SESSION['formula_password_version'] ?? '';
    if (empty($_SESSION['formula_unlocked']) || !is_string($version)
        || !hash_equals(formulaPasswordVersion(), $version)) {
        unset($_SESSION['formula_unlocked'], $_SESSION['formula_password_version']);
        return false;
    }
    return true;
}

// Use the same policy for HTML, JSON, media and generated handoff documents.
function canViewManufacturingFormula() {
    return hasPermission('manufacturing.formula.view_all') && areFormulasUnlocked();
}

function canViewManufacturingTechnicalDetails() {
    return hasPermission('manufacturing.orders.technical_details.view');
}

function canViewManufacturingAudit() {
    return hasPermission('manufacturing.audit.view') && canViewManufacturingTechnicalDetails()
        && canViewManufacturingFormula();
}

function canAccessManufacturingStep($step) {
    if (!hasPermission('manufacturing.view_step_' . $step)) return false;
    if (in_array($step, ['sourcing', 'receipt', 'preparation'], true)) {
        return canViewManufacturingTechnicalDetails() && canViewManufacturingFormula();
    }
    return !in_array($step, ['quality', 'packaging'], true) || canViewManufacturingTechnicalDetails();
}

function manufacturingCsrfToken() {
    if (empty($_SESSION['manufacturing_csrf'])) $_SESSION['manufacturing_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['manufacturing_csrf'];
}

function manufacturingValidateCsrf() {
    $token = $_POST['manufacturing_csrf'] ?? '';
    if (!is_string($token) || !hash_equals(manufacturingCsrfToken(), $token)) {
        http_response_code(403);
        exit('Invalid request token. Refresh the page and try again.');
    }
}

function manufacturingFormulaSampleUrl($formulaId, $path) {
    return rtrim(BASE_URL, '/') . '/modules/manufacturing/formula_sample.php?id=' . (int)$formulaId
        . '&image=' . hash('sha256', (string)$path);
}
