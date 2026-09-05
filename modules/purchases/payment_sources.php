<?php

function poPaymentSourceConfig($type) {
    return [
        'safe' => ['table' => 'safes', 'name' => 'name', 'label' => 'Safe'],
        'bank' => ['table' => 'bank_accounts', 'name' => 'bank_name', 'label' => 'Bank account'],
        'personal' => ['table' => 'personal_accounts', 'name' => 'name', 'label' => 'Personal account'],
    ][$type] ?? null;
}

function poPaymentSources() {
    global $pdo;
    $sources = [];
    $scope = getAccountScopeSql();
    foreach (['safe', 'bank', 'personal'] as $type) {
        $config = poPaymentSourceConfig($type);
        $active = $type === 'personal' ? ' AND is_active = 1' : '';
        $nameSql = $type === 'bank' ? "CONCAT(bank_name, ' — ', account_number)" : "`{$config['name']}`";
        $rows = $pdo->query("SELECT id, $nameSql AS name, balance FROM `{$config['table']}` WHERE $scope $active ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $sources[$type . ':' . $row['id']] = $row + ['type' => $type, 'label' => $config['label']];
        }
    }
    return $sources;
}

// Call inside the payment transaction so both the payment and balance change commit together.
function debitPoPaymentSource($type, $id, $amount) {
    global $pdo;
    $config = poPaymentSourceConfig($type);
    if (!$config || $id <= 0) throw new DomainException('Select a valid payment source.');
    $scope = getAccountScopeSql();
    $active = $type === 'personal' ? ' AND is_active = 1' : '';
    $stmt = $pdo->prepare("UPDATE `{$config['table']}` SET balance = balance - ? WHERE id = ? AND $scope $active AND balance >= ?");
    $stmt->execute([$amount, $id, $amount]);
    if ($stmt->rowCount() !== 1) throw new DomainException('The selected payment source is unavailable or has insufficient balance.');
}

function refundPoPaymentSource(array $payment) {
    global $pdo;
    if (empty($payment['payment_source_type'])) return; // Historical payments were not debited here.
    $config = poPaymentSourceConfig($payment['payment_source_type']);
    if (!$config || empty($payment['payment_source_id'])) throw new DomainException('Invalid payment source.');
    $stmt = $pdo->prepare("UPDATE `{$config['table']}` SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$payment['amount'], $payment['payment_source_id']]);
    if ($stmt->rowCount() !== 1) throw new DomainException('Payment source no longer exists; cannot refund this payment.');
}

function poPaymentSourceHistory($type, $id) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT p.*, v.name AS vendor_name, u.name AS created_by_name FROM purchase_order_payments p JOIN purchase_orders po ON po.id = p.purchase_order_id JOIN vendors v ON v.id = po.vendor_id LEFT JOIN users u ON u.id = p.created_by WHERE p.payment_source_type = ? AND p.payment_source_id = ? ORDER BY p.created_at DESC, p.id DESC');
    $stmt->execute([$type, $id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
