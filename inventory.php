<?php
$page_title = 'Inventory';
require_once 'includes/header.php';
requirePrivilege('inventory');

// Handle add/edit/delete
$msg = '';
if (!empty($_SESSION['inventory_flash'])) {
  $msg = $_SESSION['inventory_flash'];
  unset($_SESSION['inventory_flash']);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['export'] ?? '') === 'csv') {
    requirePrivilege('inventory');
    ob_end_clean(); // discard the sidebar HTML already buffered by header.php — stream a clean CSV instead
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory_products.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'category', 'rejareja_price', 'jumla_price', 'stock', 'unit', 'low_stock_threshold']);
    $stmt = $conn->prepare("SELECT p.name, c.name as category, p.rejareja_price, p.jumla_price, p.stock, p.unit, p.low_stock_threshold FROM products p LEFT JOIN categories c ON p.category_id=c.id WHERE p.status='active' ORDER BY c.name, p.name");
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($row = $rows->fetch_assoc()) {
        fputcsv($out, [$row['name'], $row['category'], $row['rejareja_price'], $row['jumla_price'], $row['stock'], $row['unit'], $row['low_stock_threshold']]);
    }
    fclose($out);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  requireCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'add' || $action === 'edit') {
        $name       = sanitizeString($_POST['name'] ?? '', 200);
        $cat_id     = sanitizeInt($_POST['category_id'] ?? 0);
        $rejareja   = sanitizeFloat($_POST['rejareja_price'] ?? 0);
        $jumla      = sanitizeFloat($_POST['jumla_price'] ?? 0);
        $stock      = sanitizeInt($_POST['stock'] ?? 0);
        $unit       = sanitizeString($_POST['unit'] ?? '', 30);
        $threshold  = sanitizeInt($_POST['low_stock_threshold'] ?? 10);
        
        if ($action === 'add') {
            $stmt = $conn->prepare("INSERT INTO products (name,category_id,rejareja_price,jumla_price,stock,unit,low_stock_threshold) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param('siddisi', $name,$cat_id,$rejareja,$jumla,$stock,$unit,$threshold);
            $stmt->execute();
            logActivity($conn, "Product added: $name", 'product');
            $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Product added successfully!</div>';
        } else {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("UPDATE products SET name=?,category_id=?,rejareja_price=?,jumla_price=?,stock=?,unit=?,low_stock_threshold=? WHERE id=?");
            $stmt->bind_param('siddisii', $name,$cat_id,$rejareja,$jumla,$stock,$unit,$threshold,$id);
            $stmt->execute();
            logActivity($conn, "Product updated: $name", 'product');
            $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Product updated!</div>';
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
        $msg = '<div style="color:var(--warning);padding:10px;background:rgba(245,158,11,0.1);border-radius:8px;margin-bottom:14px">Product removed from inventory.</div>';
    }
      if ($action === 'import') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
          $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Import failed. Please choose a valid CSV file.</div>';
        } else {
          $path = $_FILES['import_file']['tmp_name'];
          $handle = fopen($path, 'r');
          if ($handle === false) {
            $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Unable to read the uploaded file.</div>';
          } else {
            $conn->begin_transaction();
            try {
              $header = fgetcsv($handle);
              $header_map = [];
              if ($header) {
                foreach ($header as $idx => $col) {
                  $key = strtolower(trim((string)$col));
                  $header_map[$key] = $idx;
                }
              }

              $rows_imported = 0;
              while (($row = fgetcsv($handle)) !== false) {
                if (!array_filter($row)) {
                  continue;
                }
                $name = $header ? ($row[$header_map['name'] ?? $header_map['product_name'] ?? 0] ?? '') : ($row[0] ?? '');
                $category = $header ? ($row[$header_map['category'] ?? 1] ?? '') : ($row[1] ?? '');
                $rejareja = $header ? ($row[$header_map['rejareja_price'] ?? 2] ?? 0) : ($row[2] ?? 0);
                $jumla = $header ? ($row[$header_map['jumla_price'] ?? 3] ?? 0) : ($row[3] ?? 0);
                $stock = $header ? ($row[$header_map['stock'] ?? 4] ?? 0) : ($row[4] ?? 0);
                $unit = $header ? ($row[$header_map['unit'] ?? 5] ?? 'pc') : ($row[5] ?? 'pc');
                $threshold = $header ? ($row[$header_map['low_stock_threshold'] ?? 6] ?? 10) : ($row[6] ?? 10);

                $name = sanitizeString($name, 200);
                $category = sanitizeString($category, 200);
                if ($name === '') {
                  continue;
                }

                $cat_id = 0;
                if ($category !== '') {
                  $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? LIMIT 1");
                  $stmt->bind_param('s', $category);
                  $stmt->execute();
                  $cat = $stmt->get_result()->fetch_assoc();
                  if ($cat) {
                    $cat_id = (int)$cat['id'];
                  } else {
                    $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
                    $stmt->bind_param('s', $category);
                    $stmt->execute();
                    $cat_id = $conn->insert_id;
                  }
                }

                $stmt = $conn->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                if ($existing) {
                  $id = (int)$existing['id'];
                  $stmt = $conn->prepare("UPDATE products SET category_id=?, rejareja_price=?, jumla_price=?, stock=?, unit=?, low_stock_threshold=? WHERE id=?");
                  $stmt->bind_param('iddisii', $cat_id, $rejareja, $jumla, $stock, $unit, $threshold, $id);
                  $stmt->execute();
                } else {
                  $stmt = $conn->prepare("INSERT INTO products (name,category_id,rejareja_price,jumla_price,stock,unit,low_stock_threshold) VALUES (?,?,?,?,?,?,?)");
                  $stmt->bind_param('siddisi', $name, $cat_id, $rejareja, $jumla, $stock, $unit, $threshold);
                  $stmt->execute();
                }
                $rows_imported++;
              }
              fclose($handle);
              $conn->commit();
              $msg = '<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">Imported ' . $rows_imported . ' products successfully.</div>';
            } catch (Exception $e) {
              $conn->rollback();
              $msg = '<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">Import failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
          }
        }
      }

    $_SESSION['inventory_flash'] = $msg;
    $redirect_qs = $_GET ? ('?' . http_build_query($_GET)) : '';
    header('Location: inventory.php' . $redirect_qs);
    exit;
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

$stmt = $conn->prepare("SELECT p.*, c.name as cat_name FROM products p LEFT JOIN categories c ON p.category_id=c.id WHERE " . implode(' AND ', $where) . " ORDER BY c.name, p.name");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$products = $stmt->get_result();
$categories = $conn->query("SELECT * FROM categories ORDER BY name");
$total_products = $conn->query("SELECT COUNT(*) as c FROM products WHERE status='active'")->fetch_assoc()['c'];
$low_count  = $conn->query("SELECT COUNT(*) as c FROM products WHERE stock<=low_stock_threshold AND stock>0 AND status='active'")->fetch_assoc()['c'];
$out_count  = $conn->query("SELECT COUNT(*) as c FROM products WHERE stock=0 AND status='active'")->fetch_assoc()['c'];
$ok_count   = $total_products - $low_count - $out_count;
?>

<div class="stats-grid">
  <div class="stat-card purple"><div class="stat-label">Total Products</div><div class="stat-value"><?= $total_products ?></div><div class="stat-icon"></div></div>
  <div class="stat-card green"><div class="stat-label">Well Stocked</div><div class="stat-value"><?= $ok_count ?></div><div class="stat-icon"></div></div>
  <div class="stat-card amber"><div class="stat-label">Low Stock</div><div class="stat-value"><?= $low_count ?></div><div class="stat-icon"></div></div>
  <div class="stat-card red"><div class="stat-label">Out of Stock</div><div class="stat-value"><?= $out_count ?></div><div class="stat-icon"></div></div>
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
    <div style="display:flex;gap:8px;align-items:center">
      <a class="card-action" href="?export=csv">Export CSV</a>
      <button type="button" class="card-action" onclick="openModal('import-products-modal')">Import CSV</button>
    </div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Product Name</th><th>Category</th><th>Rejareja Price</th><th>Jumla Price</th><th>Stock</th><th>Unit</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php $i=1; while($p = $products->fetch_assoc()): ?>
        <tr>
          <td class="text-muted"><?= $i++ ?></td>
          <td class="td-bold"><?= htmlspecialchars($p['name']) ?></td>
          <td><?= htmlspecialchars($p['cat_name']) ?></td>
          <td class="text-purple"><?= tzs($p['rejareja_price']) ?></td>
          <td class="text-purple"><?= tzs($p['jumla_price']) ?></td>
          <td class="<?= $p['stock']==0?'text-danger':($p['stock']<=$p['low_stock_threshold']?'text-warning':'text-success') ?>"><?= $p['stock'] ?></td>
          <td><?= htmlspecialchars(unitLabel($p['unit'])) ?></td>
          <td>
            <?php if($p['stock']==0): ?><span class="badge badge-danger">Out of Stock</span>
            <?php elseif($p['stock']<=$p['low_stock_threshold']): ?><span class="badge badge-warning">Low Stock</span>
            <?php else: ?><span class="badge badge-success">In Stock</span><?php endif; ?>
          </td>
          <td>
            <div class="action-buttons">
              <button class="btn btn-outline btn-sm" title="Edit" aria-label="Edit" onclick='editProduct(<?= json_encode($p) ?>)'>✏️</button>
              <form method="POST" onsubmit="return confirm('Remove this product?')">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="delete"/>
                <input type="hidden" name="id" value="<?= $p['id'] ?>"/>
                <button type="submit" class="btn btn-danger btn-sm" title="Delete" aria-label="Delete">🗑️</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endwhile; ?>
        <?php if($products->num_rows===0): ?>
        <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text3)">No products found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Import CSV Modal -->
<div class="modal-overlay" id="import-products-modal" data-dismiss="true">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">Import Products (CSV)</span>
      <button class="modal-close" onclick="closeModal('import-products-modal')">&times;</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <?= csrfInput() ?>
      <input type="hidden" name="action" value="import"/>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">CSV File</label>
          <input type="file" name="import_file" class="form-control" accept=".csv" required/>
        </div>
        <div class="form-group" style="font-size:12px;color:var(--text3)">
          Columns: name, category, rejareja_price, jumla_price, stock, unit, low_stock_threshold
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('import-products-modal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Import</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Product Modal -->
<div class="modal-overlay" id="add-product-modal" data-dismiss="true">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title" id="product-modal-title">Add New Product</span>
      <button class="modal-close" onclick="closeModal('add-product-modal')">&times;</button>
    </div>
    <form method="POST">
      <?= csrfInput() ?>
      <input type="hidden" name="action" id="product-action" value="add"/>
      <input type="hidden" name="id" id="product-id"/>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Product Name *</label>
            <select name="name" id="p-name" class="form-control" required>
              <option value="">Select product</option>
              <?php foreach (PRODUCT_CATALOG as $p_name): ?>
              <option value="<?= htmlspecialchars($p_name) ?>"><?= htmlspecialchars($p_name) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
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
              <option value="pc">Pieces (pc)</option>
              <option value="half_ctns">Half Carton</option>
              <option value="ctns">Cartons (ctns)</option>
            </select>
          </div>
        </div>
        <div class="form-row">
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
  const nameSelect = document.getElementById('p-name');
  if(p.name && ![...nameSelect.options].some(o=>o.value===p.name)){
    const opt = document.createElement('option');
    opt.value = p.name; opt.textContent = p.name;
    nameSelect.appendChild(opt);
  }
  nameSelect.value = p.name;
  document.getElementById('p-cat').value = p.category_id;
  document.getElementById('p-rej').value = p.rejareja_price;
  document.getElementById('p-jum').value = p.jumla_price;
  document.getElementById('p-stock').value = p.stock;
  document.getElementById('p-unit').value = p.unit;
  document.getElementById('p-thresh').value = p.low_stock_threshold;
  openModal('add-product-modal');
}
</script>
JS;
require_once 'includes/footer.php';
?>
