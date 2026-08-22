<?php
$page_title = 'Korosho Inventory';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('korosho');
$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$msg = '';
if (!empty($_SESSION['korosho_inventory_flash'])) {
  $msg = $_SESSION['korosho_inventory_flash'];
  unset($_SESSION['korosho_inventory_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
  $action = $_POST['action'] ?? '';

  if ($action === 'add_product') {
    if (!$is_admin) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Only an admin can add products.</div>';
    } else {
      $name = sanitizeString($_POST['name'] ?? '', 200);
      $unit = sanitizeString($_POST['unit'] ?? 'kg', 30);
      $buying = sanitizeFloat($_POST['buying_price'] ?? 0);
      $rejareja = sanitizeFloat($_POST['rejareja_price'] ?? 0);
      $jumla = sanitizeFloat($_POST['jumla_price'] ?? 0);
      $stock = sanitizeInt($_POST['stock'] ?? 0);
      $threshold = sanitizeInt($_POST['low_stock_threshold'] ?? 10);
      if ($name === '' || $buying <= 0 || $rejareja <= 0 || $jumla <= 0) {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Name and positive prices are required.</div>';
      } else {
        $stmt = $conn->prepare("INSERT INTO korosho_products (name, unit, buying_price, rejareja_price, jumla_price, stock, low_stock_threshold) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('ssdddii', $name, $unit, $buying, $rejareja, $jumla, $stock, $threshold);
        $stmt->execute();
        logActivity($conn, "Korosho product added: $name", 'system');
        $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Product added!</div>';
      }
    }
  }

  if ($action === 'edit_product') {
    if (!$is_admin) {
      $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Only an admin can edit products.</div>';
    } else {
      $id = sanitizeInt($_POST['id'] ?? 0);
      $name = sanitizeString($_POST['name'] ?? '', 200);
      $unit = sanitizeString($_POST['unit'] ?? 'kg', 30);
      $buying = sanitizeFloat($_POST['buying_price'] ?? 0);
      $rejareja = sanitizeFloat($_POST['rejareja_price'] ?? 0);
      $jumla = sanitizeFloat($_POST['jumla_price'] ?? 0);
      $stock = sanitizeInt($_POST['stock'] ?? 0);
      $threshold = sanitizeInt($_POST['low_stock_threshold'] ?? 10);
      $status = sanitizeString($_POST['status'] ?? 'active', 20);
      if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
      if ($name === '' || $buying <= 0 || $rejareja <= 0 || $jumla <= 0) {
        $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Name and positive prices are required.</div>';
      } else {
        $stmt = $conn->prepare("UPDATE korosho_products SET name=?, unit=?, buying_price=?, rejareja_price=?, jumla_price=?, stock=?, low_stock_threshold=?, status=? WHERE id=?");
        $stmt->bind_param('ssdddiisi', $name, $unit, $buying, $rejareja, $jumla, $stock, $threshold, $status, $id);
        $stmt->execute();
        logActivity($conn, "Korosho product updated: $name", 'system');
        $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Product updated!</div>';
      }
    }
  }

  // POST/redirect/GET: send the browser to a plain GET after processing, so a
  // page refresh replays the GET instead of resubmitting the form.
  $_SESSION['korosho_inventory_flash'] = $msg;
  header('Location: korosho_inventory.php');
  exit;
}

$all_products_result = $conn->query("SELECT * FROM korosho_products ORDER BY name");
$all_products = [];
while ($p = $all_products_result->fetch_assoc()) $all_products[] = $p;
?>
<div class="no-print" style="margin-bottom:16px"><a href="korosho.php" class="btn btn-outline btn-sm">&larr; Korosho</a></div>

<?=$msg?>

<div class="card">
  <div class="card-header">
    <span class="card-title">Korosho Products</span>
    <?php if ($is_admin): ?><button type="button" class="btn btn-primary btn-sm" onclick="openModal('add-product-modal')">+ Add Product</button><?php endif; ?>
  </div>
  <div class="table-wrap reports-table-wrap"><table>
    <thead><tr><th>Name</th><th>Unit</th><?php if($is_admin):?><th>Buying Price</th><?php endif;?><th>Rejareja</th><th>Jumla</th><th>Stock</th><th>Status</th><?php if($is_admin):?><th>Action</th><?php endif;?></tr></thead>
    <tbody>
    <?php foreach ($all_products as $p): ?>
      <tr>
        <td class="td-bold"><?=htmlspecialchars($p['name'])?></td>
        <td><?=htmlspecialchars(unitLabel($p['unit']))?></td>
        <?php if ($is_admin): ?><td><?=tzs($p['buying_price'])?></td><?php endif; ?>
        <td><?=tzs($p['rejareja_price'])?></td>
        <td><?=tzs($p['jumla_price'])?></td>
        <td><?=number_format($p['stock'])?><?=$p['stock']<=$p['low_stock_threshold'] ? ' <span class="badge badge-danger">Low</span>' : ''?></td>
        <td><span class="badge badge-<?=$p['status']==='active'?'success':'danger'?>"><?=ucfirst($p['status'])?></span></td>
        <?php if ($is_admin): ?>
        <td><button type="button" class="btn btn-outline btn-sm" onclick='openEditProduct(<?=json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>Edit</button></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$all_products): ?>
      <tr><td colspan="<?=$is_admin?8:6?>" style="text-align:center;padding:22px;color:var(--text3)">No products yet<?php if ($is_admin): ?> — click "+ Add Product" to create one.<?php endif; ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php if ($is_admin): ?>
<!-- Add Product Modal -->
<div class="modal-overlay" id="add-product-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Add Korosho Product</span><button class="modal-close" onclick="closeModal('add-product-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="add_product"><?=csrfInput()?><div class="modal-body">
<div class="form-group"><label class="form-label">Product Name *</label>
<select name="name" class="form-control" required>
<option value="">Select product</option>
<?php foreach (KOROSHO_PRODUCT_CATALOG as $p_name): ?>
<option value="<?= htmlspecialchars($p_name) ?>"><?= htmlspecialchars($p_name) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="form-row">
<div class="form-group"><label class="form-label">Unit</label><select name="unit" class="form-control"><option value="kg">Kilograms (kg)</option></select></div>
<div class="form-group"><label class="form-label">Stock Quantity</label><input type="number" name="stock" class="form-control" value="0" min="0"/></div>
</div>
<div class="form-group"><label class="form-label">Buying Price (TZS) *</label><input type="number" name="buying_price" class="form-control" placeholder="Cost price" min="1" required/></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Rejareja Price (TZS) *</label><input type="number" name="rejareja_price" class="form-control" min="1" required/></div>
<div class="form-group"><label class="form-label">Jumla Price (TZS) *</label><input type="number" name="jumla_price" class="form-control" min="1" required/></div>
</div>
<div class="form-group"><label class="form-label">Low Stock Threshold</label><input type="number" name="low_stock_threshold" class="form-control" value="10" min="0"/></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('add-product-modal')">Cancel</button><button type="submit" class="btn btn-primary">Add Product</button></div></form></div></div>

<!-- Edit Product Modal -->
<div class="modal-overlay" id="edit-product-modal" data-dismiss="true"><div class="modal"><div class="modal-header"><span class="modal-title">Edit Korosho Product</span><button class="modal-close" onclick="closeModal('edit-product-modal')">&#x2715;</button></div>
<form method="POST"><input type="hidden" name="action" value="edit_product"><input type="hidden" name="id" id="edit-product-id"><?=csrfInput()?><div class="modal-body">
<div class="form-group"><label class="form-label">Product Name *</label>
<select name="name" id="edit-product-name" class="form-control" required>
<?php foreach (KOROSHO_PRODUCT_CATALOG as $p_name): ?>
<option value="<?= htmlspecialchars($p_name) ?>"><?= htmlspecialchars($p_name) ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="form-row">
<div class="form-group"><label class="form-label">Unit</label><select name="unit" id="edit-product-unit" class="form-control"><option value="kg">Kilograms (kg)</option></select></div>
<div class="form-group"><label class="form-label">Stock Quantity</label><input type="number" name="stock" id="edit-product-stock" class="form-control" min="0"/></div>
</div>
<div class="form-group"><label class="form-label">Buying Price (TZS) *</label><input type="number" name="buying_price" id="edit-product-buying" class="form-control" placeholder="Cost price" min="1" required/></div>
<div class="form-row">
<div class="form-group"><label class="form-label">Rejareja Price (TZS) *</label><input type="number" name="rejareja_price" id="edit-product-rejareja" class="form-control" min="1" required/></div>
<div class="form-group"><label class="form-label">Jumla Price (TZS) *</label><input type="number" name="jumla_price" id="edit-product-jumla" class="form-control" min="1" required/></div>
</div>
<div class="form-row">
<div class="form-group"><label class="form-label">Low Stock Threshold</label><input type="number" name="low_stock_threshold" id="edit-product-threshold" class="form-control" min="0"/></div>
<div class="form-group"><label class="form-label">Status</label><select name="status" id="edit-product-status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
</div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('edit-product-modal')">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div></form></div></div>
<?php endif; ?>

<?php
$extra_js = <<<'JS'
<script>
function openEditProduct(p){
  document.getElementById('edit-product-id').value = p.id;
  document.getElementById('edit-product-name').value = p.name;
  document.getElementById('edit-product-unit').value = p.unit;
  document.getElementById('edit-product-stock').value = p.stock;
  document.getElementById('edit-product-buying').value = p.buying_price;
  document.getElementById('edit-product-rejareja').value = p.rejareja_price;
  document.getElementById('edit-product-jumla').value = p.jumla_price;
  document.getElementById('edit-product-threshold').value = p.low_stock_threshold;
  document.getElementById('edit-product-status').value = p.status;
  openModal('edit-product-modal');
}
</script>
JS;
require_once 'includes/footer.php';
?>
