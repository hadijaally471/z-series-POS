<?php
$page_title = 'Inventory';
require_once 'includes/header.php';
requirePrivilege('inventory');

// Handle add/edit/delete
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'add' || $action === 'edit') {
        $name       = sanitizeString($_POST['name'] ?? '', 200);
        $cat_id     = sanitizeInt($_POST['category_id'] ?? 0);
        $sup_id     = !empty($_POST['supplier_id']) ? sanitizeInt($_POST['supplier_id']) : null;
        $rejareja   = sanitizeFloat($_POST['rejareja_price'] ?? 0);
        $jumla      = sanitizeFloat($_POST['jumla_price'] ?? 0);
        $stock      = sanitizeInt($_POST['stock'] ?? 0);
        $unit       = sanitizeString($_POST['unit'] ?? '', 30);
        $threshold  = sanitizeInt($_POST['low_stock_threshold'] ?? 10);
        
        if ($action === 'add') {
            $stmt = $conn->prepare("INSERT INTO products (name,category_id,supplier_id,rejareja_price,jumla_price,stock,unit,low_stock_threshold) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param('siiiddsi', $name,$cat_id,$sup_id,$rejareja,$jumla,$stock,$unit,$threshold);
            $stmt->execute();
            logActivity($conn, "Product added: $name", 'product');
            $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">✅ Product added successfully!</div>';
        } else {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("UPDATE products SET name=?,category_id=?,supplier_id=?,rejareja_price=?,jumla_price=?,stock=?,unit=?,low_stock_threshold=? WHERE id=?");
            $stmt->bind_param('siiiddsii', $name,$cat_id,$sup_id,$rejareja,$jumla,$stock,$unit,$threshold,$id);
            $stmt->execute();
            logActivity($conn, "Product updated: $name", 'product');
            $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">✅ Product updated!</div>';
        }
    }
    if ($action === 'delete') {
      $id = sanitizeInt($_POST['id'] ?? 0);
      $stmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
      $stmt->bind_param('i', $id);
      $stmt->execute();
      $p = $stmt->get_result()->fetch_assoc();
      $stmt = $conn->prepare("UPDATE products SET status='inactive' WHERE id = ?");
      $stmt->bind_param('i', $id);
      $stmt->execute();
        logActivity($conn, "Product removed: ".$p['name'], 'product');
        $msg = '<div style="color:var(--warning);padding:10px;background:rgba(245,158,11,0.1);border-radius:8px;margin-bottom:14px">⚠️ Product removed from inventory.</div>';
    }
}

  $search     = sanitizeString($_GET['search'] ?? '', 100);
  $cat_filter = sanitizeInt($_GET['cat'] ?? 0);
  $stock_filter = sanitizeString($_GET['stock'] ?? '', 20);
$where = ["p.status = 'active'"];
$params = [];
$types = '';
if ($search) {
  $where[] = "p.name LIKE ?";
  $params[] = '%' . $search . '%';
  $types .= 's';
}
if ($cat_filter) {
  $where[] = "p.category_id = ?";
  $params[] = $cat_filter;
  $types .= 'i';
}
if ($stock_filter === 'low')  $where[] = "p.stock <= p.low_stock_threshold AND p.stock > 0";
if ($stock_filter === 'out')  $where[] = "p.stock = 0";
if ($stock_filter === 'ok')   $where[] = "p.stock > p.low_stock_threshold";

$stmt = $conn->prepare("SELECT p.*, c.name as cat_name, s.name as sup_name FROM products p LEFT JOIN categories c ON p.category_id=c.id LEFT JOIN suppliers s ON p.supplier_id=s.id WHERE " . implode(' AND ', $where) . " ORDER BY c.name, p.name");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$products = $stmt->get_result();
$categories = $conn->query("SELECT * FROM categories ORDER BY name");
$suppliers  = $conn->query("SELECT * FROM suppliers WHERE status='active' ORDER BY name");
$total_products = $conn->query("SELECT COUNT(*) as c FROM products WHERE status='active'")->fetch_assoc()['c'];
$low_count  = $conn->query("SELECT COUNT(*) as c FROM products WHERE stock<=low_stock_threshold AND stock>0 AND status='active'")->fetch_assoc()['c'];
$out_count  = $conn->query("SELECT COUNT(*) as c FROM products WHERE stock=0 AND status='active'")->fetch_assoc()['c'];
$ok_count   = $total_products - $low_count - $out_count;
?>

<div class="stats-grid">
  <div class="stat-card purple"><div class="stat-label">Total Products</div><div class="stat-value"><?= $total_products ?></div><div class="stat-icon">📦</div></div>
  <div class="stat-card green"><div class="stat-label">Well Stocked</div><div class="stat-value"><?= $ok_count ?></div><div class="stat-icon">✅</div></div>
  <div class="stat-card amber"><div class="stat-label">Low Stock</div><div class="stat-value"><?= $low_count ?></div><div class="stat-icon">⚠️</div></div>
  <div class="stat-card red"><div class="stat-label">Out of Stock</div><div class="stat-value"><?= $out_count ?></div><div class="stat-icon">❌</div></div>
</div>

<?= $msg ?>

<form method="GET" style="display:contents">
<div class="filter-bar">
  <input class="filter-search" name="search" placeholder="Search products..." value="<?= htmlspecialchars($search) ?>"/>
  <select class="filter-select" name="cat" onchange="this.form.submit()">
    <option value="">All Categories</option>
    <?php $categories->data_seek(0); while($c=$categories->fetch_assoc()): ?>
    <option value="<?= $c['id'] ?>" <?= $cat_filter==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
    <?php endwhile; ?>
  </select>
  <select class="filter-select" name="stock" onchange="this.form.submit()">
    <option value="">All Stock</option>
    <option value="ok" <?= $stock_filter==='ok'?'selected':'' ?>>In Stock</option>
    <option value="low" <?= $stock_filter==='low'?'selected':'' ?>>Low Stock</option>
    <option value="out" <?= $stock_filter==='out'?'selected':'' ?>>Out of Stock</option>
  </select>
  <button type="submit" class="btn btn-outline">Filter</button>
  <button type="button" class="btn btn-primary" onclick="openModal('add-product-modal')">+ Add Product</button>
</div>
</form>

<div class="card">
  <div class="card-header">
    <span class="card-title">All Products (<?= $products->num_rows ?>)</span>
    <span class="card-action">Export CSV</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Product Name</th><th>Category</th><th>Rejareja Price</th><th>Jumla Price</th><th>Stock</th><th>Unit</th><th>Supplier</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php $i=1; while($p = $products->fetch_assoc()): ?>
        <tr>
          <td class="text-muted"><?= $i++ ?></td>
          <td class="td-bold"><?= htmlspecialchars($p['name']) ?></td>
          <td><?= htmlspecialchars($p['cat_name']) ?></td>
          <td class="text-purple"><?= tzs($p['rejareja_price']) ?></td>
          <td class="text-purple"><?= tzs($p['jumla_price']) ?></td>
          <td class="<?= $p['stock']==0?'text-danger':($p['stock']<=$p['low_stock_threshold']?'text-warning':'text-success') ?>"><?= $p['stock'] ?></td>
          <td><?= htmlspecialchars($p['unit']) ?></td>
          <td><?= htmlspecialchars($p['sup_name'] ?? '—') ?></td>
          <td>
            <?php if($p['stock']==0): ?><span class="badge badge-danger">Out of Stock</span>
            <?php elseif($p['stock']<=$p['low_stock_threshold']): ?><span class="badge badge-warning">Low Stock</span>
            <?php else: ?><span class="badge badge-success">In Stock</span><?php endif; ?>
          </td>
          <td>
            <button class="btn btn-outline btn-sm" onclick='editProduct(<?= json_encode($p) ?>)'>Edit</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Remove this product?')">
              <?= csrfInput() ?>
              <input type="hidden" name="action" value="delete"/>
              <input type="hidden" name="id" value="<?= $p['id'] ?>"/>
              <button type="submit" class="btn btn-danger btn-sm">Del</button>
            </form>
          </td>
        </tr>
        <?php endwhile; ?>
        <?php if($products->num_rows===0): ?>
        <tr><td colspan="10" style="text-align:center;padding:30px;color:var(--text3)">No products found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Product Modal -->
<div class="modal-overlay" id="add-product-modal" data-dismiss="true">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title" id="product-modal-title">Add New Product</span>
      <button class="modal-close" onclick="closeModal('add-product-modal')">✕</button>
    </div>
    <form method="POST">
      <?= csrfInput() ?>
      <input type="hidden" name="action" id="product-action" value="add"/>
      <input type="hidden" name="id" id="product-id"/>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Product Name *</label><input name="name" id="p-name" class="form-control" placeholder="e.g. Floor Tile 60x60" required/></div>
          <div class="form-group"><label class="form-label">Category *</label>
            <select name="category_id" id="p-cat" class="form-control" required>
              <option value="">Select category</option>
              <?php $categories->data_seek(0); while($c=$categories->fetch_assoc()): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Rejareja Price (TZS) *</label><input type="number" name="rejareja_price" id="p-rej" class="form-control" placeholder="Retail price" required/></div>
          <div class="form-group"><label class="form-label">Jumla Price (TZS) *</label><input type="number" name="jumla_price" id="p-jum" class="form-control" placeholder="Wholesale price" required/></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Stock Quantity *</label><input type="number" name="stock" id="p-stock" class="form-control" placeholder="0" required/></div>
          <div class="form-group"><label class="form-label">Unit</label>
            <select name="unit" id="p-unit" class="form-control">
              <option value="pcs">Pieces (pcs)</option>
              <option value="kg">Kilograms (kg)</option>
              <option value="bag">Bags</option>
              <option value="litre">Litres</option>
              <option value="box">Boxes</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Supplier</label>
            <select name="supplier_id" id="p-sup" class="form-control">
              <option value="">Select supplier</option>
              <?php $suppliers->data_seek(0); while($s=$suppliers->fetch_assoc()): ?>
              <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Low Stock Alert Threshold</label><input type="number" name="low_stock_threshold" id="p-thresh" class="form-control" value="10"/></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('add-product-modal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Product</button>
      </div>
    </form>
  </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
function editProduct(p){
  document.getElementById('product-modal-title').textContent = 'Edit Product';
  document.getElementById('product-action').value = 'edit';
  document.getElementById('product-id').value = p.id;
  document.getElementById('p-name').value = p.name;
  document.getElementById('p-cat').value = p.category_id;
  document.getElementById('p-rej').value = p.rejareja_price;
  document.getElementById('p-jum').value = p.jumla_price;
  document.getElementById('p-stock').value = p.stock;
  document.getElementById('p-unit').value = p.unit;
  document.getElementById('p-sup').value = p.supplier_id||'';
  document.getElementById('p-thresh').value = p.low_stock_threshold;
  openModal('add-product-modal');
}
</script>
JS;
require_once 'includes/footer.php';
?>
