<?php
chdir(__DIR__.'/..');
require_once 'config.php';

if (PHP_SAPI !== 'cli') {
  requireLogin();
  requirePrivilege('employees');
}

header('Content-Type: application/json; charset=utf-8');

$res = $conn->query("SELECT COUNT(*) as c FROM employees");
$count = $res ? (int)$res->fetch_assoc()['c'] : 0;
$rows = [];
if($count>0){
  $r = $conn->query("SELECT id,name,role,phone,salary,start_date,status FROM employees ORDER BY name LIMIT 20");
  while($row = $r->fetch_assoc()) $rows[] = $row;
}

echo json_encode(['count'=>$count,'rows'=>$rows]);

?>
