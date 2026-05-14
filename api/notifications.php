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
            'title' => '📦 Low Stock Alert',
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
            'title' => '💰 Overdue Debt',
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
                'title' => '📋 Pending Purchase Order',
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
            'title' => '💸 Recent Expenses',
            'body' => $exp['c'] . ' expense(s): TZS ' . number_format($exp['total']),
            'link' => 'expenses.php',
            'priority' => 'low'
        ];
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
