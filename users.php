<?php
$page_title = 'Users';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('users');

function ensureUsersPrivilegesColumn($conn) {
    $column_check = $conn->query("SHOW COLUMNS FROM users LIKE 'privileges'");
    if ($column_check && $column_check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN privileges TEXT NULL AFTER role");
    }
}

function privilegesToArray($value) {
    if (!$value) return [];
    $items = array_filter(array_map('trim', explode(',', $value)));
    return array_values(array_unique($items));
}

function privilegesToString($items) {
    $clean = [];
    foreach ($items as $item) {
        $item = sanitizeString($item, 80);
        if ($item !== '') {
            $clean[] = $item;
        }
    }
    return implode(',', array_values(array_unique($clean)));
}

ensureUsersPrivilegesColumn($conn);

$available_roles = ['admin', 'manager', 'cashier'];
$available_statuses = ['active', 'inactive'];
$available_privileges = [
    'dashboard' => 'Dashboard',
    'pos' => 'Point of Sale',
    'inventory' => 'Inventory',
    'customers' => 'Customers',
    'suppliers' => 'Suppliers',
    'expenses' => 'Expenses',
    'debts' => 'Debts',
    'purchase_orders' => 'Purchase Orders',
    'payroll' => 'Payroll',
    'tasks' => 'Tasks',
    'reports' => 'Reports',
    'receipts' => 'Receipts',
    'employees' => 'Employees',
    'activity' => 'Activity Log',
    'settings' => 'Settings',
    'users' => 'Users'
];

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = sanitizeString($_POST['name'] ?? '', 150);
        $username = sanitizeString($_POST['username'] ?? '', 50);
        $password = (string)($_POST['password'] ?? '');
        $email = validateEmail(trim($_POST['email'] ?? ''));
        $role = sanitizeString($_POST['role'] ?? 'cashier', 20);
        $phone = sanitizeString($_POST['phone'] ?? '', 30);
        $status = sanitizeString($_POST['status'] ?? 'active', 20);
        $privileges = $_POST['privileges'] ?? [];

        if (!in_array($role, $available_roles, true)) $role = 'cashier';
        if (!in_array($status, $available_statuses, true)) $status = 'active';
        if ($name === '' || $username === '' || $password === '') {
            $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Name, username, and password are required.</div>';
        } elseif (strlen($password) < 8) {
            $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Password must be at least 8 characters.</div>';
        } else {
            $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check->bind_param('s', $username);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            if ($existing) {
                $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Username already exists.</div>';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $privilege_string = privilegesToString(is_array($privileges) ? $privileges : []);
                $stmt = $conn->prepare("INSERT INTO users (name, username, password, email, role, phone, status, privileges) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param('ssssssss', $name, $username, $hash, $email, $role, $phone, $status, $privilege_string);
                $stmt->execute();
                logActivity($conn, "User created: $name ($username)", 'system');
                $escaped_pwd = htmlspecialchars($password);
                $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">User added! Password: <code style="background:rgba(0,0,0,0.2);padding:4px 8px;border-radius:4px;font-family:monospace;font-weight:bold">'.$escaped_pwd.'</code></div>';
            }
        }
    } elseif ($action === 'edit') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        $name = sanitizeString($_POST['name'] ?? '', 150);
        $username = sanitizeString($_POST['username'] ?? '', 50);
        $password = (string)($_POST['password'] ?? '');
        $email = validateEmail(trim($_POST['email'] ?? ''));
        $role = sanitizeString($_POST['role'] ?? 'cashier', 20);
        $phone = sanitizeString($_POST['phone'] ?? '', 30);
        $status = sanitizeString($_POST['status'] ?? 'active', 20);
        $privileges = $_POST['privileges'] ?? [];

        if (!in_array($role, $available_roles, true)) $role = 'cashier';
        if (!in_array($status, $available_statuses, true)) $status = 'active';

        $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ?");
        $check->bind_param('si', $username, $id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        if ($existing) {
            $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Username already exists.</div>';
        } elseif ($password !== '' && strlen($password) < 8) {
            $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Password must be at least 8 characters.</div>';
        } else {
            $privilege_string = privilegesToString(is_array($privileges) ? $privileges : []);
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET name=?, username=?, password=?, email=?, role=?, phone=?, status=?, privileges=? WHERE id=?");
                $stmt->bind_param('ssssssssi', $name, $username, $hash, $email, $role, $phone, $status, $privilege_string, $id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET name=?, username=?, email=?, role=?, phone=?, status=?, privileges=? WHERE id=?");
                $stmt->bind_param('sssssssi', $name, $username, $email, $role, $phone, $status, $privilege_string, $id);
            }
            $stmt->execute();
            logActivity($conn, "User updated: $name ($username)", 'system');
            if ($password !== '') {
                $escaped_pwd = htmlspecialchars($password);
                $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">User updated! New password: <code style="background:rgba(0,0,0,0.2);padding:4px 8px;border-radius:4px;font-family:monospace;font-weight:bold">'.$escaped_pwd.'</code></div>';
            } else {
                $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">User updated!</div>';
            }
        }
    } elseif ($action === 'delete') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">You cannot delete your own account.</div>';
        } else {
            $stmt = $conn->prepare("SELECT name, username FROM users WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $user_row = $stmt->get_result()->fetch_assoc();
            if ($user_row) {
                try {
                    $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                    $delete_stmt->bind_param('i', $id);
                    $delete_stmt->execute();
                    logActivity($conn, 'User deleted: ' . $user_row['name'] . ' (' . $user_row['username'] . ')', 'system');
                    $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">User deleted!</div>';
                } catch (mysqli_sql_exception $e) {
                    $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Cannot delete this user — they have sales or activity records linked to them.</div>';
                }
            }
        }
    }
}

$users = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
$user_delete_options = [];
$delete_options_result = $conn->query("SELECT id, name, username FROM users ORDER BY name");
if ($delete_options_result) {
    while ($delete_option = $delete_options_result->fetch_assoc()) {
        $user_delete_options[] = $delete_option;
    }
}
$stats = $conn->query("SELECT COUNT(*) as total, SUM(status='active') as active, SUM(role='admin') as admins, SUM(role='manager') as managers, SUM(role='cashier') as cashiers FROM users")->fetch_assoc();
?>
<div class="card" style="margin-bottom:16px">
  <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
    <div>
      <div class="card-title" style="font-size:16px;margin-bottom:4px">Users</div>
      <div class="stat-sub">Manage system accounts and privileges.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-primary" onclick="openModal('add-user-modal')">+ Add User</button>
      <button class="btn btn-outline" onclick="openModal('delete-user-modal')">Delete User</button>
    </div>
  </div>
</div>

<div class="stats-grid-3">
  <div class="stat-card purple"><div class="stat-label">Total Users</div><div class="stat-value"><?= (int)$stats['total'] ?></div></div>
  <div class="stat-card green"><div class="stat-label">Active</div><div class="stat-value"><?= (int)$stats['active'] ?></div></div>
  <div class="stat-card amber"><div class="stat-label">Admins</div><div class="stat-value"><?= (int)$stats['admins'] ?></div></div>
</div>

<?=$msg?>

<div class="card">
  <div class="card-header"><span class="card-title">All Users</span></div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php $i = 1; while ($u = $users->fetch_assoc()): ?>
        <?php $privs = privilegesToArray($u['privileges'] ?? ''); ?>
        <tr>
          <td class="text-muted"><?=$i++?></td>
          <td class="td-bold"><?=htmlspecialchars($u['name'])?></td>
          <td><?=htmlspecialchars($u['username'])?></td>
          <td><span class="badge badge-purple"><?=htmlspecialchars(ucfirst($u['role']))?></span></td>
          <td><span class="badge badge-<?= $u['status']==='active' ? 'success' : 'danger' ?>"><?=htmlspecialchars(ucfirst($u['status']))?></span></td>
          <td style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <button type="button" class="btn btn-outline btn-sm" title="Edit" aria-label="Edit" onclick='openEditUser(this)'
              data-id="<?=$u['id']?>"
              data-name="<?=htmlspecialchars($u['name'], ENT_QUOTES)?>"
              data-username="<?=htmlspecialchars($u['username'], ENT_QUOTES)?>"
              data-email="<?=htmlspecialchars($u['email'] ?? '', ENT_QUOTES)?>"
              data-role="<?=htmlspecialchars($u['role'], ENT_QUOTES)?>"
              data-phone="<?=htmlspecialchars($u['phone'], ENT_QUOTES)?>"
              data-status="<?=htmlspecialchars($u['status'], ENT_QUOTES)?>"
              data-privileges="<?=htmlspecialchars($u['privileges'] ?? '', ENT_QUOTES)?>"
            >✏️</button>
            <?php if ((int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
            <form method="POST" onsubmit="return confirm('Delete <?=htmlspecialchars($u['name'], ENT_QUOTES)?>?')" style="margin:0">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?=$u['id']?>">
              <?= csrfInput() ?>
              <button type="submit" class="btn btn-danger btn-sm" title="Delete" aria-label="Delete">🗑️</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-overlay" id="add-user-modal" data-dismiss="true">
  <div class="modal">
    <div class="modal-header"><span class="modal-title">Add User</span><button class="modal-close" onclick="closeModal('add-user-modal')">✕</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <?= csrfInput() ?>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Full Name *</label><input name="name" class="form-control" required/></div>
          <div class="form-group"><label class="form-label">Username *</label><input name="username" class="form-control" required/></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Password *</label><input type="password" name="password" class="form-control" minlength="8" required/></div>
          <div class="form-group"><label class="form-label">Phone</label><input name="phone" class="form-control" placeholder="+255 7XX XXX XXX"/></div>
        </div>
        <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" placeholder="For password reset emails" autocomplete="off"/></div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Role</label>
            <select name="role" class="form-control">
              <option value="admin">Admin</option>
              <option value="manager">Manager</option>
              <option value="cashier" selected>Cashier</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
              <option value="active" selected>Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Privileges</label>
          <div class="form-row" style="grid-template-columns:repeat(2,1fr)">
            <?php foreach ($available_privileges as $key => $label): ?>
              <label class="badge badge-purple" style="display:flex;align-items:center;gap:8px;padding:10px 12px;cursor:pointer">
                <input type="checkbox" name="privileges[]" value="<?=$key?>" style="margin:0"/>
                <span><?=htmlspecialchars($label)?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-user-modal')">Cancel</button><button type="submit" class="btn btn-primary">Add User</button></div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="edit-user-modal" data-dismiss="true">
  <div class="modal">
    <div class="modal-header"><span class="modal-title">Edit User</span><button class="modal-close" onclick="closeModal('edit-user-modal')">✕</button></div>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit-user-id">
      <?= csrfInput() ?>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Full Name *</label><input name="name" id="edit-user-name" class="form-control" required/></div>
          <div class="form-group"><label class="form-label">Username *</label><input name="username" id="edit-user-username" class="form-control" required/></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">New Password</label><input type="password" name="password" id="edit-user-password" class="form-control" placeholder="Leave blank to keep current" minlength="8" autocomplete="new-password"/></div>
          <div class="form-group"><label class="form-label">Phone</label><input name="phone" id="edit-user-phone" class="form-control"/></div>
        </div>
        <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="edit-user-email" class="form-control" placeholder="For password reset emails" autocomplete="off"/></div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Role</label>
            <select name="role" id="edit-user-role" class="form-control">
              <option value="admin">Admin</option>
              <option value="manager">Manager</option>
              <option value="cashier">Cashier</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" id="edit-user-status" class="form-control">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Privileges</label>
          <div class="form-row" style="grid-template-columns:repeat(2,1fr)">
            <?php foreach ($available_privileges as $key => $label): ?>
              <label class="badge badge-purple" style="display:flex;align-items:center;gap:8px;padding:10px 12px;cursor:pointer">
                <input type="checkbox" name="privileges[]" value="<?=$key?>" class="edit-privilege" style="margin:0"/>
                <span><?=htmlspecialchars($label)?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('edit-user-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="delete-user-modal" data-dismiss="true">
  <div class="modal">
    <div class="modal-header"><span class="modal-title">Delete User</span><button class="modal-close" onclick="closeModal('delete-user-modal')">✕</button></div>
    <form method="POST" onsubmit="return confirm('Delete this user permanently?')">
      <input type="hidden" name="action" value="delete">
      <?= csrfInput() ?>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Select User</label>
          <select name="id" class="form-control" required>
            <option value="">Choose user...</option>
            <?php foreach ($user_delete_options as $user_row): ?>
              <?php if ((int)$user_row['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                <option value="<?=$user_row['id']?>"><?=htmlspecialchars($user_row['name'] . ' (' . $user_row['username'] . ')')?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('delete-user-modal')">Cancel</button><button type="submit" class="btn btn-danger">Delete User</button></div>
    </form>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
<script>
// Auto-dismiss notification message after 2 seconds
document.addEventListener('DOMContentLoaded', function() {
  const msgDiv = document.querySelector('div[style*="color:var"]');
  if (msgDiv && (msgDiv.textContent.trim().length > 0)) {
    setTimeout(() => {
      msgDiv.style.opacity = '0';
      msgDiv.style.transition = 'opacity 0.3s ease-out';
      setTimeout(() => msgDiv.remove(), 300);
    }, 2000);
  }
});

function openEditUser(button){
  document.getElementById('edit-user-id').value = button.dataset.id || '';
  document.getElementById('edit-user-name').value = button.dataset.name || '';
  document.getElementById('edit-user-username').value = button.dataset.username || '';
  document.getElementById('edit-user-email').value = button.dataset.email || '';
  document.getElementById('edit-user-password').value = '';
  document.getElementById('edit-user-role').value = button.dataset.role || 'cashier';
  document.getElementById('edit-user-phone').value = button.dataset.phone || '';
  document.getElementById('edit-user-status').value = button.dataset.status || 'active';
  const selectedPrivileges = (button.dataset.privileges || '').split(',').map(v => v.trim()).filter(Boolean);
  document.querySelectorAll('#edit-user-modal .edit-privilege').forEach(cb => {
    cb.checked = selectedPrivileges.includes(cb.value);
  });
  openModal('edit-user-modal');
}
</script>
