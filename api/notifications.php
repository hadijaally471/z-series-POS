<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
requireLogin();

// Build real notifications from business data
$notifications = [];

// 1. Low stock products
$low_stock = $conn->query("SELECT id, name, stock, low_stock_threshold FROM products WHERE stock <= low_stock_threshold AND status='active' ORDER BY stock ASC");
if ($low_stock) {
    while ($p = $low_stock->fetch_assoc()) {
        $notifications[] = [
            'id' => 'low_stock_' . $p['id'],
            'type' => 'low_stock',
            'title' => ' Low Stock Alert',
            'body' => $p['name'] . ' (Stock: ' . $p['stock'] . ')',
            'link' => 'inventory.php',
            'priority' => 'high'
        ];
    }
}

// 2. Overdue debts
$overdue = $conn->query("SELECT id, customer_name, balance FROM debts WHERE status != 'cleared' AND due_date < CURDATE() ORDER BY due_date ASC LIMIT 5");
if ($overdue) {
    while ($d = $overdue->fetch_assoc()) {
        $notifications[] = [
            'id' => 'overdue_debt_' . $d['id'],
            'type' => 'overdue_debt',
            'title' => ' Overdue Debt',
            'body' => $d['customer_name'] . ' owes TZS ' . number_format($d['balance']),
            'link' => 'debts.php',
            'priority' => 'high'
        ];
    }
}

// 3. Pending purchase orders (if not received)
$pending_po = $conn->query("SELECT id, supplier_name, order_date FROM purchase_orders WHERE status='pending' ORDER BY order_date ASC LIMIT 3");
if ($pending_po) {
    while ($po = $pending_po->fetch_assoc()) {
        $days_pending = intval((time() - strtotime($po['order_date'])) / 86400);
        if ($days_pending > 1) {
            $notifications[] = [
                'id' => 'pending_po_' . $po['id'],
                'type' => 'pending_po',
                'title' => ' Pending Purchase Order',
                'body' => $po['supplier_name'] . ' (' . $days_pending . ' days pending)',
                'link' => 'purchase_orders.php',
                'priority' => 'medium'
            ];
        }
    }
}

// 4. Active expenses (last 24h)
$recent_expenses = $conn->query("SELECT COUNT(*) as c, COALESCE(SUM(amount),0) as total FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
if ($recent_expenses) {
    $exp = $recent_expenses->fetch_assoc();
    if ($exp['c'] > 0) {
        $notifications[] = [
            'id' => 'recent_expenses_24h',
            'type' => 'expenses',
            'title' => ' Recent Expenses',
            'body' => $exp['c'] . ' expense(s): TZS ' . number_format($exp['total']),
            'link' => 'expenses.php',
            'priority' => 'low'
        ];
    }
}

// 5. Own tasks that are due soon (today, overdue, or within the next 2 days)
$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id) {
    $stmt = $conn->prepare("SELECT id, title, due_date FROM tasks WHERE user_id = ? AND status = 'pending' AND due_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY) ORDER BY due_date ASC LIMIT 10");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $due_tasks = $stmt->get_result();
    while ($t = $due_tasks->fetch_assoc()) {
        $is_overdue = $t['due_date'] < date('Y-m-d');
        $is_today   = $t['due_date'] === date('Y-m-d');
        $when = $is_overdue ? 'Overdue' : ($is_today ? 'Due today' : 'Due ' . date('M d', strtotime($t['due_date'])));
        $notifications[] = [
            'id' => 'task_due_' . $t['id'],
            'type' => 'task_due',
            'title' => ($is_overdue ? ' Overdue Task' : ' Task Reminder'),
            'body' => $t['title'] . ' — ' . $when,
            'link' => 'tasks.php',
            'priority' => $is_overdue || $is_today ? 'high' : 'medium'
        ];
    }
}

// 6. Pending payroll nearing/at month end
if (hasPrivilege('payroll')) {
    $current_period = date('Y-m');
    $day_of_month = (int)date('j');
    $days_in_month = (int)date('t');
    if ($day_of_month >= $days_in_month - 5) {
        $stmt = $conn->prepare("SELECT COUNT(*) as c, COALESCE(SUM(net_pay),0) as total FROM payroll WHERE period = ? AND status = 'pending'");
        $stmt->bind_param('s', $current_period);
        $stmt->execute();
        $pr = $stmt->get_result()->fetch_assoc();
        if ($pr['c'] > 0) {
            $notifications[] = [
                'id' => 'pending_payroll_' . $current_period,
                'type' => 'pending_payroll',
                'title' => ' Payroll Reminder',
                'body' => $pr['c'] . ' employee(s) not yet paid: TZS ' . number_format($pr['total']),
                'link' => 'payroll.php',
                'priority' => 'medium'
            ];
        }
    }
}

// Return as JSON
echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'count' => count($notifications),
    'timestamp' => date('c')
]);
?>
