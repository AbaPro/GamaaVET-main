<?php
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!hasPermission('tickets.manage') && !hasPermission('tickets.create') && !hasPermission('tickets.view')) {
    setAlert('danger', 'Access denied.');
    redirect('../../dashboard.php');
}

global $conn;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) redirect('index.php');

$ticket = $conn->query("SELECT t.*, r.name AS assigned_role, u.name AS assigned_user_name 
                        FROM tickets t 
                        LEFT JOIN roles r ON r.id = t.assigned_to_role_id 
                        LEFT JOIN users u ON u.id = t.assigned_to_user_id 
                        WHERE t.id = " . $id)->fetch_assoc();
if (!$ticket) redirect('index.php');

$roles = $conn->query("SELECT id, name, slug FROM roles WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$canManageTickets = hasPermission('tickets.manage');
$canUpdateTicketStatus = $canManageTickets || hasPermission('tickets.update_status');

$userId = $_SESSION['user_id'] ?? null;
if (!isset($_SESSION['role_id']) && $userId) {
    loadUserAccessToSession($userId);
}
$roleId = $_SESSION['role_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add Note logic
    if (isset($_POST['add_note']) && (hasPermission('tickets.manage') || hasPermission('tickets.create'))) {
        $note = trim($_POST['note'] ?? '');
        if ($note !== '') {
            $stmt = $conn->prepare("INSERT INTO ticket_notes (ticket_id, user_id, note) VALUES (?, ?, ?)");
            $stmt->bind_param('iis', $id, $userId, $note);
            $stmt->execute();
            $stmt->close();
            setAlert('success', 'Note added.');
        } else {
            setAlert('warning', 'Note cannot be empty.');
        }
        redirect('view.php?id=' . $id);
    }

    // Update status logic
    if (isset($_POST['update_ticket']) && $canUpdateTicketStatus) {
        $status = $_POST['status'] ?? 'open';
        $stmt = $conn->prepare("UPDATE tickets SET status=? WHERE id=?");
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();
        $stmt->close();
        setAlert('success', 'Ticket status updated.');
        redirect('view.php?id=' . $id);
    }
}

// Access control check
if (!hasPermission('tickets.manage')) {
    $allowed = ((int)($ticket['assigned_to_role_id'] ?? 0) === (int)$roleId)
        || ((int)($ticket['assigned_to_user_id'] ?? 0) === (int)$userId)
        || ((int)($ticket['created_by'] ?? 0) === (int)$userId);
    if (!$allowed) {
        setAlert('danger', 'Access denied.');
        redirect('index.php');
    }
}

// Fetch attachments
$attachmentStmt = $conn->prepare("SELECT id, file_path, original_name, created_at FROM ticket_attachments WHERE ticket_id = ? ORDER BY created_at ASC");
$attachmentStmt->bind_param('i', $id);
$attachmentStmt->execute();
$attachments = $attachmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$attachmentStmt->close();

// Fetch notes
$notesStmt = $conn->prepare("SELECT tn.*, u.name AS user_name FROM ticket_notes tn LEFT JOIN users u ON u.id = tn.user_id WHERE tn.ticket_id = ? ORDER BY tn.created_at DESC");
$notesStmt->bind_param('i', $id);
$notesStmt->execute();
$notes = $notesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notesStmt->close();

$page_title = 'Ticket #' . $id;
require_once '../../includes/header.php';
?>

<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Ticket #<?= (int)$id ?></h2>
    <a href="index.php" class="btn btn-secondary">Back</a>
  </div>

  <div class="card">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-8">
          <h5 class="mb-2"><?= htmlspecialchars($ticket['title']) ?></h5>
          <div class="text-muted small mb-3">Created: <?= formatDateTime($ticket['created_at']) ?></div>
          <p class="mb-0"><?= nl2br(htmlspecialchars($ticket['description'])) ?></p>
          <?php if (!empty($attachments)): ?>
            <div class="mt-4">
              <h6>Attachments</h6>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($attachments as $attachment): ?>
                  <a class="btn btn-outline-secondary btn-sm" href="../../<?= htmlspecialchars($attachment['file_path']) ?>" target="_blank" rel="noopener">
                    <i class="fas fa-paperclip me-1"></i><?= htmlspecialchars($attachment['original_name'] ?: 'Attachment') ?>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <div class="col-md-4">
          <?php if ($canUpdateTicketStatus): ?>
            <form method="post" class="vstack gap-2">
              <input type="hidden" name="update_ticket" value="1">
              <div>
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                  <?php foreach (['open','in_progress','resolved','closed'] as $st): ?>
                    <option value="<?= $st ?>" <?= $ticket['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="small text-muted">
                <div><strong>Priority:</strong> <?= htmlspecialchars($ticket['priority']) ?></div>
                <div><strong>Assigned Role:</strong> <?= htmlspecialchars($ticket['assigned_role'] ?? 'None') ?></div>
                <div><strong>Assigned User:</strong> <?= htmlspecialchars($ticket['assigned_user_name'] ?? 'None') ?></div>
              </div>
              <div class="pt-2">
                <button class="btn btn-primary" type="submit">Save</button>
              </div>
            </form>
          <?php else: ?>
            <div class="small text-muted">
              <div><strong>Status:</strong> <?= htmlspecialchars($ticket['status']) ?></div>
              <div><strong>Priority:</strong> <?= htmlspecialchars($ticket['priority']) ?></div>
              <div><strong>Assigned Role:</strong> <?= htmlspecialchars($ticket['assigned_role'] ?? 'None') ?></div>
              <div><strong>Assigned User:</strong> <?= htmlspecialchars($ticket['assigned_user_name'] ?? 'None') ?></div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="card mt-3">
    <div class="card-body">
      <h5 class="mb-3">Ticket Notes</h5>
      <div class="accordion" id="ticketNotesAccordion">
        <?php if (empty($notes)): ?>
          <div class="text-muted">No notes yet.</div>
        <?php else: ?>
          <?php foreach ($notes as $i => $note): ?>
            <?php $headingId = 'noteHeading' . $i; $collapseId = 'noteCollapse' . $i; ?>
            <div class="accordion-item">
              <h2 class="accordion-header" id="<?= $headingId ?>">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>" aria-expanded="false" aria-controls="<?= $collapseId ?>">
                  <?= htmlspecialchars($note['user_name'] ?? 'System') ?> - <?= formatDateTime($note['created_at']) ?>
                </button>
              </h2>
              <div id="<?= $collapseId ?>" class="accordion-collapse collapse" aria-labelledby="<?= $headingId ?>" data-bs-parent="#ticketNotesAccordion">
                <div class="accordion-body">
                  <?= nl2br(htmlspecialchars($note['note'])) ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if (hasPermission('tickets.manage') || hasPermission('tickets.create')): ?>
        <form method="post" class="mt-3">
          <div class="mb-2">
            <label for="note" class="form-label">Add Note</label>
            <textarea id="note" name="note" class="form-control" rows="3" required></textarea>
          </div>
          <button type="submit" class="btn btn-outline-primary" name="add_note" value="1">Send</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php require_once '../../includes/footer.php'; ?>
