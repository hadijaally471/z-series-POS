<?php
// tasks.php
$page_title = 'Tasks';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('tasks');
$user_id = (int)$_SESSION['user_id'];
$msg = '';
if (!empty($_SESSION['tasks_flash'])) {
  $msg = $_SESSION['tasks_flash'];
  unset($_SESSION['tasks_flash']);
}
$allowed_priorities = ['low', 'medium', 'high'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title = sanitizeString($_POST['title'] ?? '', 200);
        $description = sanitizeString($_POST['description'] ?? '', 2000);
        $due_date = sanitizeString($_POST['due_date'] ?? '', 20);
        $due_time = sanitizeString($_POST['due_time'] ?? '', 20) ?: null;
        $priority = in_array($_POST['priority'] ?? '', $allowed_priorities, true) ? $_POST['priority'] : 'medium';
        if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
            $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Title and a valid due date are required.</div>';
        } else {
            $stmt = $conn->prepare("INSERT INTO tasks (user_id,title,description,due_date,due_time,priority) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('isssss', $user_id, $title, $description, $due_date, $due_time, $priority);
            $stmt->execute();
            logActivity($conn, "Task created: $title", 'task');
            $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Task added!</div>';
        }
    }

    if ($action === 'edit') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        $title = sanitizeString($_POST['title'] ?? '', 200);
        $description = sanitizeString($_POST['description'] ?? '', 2000);
        $due_date = sanitizeString($_POST['due_date'] ?? '', 20);
        $due_time = sanitizeString($_POST['due_time'] ?? '', 20) ?: null;
        $priority = in_array($_POST['priority'] ?? '', $allowed_priorities, true) ? $_POST['priority'] : 'medium';
        if ($id > 0 && $title !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
            $stmt = $conn->prepare("UPDATE tasks SET title=?, description=?, due_date=?, due_time=?, priority=? WHERE id=? AND user_id=?");
            $stmt->bind_param('sssssii', $title, $description, $due_date, $due_time, $priority, $id, $user_id);
            $stmt->execute();
            logActivity($conn, "Task updated: $title", 'task');
            $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Task updated!</div>';
        }
    }

    if ($action === 'toggle') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE tasks SET status = IF(status='completed','pending','completed') WHERE id=? AND user_id=?");
            $stmt->bind_param('ii', $id, $user_id);
            $stmt->execute();
        }
    }

    if ($action === 'delete') {
        $id = sanitizeInt($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("SELECT title FROM tasks WHERE id=? AND user_id=?");
            $stmt->bind_param('ii', $id, $user_id);
            $stmt->execute();
            $t = $stmt->get_result()->fetch_assoc();
            $stmt = $conn->prepare("DELETE FROM tasks WHERE id=? AND user_id=?");
            $stmt->bind_param('ii', $id, $user_id);
            $stmt->execute();
            logActivity($conn, "Task deleted: " . ($t['title'] ?? 'Unknown'), 'task');
            $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Task deleted!</div>';
        }
    }

    $_SESSION['tasks_flash'] = $msg;
    $redirect_qs = $_GET ? ('?' . http_build_query($_GET)) : '';
    header('Location: tasks.php' . $redirect_qs);
    exit;
}

// --- Calendar month setup ---
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$month_start = $month . '-01';
$month_end   = date('Y-m-t', strtotime($month_start));
$prev_month  = date('Y-m', strtotime($month_start . ' -1 month'));
$next_month  = date('Y-m', strtotime($month_start . ' +1 month'));
$first_weekday = (int)date('w', strtotime($month_start));
$days_in_month = (int)date('t', strtotime($month_start));
$today = date('Y-m-d');

$day_f = $_GET['day'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day_f)) {
    $day_f = '';
}

$stmt = $conn->prepare("SELECT * FROM tasks WHERE user_id=? AND due_date BETWEEN ? AND ? ORDER BY due_date, due_time");
$stmt->bind_param('iss', $user_id, $month_start, $month_end);
$stmt->execute();
$month_tasks_result = $stmt->get_result();
$tasks_by_day = [];
while ($t = $month_tasks_result->fetch_assoc()) {
    $tasks_by_day[$t['due_date']][] = $t;
}

// --- Stats ---
$stmt = $conn->prepare("SELECT COUNT(*) as c FROM tasks WHERE user_id=? AND status='pending' AND due_date = CURDATE()");
$stmt->bind_param('i', $user_id); $stmt->execute();
$stat_today = $stmt->get_result()->fetch_assoc()['c'];

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM tasks WHERE user_id=? AND status='pending' AND due_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
$stmt->bind_param('i', $user_id); $stmt->execute();
$stat_upcoming = $stmt->get_result()->fetch_assoc()['c'];

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM tasks WHERE user_id=? AND status='pending' AND due_date < CURDATE()");
$stmt->bind_param('i', $user_id); $stmt->execute();
$stat_overdue = $stmt->get_result()->fetch_assoc()['c'];

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM tasks WHERE user_id=? AND status='completed'");
$stmt->bind_param('i', $user_id); $stmt->execute();
$stat_completed = $stmt->get_result()->fetch_assoc()['c'];

// --- List (filtered by day if selected, else all pending + recently completed) ---
if ($day_f !== '') {
    $stmt = $conn->prepare("SELECT * FROM tasks WHERE user_id=? AND due_date=? ORDER BY status='completed' ASC, due_time ASC");
    $stmt->bind_param('is', $user_id, $day_f);
} else {
    $stmt = $conn->prepare("SELECT * FROM tasks WHERE user_id=? ORDER BY status='completed' ASC, due_date ASC, due_time ASC");
    $stmt->bind_param('i', $user_id);
}
$stmt->execute();
$task_list = $stmt->get_result();

$priority_labels = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];
$priority_badge  = ['low' => 'purple', 'medium' => 'warning', 'high' => 'danger'];
$dow_labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$default_due_date = $day_f !== '' ? $day_f : $today;
?>
<div class="stats-grid">
  <div class="stat-card amber"><div class="stat-label">Due Today</div><div class="stat-value"><?=$stat_today?></div><div class="stat-icon"></div></div>
  <div class="stat-card blue"><div class="stat-label">Upcoming (7 days)</div><div class="stat-value"><?=$stat_upcoming?></div><div class="stat-icon"></div></div>
  <div class="stat-card red"><div class="stat-label">Overdue</div><div class="stat-value"><?=$stat_overdue?></div><div class="stat-icon"></div></div>
  <div class="stat-card green"><div class="stat-label">Completed</div><div class="stat-value"><?=$stat_completed?></div><div class="stat-icon"></div></div>
</div>
<?=$msg?>
<div style="margin-bottom:14px"><button class="btn btn-primary" onclick="openAddTaskModal()">+ Add Task</button></div>

<div class="card" style="margin-bottom:16px">
  <div class="cal-header">
    <div class="cal-title"><?=date('F Y', strtotime($month_start))?></div>
    <div class="cal-nav">
      <a class="btn btn-outline btn-sm" href="?month=<?=$prev_month?>">&larr; Prev</a>
      <a class="btn btn-outline btn-sm" href="?month=<?=date('Y-m')?>">Today</a>
      <a class="btn btn-outline btn-sm" href="?month=<?=$next_month?>">Next &rarr;</a>
    </div>
  </div>
  <div class="cal-grid">
    <?php foreach ($dow_labels as $dow): ?>
    <div class="cal-dow"><?=$dow?></div>
    <?php endforeach; ?>
    <?php for ($i = 0; $i < $first_weekday; $i++): ?>
    <div class="cal-day other-month"></div>
    <?php endfor; ?>
    <?php for ($d = 1; $d <= $days_in_month; $d++):
      $date_str = $month . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
      $is_today = $date_str === $today;
      $is_selected = $date_str === $day_f;
      $day_tasks = $tasks_by_day[$date_str] ?? [];
    ?>
    <div class="cal-day<?=$is_today?' today':''?><?=$is_selected?' selected':''?>" onclick="location.href='?month=<?=$month?>&day=<?=$date_str?>'">
      <div class="cal-day-num"><?=$d?></div>
      <div class="cal-day-tasks">
        <?php foreach (array_slice($day_tasks, 0, 3) as $dt):
          $chip_class = $dt['status']==='completed' ? 'done' : (($dt['due_date'] < $today) ? 'overdue' : '');
        ?>
        <div class="cal-day-task <?=$chip_class?>"><?=htmlspecialchars($dt['title'])?></div>
        <?php endforeach; ?>
        <?php if (count($day_tasks) > 3): ?>
        <div class="cal-day-more">+<?=count($day_tasks) - 3?> more</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endfor; ?>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title"><?=$day_f !== '' ? 'Tasks on ' . date('M d, Y', strtotime($day_f)) : 'All Tasks'?></span>
    <?php if ($day_f !== ''): ?><a class="btn btn-outline btn-sm" href="?month=<?=$month?>">Show All</a><?php endif; ?>
  </div>
  <div class="table-wrap"><table><thead><tr><th>#</th><th>Title</th><th>Due Date</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody><?php $i=1; while($t=$task_list->fetch_assoc()):
    $t_overdue = $t['status']==='pending' && $t['due_date'] < $today;
  ?>
  <tr<?=$t['status']==='completed'?' style="opacity:0.6"':''?>>
    <td class="text-muted"><?=$i++?></td>
    <td class="td-bold"><?=htmlspecialchars($t['title'])?><?=$t_overdue?' <span class="badge badge-danger" style="margin-left:4px">OVERDUE</span>':''?><?php if($t['description']):?><br/><small class="text-muted"><?=htmlspecialchars($t['description'])?></small><?php endif;?></td>
    <td class="<?=$t_overdue?'text-danger':'text-muted'?>"><?=date('M d, Y', strtotime($t['due_date']))?><?=$t['due_time']?' '.date('g:i A', strtotime($t['due_time'])):''?></td>
    <td><span class="badge badge-<?=$priority_badge[$t['priority']]?>"><?=$priority_labels[$t['priority']]?></span></td>
    <td>
      <form method="POST" style="margin:0" onsubmit="return true"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$t['id']?>"><?=csrfInput()?>
        <button type="submit" class="badge badge-<?=$t['status']==='completed'?'success':'warning'?>" style="border:none;cursor:pointer"><?=$t['status']==='completed'?'Completed':'Pending'?></button>
      </form>
    </td>
    <td style="display:flex;gap:6px;align-items:center">
      <button type="button" class="btn btn-outline btn-sm" onclick='openEditTask(this)' data-id="<?=$t['id']?>" data-title="<?=htmlspecialchars($t['title'], ENT_QUOTES)?>" data-description="<?=htmlspecialchars($t['description'] ?? '', ENT_QUOTES)?>" data-due-date="<?=$t['due_date']?>" data-due-time="<?=$t['due_time']?:''?>" data-priority="<?=$t['priority']?>">Edit</button>
      <form method="POST" style="margin:0" data-confirm="Delete this task?"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$t['id']?>"><?=csrfInput()?><button type="submit" class="btn btn-danger btn-sm">Delete</button></form>
    </td>
  </tr>
  <?php endwhile; if($task_list->num_rows===0):?><tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text3)">No tasks here</td></tr><?php endif;?></tbody></table></div>
</div>

<!-- Add Task Modal -->
<div class="modal-overlay" id="add-task-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Add Task</span><button class="modal-close" onclick="closeModal('add-task-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="add"><?= csrfInput() ?><div class="modal-body">
<div class="form-group"><label class="form-label">Title *</label><input name="title" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
<div class="form-row">
  <div class="form-group"><label class="form-label">Due Date *</label><input type="date" name="due_date" id="add-task-due-date" class="form-control" value="<?=htmlspecialchars($default_due_date)?>" required/></div>
  <div class="form-group"><label class="form-label">Due Time</label><input type="time" name="due_time" class="form-control"/></div>
</div>
<div class="form-group"><label class="form-label">Priority</label><select name="priority" class="form-control"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-task-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Task</button></div></form></div></div>

<!-- Edit Task Modal -->
<div class="modal-overlay" id="edit-task-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Edit Task</span><button class="modal-close" onclick="closeModal('edit-task-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit-task-id"><?= csrfInput() ?><div class="modal-body">
<div class="form-group"><label class="form-label">Title *</label><input name="title" id="edit-task-title" class="form-control" required/></div>
<div class="form-group"><label class="form-label">Description</label><textarea name="description" id="edit-task-description" class="form-control" rows="2"></textarea></div>
<div class="form-row">
  <div class="form-group"><label class="form-label">Due Date *</label><input type="date" name="due_date" id="edit-task-due-date" class="form-control" required/></div>
  <div class="form-group"><label class="form-label">Due Time</label><input type="time" name="due_time" id="edit-task-due-time" class="form-control"/></div>
</div>
<div class="form-group"><label class="form-label">Priority</label><select name="priority" id="edit-task-priority" class="form-control"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('edit-task-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div></form></div></div>

<?php require_once 'includes/footer.php'; ?>
<script>
function openAddTaskModal(){
  openModal('add-task-modal');
}
function openEditTask(btn){
  document.getElementById('edit-task-id').value = btn.dataset.id;
  document.getElementById('edit-task-title').value = btn.dataset.title;
  document.getElementById('edit-task-description').value = btn.dataset.description;
  document.getElementById('edit-task-due-date').value = btn.dataset.dueDate;
  document.getElementById('edit-task-due-time').value = btn.dataset.dueTime;
  document.getElementById('edit-task-priority').value = btn.dataset.priority;
  openModal('edit-task-modal');
}
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
</script>
