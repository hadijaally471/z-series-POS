<?php
require_once '../config.php';
requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePrivilege('korosho');
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
    $payMethod    = in_array(($data['payMethod'] ?? 'cash'), ['cash', 'lipa', 'bank'], true) ? $data['payMethod'] : 'cash';
    $discount     = max(0, (float)($data['discount'] ?? 0));
    $customerId   = !empty($data['customerId']) ? (int)$data['customerId'] : null;
    $customerName = trim((string)($data['customerName'] ?? 'Walk-in')) ?: 'Walk-in';
    $salesRepId   = !empty($data['salesRepId']) ? (int)$data['salesRepId'] : null;

    if (empty($cart)) {
        echo json_encode(['success' => false, 'message' => 'Cart is empty']);
        exit();
    }

    $conn->begin_transaction();
    try {
        $subtotal = 0;
        $normalizedCart = [];

        if ($customerId) {
            $customerStmt = $conn->prepare("SELECT id, name FROM korosho_customers WHERE id = ?");
            $customerStmt->bind_param('i', $customerId);
            $customerStmt->execute();
            $customer = $customerStmt->get_result()->fetch_assoc();
            if (!$customer) {
                throw new Exception('Customer not found');
            }
            $customerName = $customer['name'];
        }

        $productStmt = $conn->prepare("SELECT id, name, stock, buying_price, rejareja_price, jumla_price FROM korosho_products WHERE id = ? AND status = 'active' FOR UPDATE");
        $updateStockStmt = $conn->prepare("UPDATE korosho_products SET stock = stock - ? WHERE id = ? AND stock >= ?");
        $insertItemStmt = $conn->prepare("INSERT INTO korosho_sale_items (sale_id, product_id, product_name, qty, unit_price, buying_price, total) VALUES (?,?,?,?,?,?,?)");

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
                'buying_price' => (float)$product['buying_price'],
                'total' => $item_total,
            ];
        }

        if ($discount > $subtotal) {
            throw new Exception('Discount cannot exceed subtotal');
        }
        $total = max(0, $subtotal - $discount);

        $temp_receipt = 'TMP-' . bin2hex(random_bytes(6));
        $cashierId = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare("INSERT INTO korosho_sales (receipt_number, customer_id, customer_name, sales_rep_id, price_type, subtotal, discount, total, payment_method, cashier_id, status) VALUES (?,?,?,?,?,?,?,?,?,?,'completed')");
        $stmt->bind_param('sisisdddsi', $temp_receipt, $customerId, $customerName, $salesRepId, $priceType, $subtotal, $discount, $total, $payMethod, $cashierId);
        $stmt->execute();
        $sale_id = $conn->insert_id;

        $receipt_number = 'KOR-' . str_pad((string)$sale_id, 6, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare("UPDATE korosho_sales SET receipt_number = ? WHERE id = ?");
        $stmt->bind_param('si', $receipt_number, $sale_id);
        $stmt->execute();

        foreach ($normalizedCart as $item) {
            $insertItemStmt->bind_param('iisiddd', $sale_id, $item['product_id'], $item['product_name'], $item['qty'], $item['unit_price'], $item['buying_price'], $item['total']);
            $insertItemStmt->execute();

            $updateStockStmt->bind_param('iii', $item['qty'], $item['product_id'], $item['qty']);
            $updateStockStmt->execute();
            if ($updateStockStmt->affected_rows !== 1) {
                throw new Exception('Stock changed during sale for ' . $item['product_name']);
            }
        }

        if ($customerId) {
            $stmtCust = $conn->prepare("UPDATE korosho_customers SET total_purchases = total_purchases + ? WHERE id = ?");
            $stmtCust->bind_param('di', $total, $customerId);
            $stmtCust->execute();
        }

        $conn->commit();
        logActivity($conn, "Korosho sale completed: $receipt_number — $customerName — " . number_format($total) . " TZS ($payMethod)", 'sale');
        echo json_encode(['success' => true, 'receipt_number' => $receipt_number, 'sale_id' => $sale_id, 'subtotal' => $subtotal, 'total' => $total]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}
?>
