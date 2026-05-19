<?php
require_once '../config.php';
requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePrivilege('pos');
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
        exit();
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request body']);
        exit();
    }

    $cart         = is_array($data['cart'] ?? null) ? $data['cart'] : [];
    $priceType    = in_array(($data['priceType'] ?? 'rejareja'), ['rejareja', 'jumla'], true) ? $data['priceType'] : 'rejareja';
    $payMethod    = in_array(($data['payMethod'] ?? 'cash'), ['cash', 'mpesa', 'debt'], true) ? $data['payMethod'] : 'cash';
    $discount     = max(0, (float)($data['discount'] ?? 0));
    $customerId   = !empty($data['customerId']) ? (int)$data['customerId'] : null;
    $customerName = trim((string)($data['customerName'] ?? 'Walk-in')) ?: 'Walk-in';
    $customerCity = sanitizeString($data['customerCity'] ?? '', 200);
    
    if (empty($cart)) {
        echo json_encode(['success' => false, 'message' => 'Cart is empty']);
        exit();
    }
    if ($payMethod === 'debt' && !$customerId) {
        echo json_encode(['success' => false, 'message' => 'A customer is required for debt sales']);
        exit();
    }
    
    $conn->begin_transaction();
    try {
        $subtotal = 0;
        $normalizedCart = [];
        if ($customerId) {
            $customerStmt = $conn->prepare("SELECT id, name, location FROM customers WHERE id = ?");
            $customerStmt->bind_param('i', $customerId);
            $customerStmt->execute();
            $customer = $customerStmt->get_result()->fetch_assoc();
            if (!$customer) {
                throw new Exception('Customer not found');
            }
            $customerName = $customer['name'];
            if (empty($customerCity) && !empty($customer['location'])) {
                $customerCity = $customer['location'];
            }
        }

        $productStmt = $conn->prepare("SELECT id, name, stock, rejareja_price, jumla_price FROM products WHERE id = ? AND status = 'active' FOR UPDATE");
        $updateStockStmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
        $insertItemStmt = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, qty, unit_price, total) VALUES (?,?,?,?,?,?)");

        foreach ($cart as $item) {
            $product_id = (int)($item['id'] ?? 0);
            $qty = (int)($item['qty'] ?? 0);
            if ($product_id <= 0 || $qty <= 0) {
                throw new Exception('Invalid cart item');
            }

            $productStmt->bind_param('i', $product_id);
            $productStmt->execute();
            $product = $productStmt->get_result()->fetch_assoc();
            if (!$product) {
                throw new Exception('Product not found');
            }
            if ($qty > (int)$product['stock']) {
                throw new Exception('Not enough stock for ' . $product['name']);
            }

            $unit_price = $priceType === 'jumla' ? (float)$product['jumla_price'] : (float)$product['rejareja_price'];
            $item_total = $unit_price * $qty;
            $subtotal += $item_total;
            $normalizedCart[] = [
                'product_id' => $product_id,
                'product_name' => $product['name'],
                'qty' => $qty,
                'unit_price' => $unit_price,
                'total' => $item_total,
            ];
        }

        $total = max(0, $subtotal - $discount);
        if ($discount > $subtotal) {
            throw new Exception('Discount cannot exceed subtotal');
        }
        $temp_receipt = 'TMP-' . bin2hex(random_bytes(6));
        if (empty($customerCity)) {
            $customerCity = 'Arusha';
        }
        $stmt = $conn->prepare("INSERT INTO sales (receipt_number, customer_id, customer_name, customer_city, price_type, subtotal, discount, total, payment_method, cashier_id, status) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $status = 'completed';
        $cashierId = (int)$_SESSION['user_id'];
        $stmt->bind_param('sisssdddsis', $temp_receipt, $customerId, $customerName, $customerCity, $priceType, $subtotal, $discount, $total, $payMethod, $cashierId, $status);
        $stmt->execute();
        $sale_id = $conn->insert_id;

        $receipt_number = 'RCP-' . str_pad((string)$sale_id, 6, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare("UPDATE sales SET receipt_number = ? WHERE id = ?");
        $stmt->bind_param('si', $receipt_number, $sale_id);
        $stmt->execute();
        
        foreach ($normalizedCart as $item) {
            $product_id = $item['product_id'];
            $qty = $item['qty'];
            $unit_price = $item['unit_price'];
            $item_total = $item['total'];
            $product_name = $item['product_name'];

            $insertItemStmt->bind_param('iisidd', $sale_id, $product_id, $product_name, $qty, $unit_price, $item_total);
            $insertItemStmt->execute();

            $updateStockStmt->bind_param('iii', $qty, $product_id, $qty);
            $updateStockStmt->execute();
            if ($updateStockStmt->affected_rows !== 1) {
                throw new Exception('Stock changed during sale for ' . $product_name);
            }
        }
        
        // Handle debt
        if ($payMethod === 'debt' && $customerId) {
            $stmt3 = $conn->prepare("INSERT INTO debts (customer_id, customer_name, sale_id, amount, balance, description, debt_date, due_date, status) VALUES (?,?,?,?,?,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL 30 DAY),'outstanding')");
            $desc = "Sale: $receipt_number";
            $stmt3->bind_param('isidds', $customerId, $customerName, $sale_id, $total, $total, $desc);
            $stmt3->execute();
            // Update customer debt
            $stmtCust = $conn->prepare("UPDATE customers SET outstanding_debt = outstanding_debt + ? WHERE id = ?");
            $stmtCust->bind_param('di', $total, $customerId);
            $stmtCust->execute();
        }
        
        // Update customer total purchases
        if ($customerId) {
            $stmtCust = $conn->prepare("UPDATE customers SET total_purchases = total_purchases + ? WHERE id = ?");
            $stmtCust->bind_param('di', $total, $customerId);
            $stmtCust->execute();
        }
        
        $conn->commit();
        logActivity($conn, "Sale completed: $receipt_number — $customerName — " . number_format($total) . " TZS ($payMethod)", 'sale');
        echo json_encode(['success' => true, 'receipt_number' => $receipt_number, 'sale_id' => $sale_id, 'subtotal' => $subtotal, 'total' => $total]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requirePrivilege('receipts');
    $limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $search = trim($_GET['search'] ?? '');
    $where = '';
    $params = [];
    $types = '';
    if ($search !== '') {
        $term = '%' . mb_substr($search, 0, 100) . '%';
        $where = "WHERE s.receipt_number LIKE ? OR s.customer_name LIKE ?";
        $params[] = $term;
        $params[] = $term;
        $types .= 'ss';
    }
    $sql = "SELECT s.*, u.name as cashier FROM sales s LEFT JOIN users u ON s.cashier_id=u.id $where ORDER BY s.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $sales = $stmt->get_result();
    
    $rows = [];
    while ($r = $sales->fetch_assoc()) $rows[] = $r;
    echo json_encode(['success' => true, 'data' => $rows]);
}
?>
