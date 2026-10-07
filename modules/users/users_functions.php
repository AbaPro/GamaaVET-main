<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getAllUsers() {
    global $conn;
    $sql = "SELECT u.*, COALESCE(r.slug, u.role) AS role_slug, r.name AS role_name, r.id AS role_id
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            ORDER BY u.name ASC";
    $result = $conn->query($sql);
    return $result->fetch_all(MYSQLI_ASSOC);
}

function getUserById($id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function userSafeAccessFromForm($data) {
    global $conn;
    if (!isset($data['safe_access_mode'])) return null;
    $token = $data['safe_access_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['user_safe_access_token'] ?? '', $token) || $token === '') {
        throw new DomainException('Invalid safe access request. Refresh the page.');
    }
    if ($data['safe_access_mode'] === 'all') return null;
    if ($data['safe_access_mode'] !== 'selected' || (isset($data['safe_ids']) && !is_array($data['safe_ids']))) {
        throw new DomainException('Invalid safe selection.');
    }
    $ids = [];
    foreach ($data['safe_ids'] ?? [] as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new DomainException('Invalid safe selection.');
        $ids[$id] = $id;
    }
    if ($ids) {
        $result = $conn->query('SELECT id FROM safes WHERE id IN (' . implode(',', $ids) . ')');
        if ($result->num_rows !== count($ids)) throw new DomainException('A selected safe no longer exists.');
    }
    return json_encode(array_values($ids));
}

function renderUserSafeAccessForm($user = []) {
    global $conn;
    if (empty($_SESSION['user_safe_access_token'])) $_SESSION['user_safe_access_token'] = bin2hex(random_bytes(32));
    $restricted = isset($user['safe_access_ids']);
    $selected = $restricted ? (json_decode($user['safe_access_ids'], true) ?: []) : [];
    $safes = $conn->query("SELECT s.id, s.name, s.currency, COALESCE(a.name, 'GammaVet') AS brand FROM safes s LEFT JOIN accounts a ON a.id = s.account_id ORDER BY brand, s.name")->fetch_all(MYSQLI_ASSOC);
    ?>
    <div class="mt-3 user-safe-access">
        <input type="hidden" name="safe_access_token" value="<?= e($_SESSION['user_safe_access_token']) ?>">
        <label class="form-label" for="safe_access_mode">Safe Access</label>
        <select class="form-select" id="safe_access_mode" name="safe_access_mode" onchange="this.closest('.user-safe-access').querySelector('.safe-selection').hidden = this.value === 'all'">
            <option value="all" <?= !$restricted ? 'selected' : '' ?>>All safes in permitted brands</option>
            <option value="selected" <?= $restricted ? 'selected' : '' ?>>Only selected safes</option>
        </select>
        <div class="safe-selection mt-2" <?= !$restricted ? 'hidden' : '' ?>>
            <label class="form-label" for="safe_ids">Allowed Safes</label>
            <select class="form-select" id="safe_ids" name="safe_ids[]" multiple size="6">
                <?php foreach ($safes as $safe): ?>
                    <option value="<?= (int)$safe['id'] ?>" <?= in_array((int)$safe['id'], $selected, true) ? 'selected' : '' ?>><?= e($safe['brand'] . ' — ' . $safe['name'] . ' (' . $safe['currency'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">Select one safe to limit this user to it. Select none to deny access to all safes.</small>
        </div>
        <small class="text-muted">Role permissions still control which actions the user can perform. Brand permissions still apply.</small>
    </div>
    <?php
}

function createUser($data) {
    global $conn;
    
    // Validate passwords match
    if ($data['password'] !== $data['confirm_password']) {
        return false;
    }
    
    $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
    $is_active = isset($data['is_active']) ? 1 : 0;

    $role_id = isset($data['role_id']) ? (int)$data['role_id'] : 0;
    $role_slug = null;
    if ($role_id > 0) {
        $rs = $conn->prepare("SELECT slug FROM roles WHERE id = ?");
        $rs->bind_param("i", $role_id);
        $rs->execute();
        $rres = $rs->get_result();
        if ($row = $rres->fetch_assoc()) { $role_slug = $row['slug']; }
        $rs->close();
    }
    if ($role_slug === null && !empty($data['role'])) {
        $role_slug = $data['role'];
    }
    if ($role_slug === null) { $role_slug = 'salesman'; }

    $region = !empty($data['region']) ? $data['region'] : NULL;
    try { $safeAccess = userSafeAccessFromForm($data); } catch (DomainException $e) { return false; }

    $stmt = $conn->prepare("INSERT INTO users (username, password, name, email, role, role_id, is_active, region, safe_access_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssiiss",
        $data['username'],
        $hashed_password,
        $data['name'],
        $data['email'],
        $role_slug,
        $role_id,
        $is_active,
        $region,
        $safeAccess
    );

    if (!$stmt->execute()) {
        return false;
    }
    logActivity("Created user: {$data['username']}", ['name' => $data['name'], 'role' => $role_slug, 'active' => (bool)$is_active], 'create', 'user', $stmt->insert_id);
    return true;
}

function updateUser($id, $data) {
    global $conn;
    
    $is_active = isset($data['is_active']) ? 1 : 0;
    
    // Resolve role
    $role_id = isset($data['role_id']) ? (int)$data['role_id'] : 0;
    $role_slug = null;
    if ($role_id > 0) {
        $rs = $conn->prepare("SELECT slug FROM roles WHERE id = ?");
        $rs->bind_param("i", $role_id);
        $rs->execute();
        $rres = $rs->get_result();
        if ($row = $rres->fetch_assoc()) { $role_slug = $row['slug']; }
        $rs->close();
    }
    if ($role_slug === null && !empty($data['role'])) {
        $role_slug = $data['role'];
    }

    $region = !empty($data['region']) ? $data['region'] : NULL;
    try { $safeAccess = userSafeAccessFromForm($data); } catch (DomainException $e) { return false; }

    if (!isset($data['safe_access_mode'])) $safeAccess = getUserById($id)['safe_access_ids'] ?? null;

    // Check if password is being updated
    if (!empty($data['password'])) {
        if ($data['password'] !== $data['confirm_password']) {
            return false;
        }
        $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE users SET username = ?, password = ?, name = ?, email = ?, role = ?, role_id = ?, is_active = ?, region = ?, safe_access_ids = ? WHERE id = ?");
        $stmt->bind_param("sssssiissi",
            $data['username'],
            $hashed_password,
            $data['name'],
            $data['email'],
            $role_slug,
            $role_id,
            $is_active,
            $region,
            $safeAccess,
            $id
        );
    } else {
        $stmt = $conn->prepare("UPDATE users SET username = ?, name = ?, email = ?, role = ?, role_id = ?, is_active = ?, region = ?, safe_access_ids = ? WHERE id = ?");
        $stmt->bind_param("ssssiissi",
            $data['username'],
            $data['name'],
            $data['email'],
            $role_slug,
            $role_id,
            $is_active,
            $region,
            $safeAccess,
            $id
        );
    }
    
    if (!$stmt->execute()) {
        return false;
    }
    $changes = ['username' => $data['username'], 'role' => $role_slug, 'active' => (bool)$is_active, 'safe_access_ids' => $safeAccess];
    if (!empty($data['password'])) {
        $changes['password'] = 'changed';
    }
    logActivity("Updated user ID: $id", $changes, 'update', 'user', $id);
    return true;
}

function deleteUser($id) {
    global $conn;
    
    // Prevent deleting the last admin
    $checkAdmin = $conn->query("SELECT COUNT(*) as admin_count FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE COALESCE(r.slug, u.role) = 'admin'");
    $adminCount = $checkAdmin->fetch_assoc()['admin_count'];
    
    $user = getUserById($id);
    $effectiveRole = $user['role'] ?? null;
    if (!$effectiveRole && isset($user['role_id'])) {
        $rs = $conn->prepare("SELECT slug FROM roles WHERE id = ?");
        $rs->bind_param("i", $user['role_id']);
        $rs->execute();
        $roleRow = $rs->get_result()->fetch_assoc();
        $rs->close();
        $effectiveRole = $roleRow['slug'] ?? null;
    }
    if ($effectiveRole === 'admin' && $adminCount <= 1) {
        return false;
    }
    
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        return false;
    }
    logActivity("Deleted user ID: $id", ['username' => $user['username'] ?? null, 'name' => $user['name'] ?? null], 'delete', 'user', $id);
    return true;
}

function getRoleColor($role)
{
    switch ($role) {
        case 'admin':
            return 'danger';

        case 'accountant':
            return 'info';

        case 'salesman':
            return 'success';

        case 'sales_manager':
            return 'primary';

        case 'inventory_manager':
            return 'warning';

        case 'inventory_supervisor':
            return 'secondary';

        case 'purchasing_supervisor':
            return 'dark';

        case 'operations_manager':
            return 'primary';

        case 'production_manager':
            return 'success';

        case 'production_supervisor':
            return 'warning';

        default:
            return 'secondary';
    }
}

function getAllRoles($include_inactive = false) {
    global $conn;
    $sql = "SELECT id, name, slug, is_active FROM roles" . ($include_inactive ? "" : " WHERE is_active = 1") . " ORDER BY name";
    $res = $conn->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

?>
