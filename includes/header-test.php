<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' | Gammavet System' : 'Gammavet System'; ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Icon Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    
    <style>
        body {
            background: #f8f9fa;
            min-height: 100vh;
            font-family: 'Inter', 'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
        }
        
        .top-bar {
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        
        .notif-badge {
            position: relative;
            cursor: pointer;
        }
        
        .notif-badge .badge {
            position: absolute;
            top: -8px;
            right: -8px;
        }
    </style>
</head>
<body>
    <!-- Top Bar -->
    <div class="top-bar">
        <div class="d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <img src="<?= BASE_URL ?>logo.png" alt="GammaVet" width="40" height="40">
                <h4 class="mb-0 fw-bold text-primary">GammaVet System</h4>
            </div>
            
            <div class="user-info">
                <?php if (isLoggedIn()): ?>
                    <?php $notifCount = function_exists('getUnreadNotificationsCount') ? getUnreadNotificationsCount() : 0; ?>
                    <?php if ($notifCount > 0 && hasPermission('notifications.view')): ?>
                        <div class="notif-badge">
                            <a href="<?= BASE_URL ?>modules/notifications/index.php" class="text-decoration-none text-dark">
                                <i class="fas fa-bell fs-5"></i>
                                <span class="badge rounded-pill bg-danger"><?= $notifCount ?></span>
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="dropdown">
                        <button class="btn btn-light dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                            <div class="user-avatar">
                                <?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?>
                            </div>
                            <span><?= $_SESSION['user_name'] ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= BASE_URL ?>modules/users/profile.php"><i class="fas fa-user me-2"></i> Profile</a></li>
                            <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i> Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="<?= BASE_URL ?>logout.php?logout">
                                    <i class="fas fa-sign-out-alt me-2"></i> Logout
                                </a>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Notification toast + poller -->
    <?php if (isLoggedIn() && hasPermission('notifications.view')): ?>
    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
        <div id="notifToast" class="toast align-items-center text-bg-primary border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas fa-bell me-2"></i> New notifications arrived.
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    </div>
    <audio id="notifSound"><source src="<?= BASE_URL ?>assets/notify.mp3" type="audio/mpeg"></audio>
    <script>
    (function(){
        let last = parseInt(localStorage.getItem('notif_last_count')||'0',10);
        function check(){
            fetch('<?= BASE_URL ?>modules/notifications/unread_count.php').then(r=>r.json()).then(d=>{
                const c = parseInt((d&&d.count)||0,10);
                if (c>last){
                    try { document.getElementById('notifSound').play().catch(()=>{}); } catch(e){}
                    const t = new bootstrap.Toast(document.getElementById('notifToast')); t.show();
                }
                last = c; localStorage.setItem('notif_last_count', String(c));
            }).catch(()=>{});
        }
        setInterval(check, 30000);
        document.addEventListener('DOMContentLoaded', check);
    })();
    </script>
    <?php endif; ?>

    <div class="container-fluid mt-4">
        <?php displayAlert(); ?>