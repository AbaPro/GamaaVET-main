<?php
// Superseded by receive_transfer.php when transfers gained a real
// sender -> receiver -> verifier workflow. Kept only so old bookmarks and
// links keep working; all logic now lives in receive_transfer.php.
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$transfer_id = (int)($_GET['id'] ?? 0);

if ($transfer_id > 0) {
    redirect('receive_transfer.php?id=' . $transfer_id);
}

redirect('transfers_list.php');
exit;
