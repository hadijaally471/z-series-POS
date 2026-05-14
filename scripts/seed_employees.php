<?php
/**
 * Simple seeder for employees table.
 * Run from browser while signed in as an employees/admin user
 * or CLI: php scripts/seed_employees.php
 */
chdir(__DIR__.'/..');
require_once 'config.php';

if (PHP_SAPI !== 'cli') {
    requireLogin();
    requirePrivilege('employees');
}

header('Content-Type: text/plain; charset=utf-8');

// basic guard: only run in non-production? We keep it manual.
$res = $conn->query("SELECT COUNT(*) as c FROM employees");
$c = $res ? (int)$res->fetch_assoc()['c'] : 0;
if($c > 0){
    echo "Employees table already has {$c} rows.\n";
    exit;
}

$samples = [
    ['Admin User','Administrator','+255 712 000 001',600000,'2025-01-01','active'],
    ['Amina Rashid','Cashier','+255 723 000 002',350000,'2025-03-01','active'],
    ['Bakari Hamisi','Factory Worker','+255 734 000 003',280000,'2025-06-01','active'],
    ['Zuwena Ali','Factory Worker','+255 745 000 004',280000,'2025-06-01','on_leave'],
    ['Omari Hassan','Driver','+255 756 000 005',320000,'2025-09-01','active'],
    ['Hassan Ally','Sales Person','+255 712 111 222',300000,'2025-11-01','active'],
    ['Fatuma Said','Cashier','+255 723 333 444',320000,'2026-01-15','active']
];

$stmt = $conn->prepare("INSERT INTO employees (name,role,phone,salary,start_date,status) VALUES (?,?,?,?,?,?)");
foreach($samples as $s){
    [$name,$role,$phone,$salary,$start,$status] = $s;
    $stmt->bind_param('sssdss',$name,$role,$phone,$salary,$start,$status);
    if(!$stmt->execute()){
        echo "Insert failed: ".($stmt->error ?? 'unknown')."\n";
        exit(1);
    }
}

echo "Inserted ".count($samples)." sample employees.\n";
// update activity log
logActivity($conn, 'Seeded employees table with sample data', 'system');

?>
