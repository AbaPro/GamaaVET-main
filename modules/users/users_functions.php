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

    $stmt = $conn->prepare("INSERT INTO users (username, password, name, email, role, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssii",
        $data['username'],
        $hashed_password,
        $data['name'],
        $data['email'],
        $role_slug,
        $role_id,
        $is_active
    );

    return $stmt->execute();
    
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

    // Check if password is being updated
    if (!empty($data['password'])) {
        if ($data['password'] !== $data['confirm_password']) {
            return false;
        }
        $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE users SET username = ?, password = ?, name = ?, email = ?, role = ?, role_id = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param("sssssiii", 
            $data['username'],
            $hashed_password,
            $data['name'],
            $data['email'],
            $role_slug,
            $role_id,
            $is_active,
            $id
        );
    } else {
        $stmt = $conn->prepare("UPDATE users SET username = ?, name = ?, email = ?, role = ?, role_id = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param("ssssiii", 
            $data['username'],
            $data['name'],
            $data['email'],
            $role_slug,
            $role_id,
            $is_active,
            $id
        );
    }
    
    return $stmt->execute();
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
    return $stmt->execute();
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
