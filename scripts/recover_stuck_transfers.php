<?php
chdir(__DIR__.'/..');
require_once 'config.php';

if (PHP_SAPI !== 'cli') {
  requireLogin();
  if (($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Admin only.');
  }
}

$apply = (PHP_SAPI === 'cli') ? in_array('--apply', $argv, true) : (($_GET['apply'] ?? '') === '1');

// Reconstruct every product name ever moved by admin_inventory.php's "transfer"
// action from its activity_log trail (logActivity call in admin_inventory.php).
$res = $conn->query("SELECT DISTINCT action FROM activity_log WHERE type='product' AND action LIKE 'Transferred % to visible inventory'");
$names = [];
while ($row = $res->fetch_assoc()) {
  if (preg_match('/^Transferred \d+ (?:kg|Half Kg|Quarter Kg|pc|ctns) of (.+) to visible inventory$/', $row['action'], $m)) {
    $names[$m[1]] = true;
  }
}

$report = [];
foreach (array_keys($names) as $name) {
  $stmt = $conn->prepare("SELECT id, stock, status FROM products WHERE name = ? ORDER BY (status='active') DESC, id ASC");
  $stmt->bind_param('s', $name);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

  $active = null;
  $inactive = [];
  foreach ($rows as $r) {
    if ($r['status'] === 'active' && $active === null) $active = $r;
    elseif ($r['status'] === 'inactive') $inactive[] = $r;
  }

  foreach ($inactive as $inact) {
    if ((int)$inact['stock'] <= 0) continue; // nothing stuck here, ignore

    $entry = [
      'name' => $name,
      'hidden_id' => (int)$inact['id'],
      'hidden_stock' => (int)$inact['stock'],
      'action' => $active ? 'merge_into_active' : 'reactivate',
    ];
    if ($active) $entry['active_id'] = (int)$active['id'];

    if ($apply) {
      $conn->begin_transaction();
      try {
        if ($active) {
          $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
          $stmt->bind_param('ii', $inact['stock'], $active['id']);
          $stmt->execute();
          $stmt = $conn->prepare("UPDATE products SET stock = 0 WHERE id = ?");
          $stmt->bind_param('i', $inact['id']);
          $stmt->execute();
        } else {
          $stmt = $conn->prepare("UPDATE products SET status = 'active' WHERE id = ?");
          $stmt->bind_param('i', $inact['id']);
          $stmt->execute();
        }
        logActivity($conn, "Recovered {$inact['stock']} units of $name stuck on a hidden product row", 'product');
        $conn->commit();
      } catch (Exception $e) {
        $conn->rollback();
        $entry['error'] = $e->getMessage();
      }
    }

    $report[] = $entry;
  }
}

if (PHP_SAPI === 'cli') {
  if (!$report) {
    echo "No stuck transferred stock found.\n";
  } else {
    foreach ($report as $e) {
      $tag = isset($e['error']) ? '[FAILED]' : ($apply ? '[APPLIED]' : '[DRY RUN]');
      echo "$tag {$e['name']}: {$e['hidden_stock']} units on hidden row #{$e['hidden_id']} -> {$e['action']}";
      if (isset($e['active_id'])) echo " (active row #{$e['active_id']})";
      if (isset($e['error'])) echo " — {$e['error']}";
      echo "\n";
    }
    if (!$apply) echo "\nDry run only — re-run with --apply to fix.\n";
  }
} else {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['applied' => $apply, 'report' => $report]);
}
