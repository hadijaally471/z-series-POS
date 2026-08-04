<?php
$page_title = 'Debts';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('debts');
$msg = '';
if (!empty($_SESSION['debts_flash'])) {
  $msg = $_SESSION['debts_flash'];
  unset($_SESSION['debts_flash']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'pay') {
    $debt_id = sanitizeInt($_POST['debt_id'] ?? 0);
    $amount  = sanitizeFloat($_POST['amount'] ?? 0);
    $method  = sanitizeString($_POST['payment_method'] ?? 'cash', 20);
    $new_due = sanitizeString($_POST['due_date'] ?? '', 20);
    if (!in_array($method, ['cash', 'mpesa'], true)) {
      $method = 'cash';
    }
    if ($amount <= 0) {
      $msg='<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Payment amount must be greater than zero.</div>';
    } else {
      $conn->begin_transaction();
      try {
        $stmt = $conn->prepare("SELECT * FROM debts WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $debt_id);
        $stmt->execute();
        $debt = $stmt->get_result()->fetch_assoc();
        if (!$debt || $debt['status'] === 'cleared') {
          throw new Exception('Debt record was not found or is already cleared.');
        }
        if ($amount > (float)$debt['balance']) {
          throw new Exception('Payment cannot exceed the outstanding balance.');
        }
            $new_paid = $debt['amount_paid'] + $amount;
            $new_bal  = $debt['amount'] - $new_paid;
            $status   = $new_bal <= 0 ? 'cleared' : ($new_paid > 0 ? 'partial' : 'outstanding');
      $bal = max(0, $new_bal);
      if ($new_due !== '' && $status !== 'cleared') {
        $stmt_up = $conn->prepare("UPDATE debts SET amount_paid = ?, balance = ?, status = ?, due_date = ? WHERE id = ?");
        $stmt_up->bind_param('ddssi', $new_paid, $bal, $status, $new_due, $debt_id);
      } else {
        $stmt_up = $conn->prepare("UPDATE debts SET amount_paid = ?, balance = ?, status = ? WHERE id = ?");
        $stmt_up->bind_param('ddsi', $new_paid, $bal, $status, $debt_id);
      }
      $stmt_up->execute();
      $stmt = $conn->prepare("INSERT INTO debt_payments (debt_id,amount,payment_method) VALUES (?,?,?)");
      $stmt->bind_param('ids',$debt_id,$amount,$method); $stmt->execute();
      if ($debt['customer_id']) {
        $custId = (int)$debt['customer_id'];
        $dec = min($amount,$debt['balance']);
        $stmt_c = $conn->prepare("UPDATE customers SET outstanding_debt = outstanding_debt - ? WHERE id = ?");
        $stmt_c->bind_param('di', $dec, $custId);
        $stmt_c->execute();
      }
            $conn->commit();
            logActivity($conn,"Debt payment: ".$debt['customer_name']." — ".number_format($amount)." TZS",'debt');
            $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Payment recorded!</div>';
      } catch (Exception $e) {
        $conn->rollback();
        $msg='<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">'.htmlspecialchars($e->getMessage()).'</div>';
      }
    }
    }
    if ($action === 'add') {
    $cid = !empty($_POST['customer_id'])?sanitizeInt($_POST['customer_id']):null;
    $cname = sanitizeString($_POST['customer_name'] ?? '', 200); $amount = sanitizeFloat($_POST['amount'] ?? 0);
    $desc = sanitizeString($_POST['description'] ?? '', 500); $due = sanitizeString($_POST['due_date'] ?? '', 20);
    if ($amount <= 0 || $cname === '') {
      $msg='<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Customer name and a positive amount are required.</div>';
    } else {
      $conn->begin_transaction();
      try {
        if ($cid) {
          $stmt_cust = $conn->prepare("SELECT name FROM customers WHERE id = ?");
          $stmt_cust->bind_param('i', $cid);
          $stmt_cust->execute();
          $customer = $stmt_cust->get_result()->fetch_assoc();
          if (!$customer) {
            throw new Exception('Customer not found.');
          }
          $cname = $customer['name'];
        }
        $stmt = $conn->prepare("INSERT INTO debts (customer_id,customer_name,amount,balance,description,debt_date,due_date) VALUES (?,?,?,?,?,CURDATE(),?)");
        $stmt->bind_param('isddss',$cid,$cname,$amount,$amount,$desc,$due);
        $stmt->execute();
        if ($cid) {
          $stmt_up = $conn->prepare("UPDATE customers SET outstanding_debt = outstanding_debt + ? WHERE id = ?");
          $stmt_up->bind_param('di', $amount, $cid);
          $stmt_up->execute();
        }
        $conn->commit();
            logActivity($conn,"Debt recorded: $cname — ".number_format($amount)." TZS",'debt');
            $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Debt recorded!</div>';
      } catch (Exception $e) {
        $conn->rollback();
        $msg='<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">'.htmlspecialchars($e->getMessage()).'</div>';
      }
    }
    }
    if ($action === 'delete') {
    $debt_id = sanitizeInt($_POST['id'] ?? 0);
    if ($debt_id > 0) {
      $conn->begin_transaction();
      try {
        $stmt = $conn->prepare("SELECT * FROM debts WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $debt_id);
        $stmt->execute();
        $debt = $stmt->get_result()->fetch_assoc();
        if (!$debt) {
          throw new Exception('Debt record not found.');
        }
        if ($debt['sale_id']) {
          throw new Exception('This debt is linked to a sale — delete the receipt in Receipts instead.');
        }
        if ($debt['customer_id']) {
          $stmt_c = $conn->prepare("UPDATE customers SET outstanding_debt = outstanding_debt - ? WHERE id = ?");
          $stmt_c->bind_param('di', $debt['balance'], $debt['customer_id']);
          $stmt_c->execute();
        }
        $stmt_dp = $conn->prepare("DELETE FROM debt_payments WHERE debt_id = ?");
        $stmt_dp->bind_param('i', $debt_id);
        $stmt_dp->execute();
        $stmt_d = $conn->prepare("DELETE FROM debts WHERE id = ?");
        $stmt_d->bind_param('i', $debt_id);
        $stmt_d->execute();
        $conn->commit();
        $paid_note = (float)$debt['amount_paid'] > 0 ? ' (' . number_format($debt['amount_paid']) . ' TZS already collected — payment history removed)' : '';
        logActivity($conn, "Debt deleted: " . $debt['customer_name'] . " — " . number_format($debt['amount']) . " TZS" . $paid_note, 'debt');
        $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Debt record deleted!</div>';
      } catch (Exception $e) {
        $conn->rollback();
        $msg='<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">'.htmlspecialchars($e->getMessage()).'</div>';
      }
    }
    }

    $_SESSION['debts_flash'] = $msg;
    $redirect_qs = $_GET ? ('?' . http_build_query($_GET)) : '';
    header('Location: debts.php' . $redirect_qs);
    exit;
}
$debts = $conn->query("SELECT * FROM debts ORDER BY FIELD(status,'outstanding','partial','cleared'), debt_date ASC");
$stats = $conn->query("SELECT COALESCE(SUM(balance),0) as total, COUNT(*) as count, SUM(status='partial') as partial, SUM(DATEDIFF(CURDATE(),due_date)>0 AND status!='cleared') as overdue FROM debts WHERE status != 'cleared'")->fetch_assoc();
$customers_list = $conn->query("SELECT id,name FROM customers WHERE status = 'active' ORDER BY name");
?>
<div class="stats-grid">
  <div class="stat-card red"><div class="stat-label">Total Outstanding</div><div class="stat-value"><?=tzs($stats['total'])?></div><div class="stat-icon"></div></div>
  <div class="stat-card amber"><div class="stat-label">Debtors Count</div><div class="stat-value"><?=$stats['count']?></div><div class="stat-icon"></div></div>
  <div class="stat-card purple"><div class="stat-label">Partially Paid</div><div class="stat-value"><?=$stats['partial']?></div><div class="stat-icon"></div></div>
  <div class="stat-card red"><div class="stat-label">Overdue</div><div class="stat-value"><?=$stats['overdue']?></div><div class="stat-icon"></div></div>
</div>
<?=$msg?>
<div style="margin-bottom:14px"><button class="btn btn-primary" onclick="openModal('add-debt-modal')">+ Record Debt</button></div>
<div class="card"><div class="card-header"><span class="card-title">Debts</span></div>
<div class="table-wrap"><table><thead><tr><th>#</th><th>Customer</th><th>Total Amount</th><th>Paid</th><th>Balance</th><th>Debt Date</th><th>Due Date</th><th>Status</th><th>Action</th></tr></thead>
<tbody><?php $i=1; while($d=$debts->fetch_assoc()):
$cleared = $d['status']==='cleared';
$overdue = !$cleared && $d['due_date'] && strtotime($d['due_date']) < time();
$status_labels = ['outstanding'=>'Outstanding','partial'=>'Partial','cleared'=>'Paid'];
$status_class = $cleared ? 'success' : ($d['status']==='partial'?'warning':'danger');
?>
<tr <?php if(!$cleared): ?>style="cursor:pointer<?=$overdue?';background:rgba(239,68,68,0.04)':''?>" onclick="payDebt(<?=$d['id']?>,<?=$d['balance']?>,'<?=htmlspecialchars($d['customer_name'], ENT_QUOTES)?>','<?=$d['due_date']?:''?>')"<?php else: ?>style="opacity:0.65"<?php endif; ?>>
<td class="text-muted"><?=$i++?></td><td class="td-bold"><?=htmlspecialchars($d['customer_name'])?><?=$overdue?' <span class="badge badge-danger" style="margin-left:4px">OVERDUE</span>':''?></td>
<td><?=tzs($d['amount'])?></td><td class="text-success"><?=tzs($d['amount_paid'])?></td><td class="text-danger"><?=tzs($d['balance'])?></td>
<td class="text-muted"><?=date('M d, Y',strtotime($d['debt_date']))?></td>
<td class="<?=$overdue?'text-danger':''?>"><?=$d['due_date']?date('M d, Y',strtotime($d['due_date'])):'—'?></td>
<td><span class="badge badge-<?=$status_class?>"><?=$status_labels[$d['status']]?></span></td>
<td style="display:flex;gap:6px;align-items:center">
<?php if(!$cleared): ?><button class="btn btn-success btn-sm" onclick="event.stopPropagation();payDebt(<?=$d['id']?>,<?=$d['balance']?>,'<?=htmlspecialchars($d['customer_name'], ENT_QUOTES)?>','<?=$d['due_date']?:''?>')">Pay</button><?php endif; ?>
<?php if(!$d['sale_id']):
$confirm_text = "Delete this debt record for " . htmlspecialchars($d['customer_name'], ENT_QUOTES) . "?";
$confirm_text .= (float)$d['amount_paid'] > 0
  ? " This customer already paid " . number_format($d['amount_paid']) . " TZS toward it — that payment history will be permanently deleted too."
  : " This cannot be undone.";
?>
<form method="POST" style="margin:0" onclick="event.stopPropagation()" data-confirm="<?=$confirm_text?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$d['id']?>"><?=csrfInput()?><button type="submit" class="btn btn-danger btn-sm">Delete</button></form>
<?php endif; ?>
</td>
</tr>
<?php endwhile; if($debts->num_rows===0):?><tr><td colspan="9" style="text-align:center;padding:30px;color:var(--success)">No debts recorded yet!</td></tr><?php endif;?></tbody></table></div></div>

<!-- Add Debt Modal -->
<div class="modal-overlay" id="add-debt-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Record Debt</span><button class="modal-close" onclick="closeModal('add-debt-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="add"><?= csrfInput() ?><div class="modal-body">
<div class="form-row">
<div class="form-group"><label class="form-label">Customer</label><select name="customer_id" class="form-control" onchange="setDebtName(this)"><option value="">-- Walk-in/Other --</option><?php $customers_list->data_seek(0); while($c=$customers_list->fetch_assoc()):?><option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option><?php endwhile;?></select></div>
<div class="form-group"><label class="form-label">Customer Name *</label><input name="customer_name" id="debt-cname" class="form-control" placeholder="Full name" required/></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Amount (TZS) *</label><input type="number" name="amount" class="form-control" required/></div><div class="form-group"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-control" value="<?=date('Y-m-d',strtotime('+30 days'))?>"/></div></div>
<div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2" placeholder="What was purchased on debt?"></textarea></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-debt-modal')">Cancel</button><button type="submit" class="btn btn-primary">Record Debt</button></div></form></div></div>

<!-- Pay Debt Modal -->
<div class="modal-overlay" id="pay-debt-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Record Payment</span><button class="modal-close" onclick="closeModal('pay-debt-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="pay"><input type="hidden" name="debt_id" id="pay-debt-id"><?= csrfInput() ?>
<div class="modal-body">
<div style="margin-bottom:14px;padding:14px;background:var(--bg3);border-radius:10px"><div style="font-size:13px;color:var(--text2)">Customer: <strong id="pay-cname" style="color:var(--text)"></strong></div><div style="font-size:13px;color:var(--text2);margin-top:4px">Balance: <strong id="pay-balance" style="color:var(--danger)"></strong></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Amount Paying (TZS) *</label><input type="number" name="amount" id="pay-amount" class="form-control" required/></div><div class="form-group"><label class="form-label">Payment Method</label><select name="payment_method" class="form-control"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option></select></div></div>
<div class="form-group"><label class="form-label">Due Date (for remaining balance)</label><input type="date" name="due_date" id="pay-due-date" class="form-control"/></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('pay-debt-modal')">Cancel</button><button type="submit" class="btn btn-success">Record Payment</button></div></form></div></div>

<?php
$extra_js = <<<'JS'
<script>
function payDebt(id, balance, name, dueDate){
  document.getElementById('pay-debt-id').value = id;
  document.getElementById('pay-amount').value = balance;
  document.getElementById('pay-cname').textContent = name;
  document.getElementById('pay-balance').textContent = 'TZS '+balance.toLocaleString();
  document.getElementById('pay-due-date').value = dueDate || '';
  openModal('pay-debt-modal');
}
function setDebtName(sel){
  const name = sel.selectedOptions[0].text;
  if(sel.value) document.getElementById('debt-cname').value = name;
}
</script>
JS;
require_once 'includes/footer.php'; ?>
