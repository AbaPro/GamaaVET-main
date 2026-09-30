<?php

function saveExpensePaymentAttachments(PDO $pdo, int $paymentId, array $images, int $userId): void {
    if (!$images) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO expense_payment_attachments (expense_payment_id, file_path, original_name, created_by) VALUES (?, ?, ?, ?)');
    foreach ($images as $image) {
        $stmt->execute([$paymentId, $image['path'], $image['original_name'], $userId]);
    }
}

function removeUploadedExpensePaymentImages(array $images): void {
    foreach ($images as $image) {
        $fullPath = ROOT_PATH . '/' . $image['path'];
        if (is_file($fullPath)) {
            unlink($fullPath);
        }
    }
}
