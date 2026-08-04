<?php
// backup.php — admin-only full database export. Generates a plain .sql dump
// (DROP + CREATE + INSERT per table) using PHP/mysqli directly rather than
// shelling out to `mysqldump`, since shared hosting often disables shell_exec.
$page_title = 'Backups';
require_once 'includes/header.php';
if (($_SESSION['user_role'] ?? '') !== 'admin') {
  http_response_code(403);
  die('<div style="padding: 40px; text-align: center; font-family: sans-serif; color: #dc3545;"><h2>Access Denied</h2><p>Backups is restricted to admin accounts only.</p><a href="dashboard.php" style="color: #0d6efd; text-decoration: none;">Back to Dashboard</a></div>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download') {
  requireCsrfToken();
  // Discard the sidebar HTML header.php already buffered, and stop buffering —
  // a full dump can be large, so it should stream straight out, not sit in memory.
  ob_end_clean();

  $filename = 'zseries_pos_backup_' . date('Y-m-d_His') . '.sql';
  header('Content-Type: application/sql');
  header('Content-Disposition: attachment; filename="' . $filename . '"');

  echo "-- Z-Series POS full backup\n-- Generated: " . date('c') . "\n\n";
  echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

  $tables = [];
  $res = $conn->query('SHOW TABLES');
  while ($row = $res->fetch_row()) $tables[] = $row[0];

  foreach ($tables as $table) {
    echo "-- --------------------------------------------------\n";
    echo "-- Table: $table\n";
    echo "-- --------------------------------------------------\n";
    echo "DROP TABLE IF EXISTS `$table`;\n";
    $create = $conn->query("SHOW CREATE TABLE `$table`")->fetch_assoc();
    echo $create['Create Table'] . ";\n\n";

    $rows = $conn->query("SELECT * FROM `$table`");
    if ($rows && $rows->num_rows > 0) {
      $buffer = [];
      while ($record = $rows->fetch_assoc()) {
        $vals = array_map(function ($v) use ($conn) {
          if ($v === null) return 'NULL';
          return "'" . $conn->real_escape_string($v) . "'";
        }, array_values($record));
        $buffer[] = '(' . implode(',', $vals) . ')';
        if (count($buffer) >= 500) {
          echo "INSERT INTO `$table` VALUES\n" . implode(",\n", $buffer) . ";\n";
          $buffer = [];
        }
      }
      if ($buffer) {
        echo "INSERT INTO `$table` VALUES\n" . implode(",\n", $buffer) . ";\n";
      }
      echo "\n";
    }
  }

  echo "SET FOREIGN_KEY_CHECKS=1;\n";
  logActivity($conn, 'Full database backup downloaded', 'system');
  exit;
}
?>
<div class="card">
  <div class="card-header"><span class="card-title">Database Backup</span></div>
  <div class="card-body">
    <p style="color:var(--text2);margin-bottom:14px;max-width:600px">
      Download a full SQL backup of the entire database — every table, every row (sales, debts, inventory, users, everything). Keep the file somewhere safe outside this server (a cloud drive, email to yourself, a USB drive) in case anything ever goes wrong here.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="download">
      <?= csrfInput() ?>
      <button type="submit" class="btn btn-primary">⬇ Download Full Backup (.sql)</button>
    </form>
    <p style="color:var(--text3);font-size:12px;margin-top:14px">This file contains everything, including account password hashes — store it privately, not somewhere publicly shared.</p>
  </div>
</div>
<?php require_once 'includes/footer.php'; ?>
