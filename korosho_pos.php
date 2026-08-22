<?php
$page_title = 'Korosho POS';
$content_class = 'content premium-content';
require_once 'includes/header.php';
requirePrivilege('korosho');

$products_result = $conn->query("SELECT * FROM korosho_products WHERE status='active' ORDER BY name");
$products = [];
while ($p = $products_result->fetch_assoc()) $products[] = $p;

$customers_result = $conn->query("SELECT * FROM korosho_customers WHERE status='active' ORDER BY name");
$customers = [];
while ($c = $customers_result->fetch_assoc()) $customers[] = $c;

$sales_reps_result = $conn->query("SELECT id, name FROM korosho_employees WHERE status='active' ORDER BY name");
$sales_reps = [];
while ($r = $sales_reps_result->fetch_assoc()) $sales_reps[] = $r;
?>
<div class="no-print" style="margin-bottom:16px"><a href="korosho.php" class="btn btn-outline btn-sm">&larr; Korosho</a></div>

<div class="card">
  <div class="card-header"><span class="card-title">Sell Korosho Products</span></div>
  <div class="card-body">
    <div style="display:flex;gap:16px;flex-wrap:wrap">
      <div style="flex:2;min-width:280px">
        <div class="pos-products" id="korosho-products-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">
          <?php foreach ($products as $p): ?>
          <div class="pos-product <?= $p['stock']==0?'out-of-stock':'' ?>"
               data-id="<?= $p['id'] ?>"
               data-name="<?= htmlspecialchars($p['name']) ?>"
               data-rejareja="<?= $p['rejareja_price'] ?>"
               data-jumla="<?= $p['jumla_price'] ?>"
               data-stock="<?= $p['stock'] ?>"
               data-unit="<?= htmlspecialchars($p['unit']) ?>"
               onclick="koroshoAddToCart(this)">
            <div class="pos-product-name"><?= htmlspecialchars($p['name']) ?></div>
            <div class="pos-product-prices">
              <div class="pos-product-price">Rejareja: <span><?= tzs($p['rejareja_price']) ?></span></div>
              <div class="pos-product-price">Jumla: <span><?= tzs($p['jumla_price']) ?></span></div>
            </div>
            <div class="pos-product-stock <?= $p['stock']==0?'out':($p['stock']<=$p['low_stock_threshold']?'low':'') ?>">
              <?= $p['stock']==0 ? 'Out of stock' : 'Stock: '.$p['stock'].' '.unitLabel($p['unit']) ?>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (!$products): ?>
          <div style="color:var(--text3);padding:12px">No Korosho products yet — add one in <a href="korosho_inventory.php">Inventory</a>.</div>
          <?php endif; ?>
        </div>
      </div>
      <div style="flex:1;min-width:260px">
        <div style="position:relative;margin-bottom:10px">
          <input type="text" class="form-control" id="korosho-customer-search" style="font-size:12px" placeholder="Walk-in Customer — search to select..." autocomplete="off"/>
          <div id="korosho-customer-search-results" style="display:none;position:absolute;z-index:20;top:100%;left:0;right:0;max-height:220px;overflow-y:auto;background:var(--card-bg);border:1px solid var(--border);border-radius:8px;margin-top:4px;box-shadow:0 8px 20px rgba(0,0,0,0.35)"></div>
        </div>
        <select id="korosho-customer-select" style="display:none">
          <option value="">Walk-in Customer</option>
          <?php foreach ($customers as $c): ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="form-control" id="korosho-sales-rep-select" style="font-size:12px;margin-bottom:10px">
          <option value="">— Sales Rep (optional) —</option>
          <?php foreach ($sales_reps as $r): ?>
          <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="price-type-btns">
          <button class="price-type-btn active" id="korosho-btn-rejareja" onclick="koroshoSetPriceType('rejareja')">Rejareja (Retail)</button>
          <button class="price-type-btn" id="korosho-btn-jumla" onclick="koroshoSetPriceType('jumla')">Jumla (Wholesale)</button>
        </div>
        <div id="korosho-cart" style="margin-top:10px;max-height:260px;overflow-y:auto">
          <div class="cart-empty">No items yet<br/><small>Click a product to add</small></div>
        </div>
        <div class="pos-totals" style="margin-top:10px">
          <div class="pos-total-row"><span>Subtotal</span><span id="korosho-subtotal">TZS 0</span></div>
          <div class="pos-total-row"><span>Discount (TZS)</span><input type="number" id="korosho-discount" class="discount-input" value="0" min="0" oninput="koroshoUpdateTotals()"/></div>
          <div class="pos-total-row grand"><span>TOTAL</span><span id="korosho-total">TZS 0</span></div>
        </div>
        <div class="pay-methods" style="margin-top:10px">
          <button class="pay-method active" id="korosho-pay-cash" onclick="koroshoSetPayMethod('cash')">Cash</button>
          <button class="pay-method" id="korosho-pay-lipa" onclick="koroshoSetPayMethod('lipa')">Lipa</button>
          <button class="pay-method" id="korosho-pay-bank" onclick="koroshoSetPayMethod('bank')">Bank</button>
        </div>
        <button class="pay-btn" style="margin-top:10px;width:100%" onclick="koroshoProcessSale()">Complete Sale & Print</button>
      </div>
    </div>
  </div>
</div>

<!-- Receipt Modal -->
<div class="modal-overlay" id="korosho-receipt-modal" data-dismiss="true">
  <div class="modal" style="width:380px">
    <div class="modal-header">
      <span class="modal-title">Receipt</span>
      <button class="modal-close" onclick="closeModal('korosho-receipt-modal')">&times;</button>
    </div>
    <div class="modal-body" id="korosho-receipt-content"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('korosho-receipt-modal')">Close</button>
      <button class="btn btn-primary" onclick="printReceipt('#korosho-receipt-content .receipt-box')">Print</button>
    </div>
  </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
let koroshoCart = [], koroshoPriceType = 'rejareja', koroshoPayMethod = 'cash';

function koroshoFmt(n){return 'TZS '+(+n).toLocaleString();}

// --- Customer search box: filters the hidden <select id="korosho-customer-select"> ---
const koroshoCustomerOptions = Array.from(document.getElementById('korosho-customer-select').options).filter(o => o.value);

function koroshoSelectCustomer(id, label){
  const sel = document.getElementById('korosho-customer-select');
  sel.value = id;
  document.getElementById('korosho-customer-search').value = label;
  document.getElementById('korosho-customer-search-results').style.display = 'none';
}

function koroshoRenderCustomerResults(matches){
  const box = document.getElementById('korosho-customer-search-results');
  if(!matches.length){ box.style.display='none'; box.innerHTML=''; return; }
  box.innerHTML = matches.slice(0,50).map(o =>
    `<div class="customer-result-item" data-id="${o.value}" style="padding:8px 12px;cursor:pointer;font-size:12px;border-bottom:1px solid var(--border2)" onmousedown="koroshoSelectCustomer('${o.value}', this.textContent)">${o.text}</div>`
  ).join('');
  box.style.display = 'block';
}

document.getElementById('korosho-customer-search')?.addEventListener('input', (e) => {
  const q = e.target.value.trim().toLowerCase();
  if(!q){ document.getElementById('korosho-customer-select').value=''; document.getElementById('korosho-customer-search-results').style.display='none'; return; }
  koroshoRenderCustomerResults(koroshoCustomerOptions.filter(o => o.text.toLowerCase().includes(q)));
});

document.getElementById('korosho-customer-search')?.addEventListener('focus', (e) => {
  const q = e.target.value.trim().toLowerCase();
  koroshoRenderCustomerResults(q ? koroshoCustomerOptions.filter(o => o.text.toLowerCase().includes(q)) : koroshoCustomerOptions);
});

document.addEventListener('click', (e) => {
  if(!e.target.closest('#korosho-customer-search') && !e.target.closest('#korosho-customer-search-results')){
    document.getElementById('korosho-customer-search-results').style.display = 'none';
  }
});

function koroshoAddToCart(el){
  if(el.classList.contains('out-of-stock')){showToast('Out of stock!','error');return;}
  const id = el.dataset.id;
  const existing = koroshoCart.find(x=>x.id===id);
  if(existing){
    if(existing.qty>=parseInt(el.dataset.stock)){showToast('Not enough stock!','error');return;}
    existing.qty++;
  } else {
    koroshoCart.push({id,name:el.dataset.name,rejareja:+el.dataset.rejareja,jumla:+el.dataset.jumla,stock:+el.dataset.stock,unit:el.dataset.unit,qty:1});
  }
  koroshoRenderCart();
  showToast(el.dataset.name+' added!','info');
}

function koroshoChangeQty(id, delta){
  const item = koroshoCart.find(x=>x.id===id);
  if(!item) return;
  item.qty += delta;
  if(item.qty<=0) koroshoCart = koroshoCart.filter(x=>x.id!==id);
  koroshoRenderCart();
}

function koroshoRenderCart(){
  const el = document.getElementById('korosho-cart');
  if(!koroshoCart.length){el.innerHTML='<div class="cart-empty">No items yet<br/><small>Click a product to add</small></div>';koroshoUpdateTotals();return;}
  el.innerHTML = koroshoCart.map(item=>`
    <div class="cart-item">
      <div class="cart-item-name">${item.name}<small>${koroshoFmt(item[koroshoPriceType])} each</small></div>
      <div class="cart-qty">
        <button class="qty-btn" onclick="koroshoChangeQty('${item.id}',-1)">−</button>
        <span class="qty-val">${item.qty}</span>
        <button class="qty-btn" onclick="koroshoChangeQty('${item.id}',1)">+</button>
      </div>
      <div class="cart-item-price">${koroshoFmt(item[koroshoPriceType]*item.qty)}</div>
    </div>`).join('');
  koroshoUpdateTotals();
}

function koroshoSetPriceType(type){
  koroshoPriceType = type;
  document.getElementById('korosho-btn-rejareja').classList.toggle('active', type==='rejareja');
  document.getElementById('korosho-btn-jumla').classList.toggle('active', type==='jumla');
  koroshoRenderCart();
}

function koroshoSetPayMethod(m){
  koroshoPayMethod = m;
  ['cash','lipa','bank'].forEach(x=>document.getElementById('korosho-pay-'+x).classList.toggle('active',x===m));
}

function koroshoGetSubtotal(){ return koroshoCart.reduce((s,i)=>s+i[koroshoPriceType]*i.qty,0); }
function koroshoGetDiscount(){
  const sub = koroshoGetSubtotal();
  let d = +document.getElementById('korosho-discount').value || 0;
  if(d<0) d = 0;
  if(d>sub) d = sub;
  return d;
}
function koroshoGetTotal(){ return koroshoGetSubtotal() - koroshoGetDiscount(); }

function koroshoUpdateTotals(){
  document.getElementById('korosho-subtotal').textContent = koroshoFmt(koroshoGetSubtotal());
  document.getElementById('korosho-total').textContent = koroshoFmt(koroshoGetTotal());
}

function koroshoProcessSale(){
  if(!koroshoCart.length){showToast('Cart is empty!','error');return;}
  const sub = koroshoGetSubtotal();
  const discount = koroshoGetDiscount();
  const total = koroshoGetTotal();
  const customerId = document.getElementById('korosho-customer-select').value;
  const customerName = document.getElementById('korosho-customer-select').selectedOptions[0].text;
  const salesRepId = document.getElementById('korosho-sales-rep-select').value;
  const salesRepName = salesRepId ? document.getElementById('korosho-sales-rep-select').selectedOptions[0].text : '';
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

  fetch('api/korosho_sales.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},
    body: JSON.stringify({cart:koroshoCart,priceType:koroshoPriceType,payMethod:koroshoPayMethod,discount,total,sub,customerId,customerName:customerId?customerName:'Walk-in',salesRepId})
  })
  .then(r=>r.json())
  .then(data=>{
    if(data.success){
      koroshoShowReceipt(data.receipt_number, total, sub, discount, customerId?customerName:'Walk-in', salesRepName);
      koroshoCart=[];
      document.getElementById('korosho-discount').value = 0;
      document.getElementById('korosho-customer-select').value = '';
      document.getElementById('korosho-customer-search').value = '';
      document.getElementById('korosho-sales-rep-select').value = '';
      koroshoRenderCart();
    } else showToast(data.message||'Error!','error');
  }).catch(()=>showToast('Connection error!','error'));
}

function koroshoShowReceipt(rno, total, sub, discount, customerName, salesRepName){
  const date = new Date().toLocaleString('en-TZ');
  const items = koroshoCart.map(i=>`<div class="receipt-row"><span>${i.name} x${i.qty}</span><span>${koroshoFmt(i[koroshoPriceType]*i.qty)}</span></div>`).join('');
  const discountLine = discount>0 ? `<div class="receipt-row"><span>Discount:</span><span>-${koroshoFmt(discount)}</span></div>` : '';
  const repLine = salesRepName ? `<div class="receipt-row"><span>Sales Rep:</span><span>${salesRepName}</span></div>` : '';
  document.getElementById('korosho-receipt-content').innerHTML = `
    <div class="receipt-box">
      <div class="receipt-header">
        <div class="receipt-company">Z-SERIES PRODUCTS — KOROSHO</div>
        <div class="receipt-sub">+255 755 059 387</div>
      </div>
      <div class="receipt-row"><span>Receipt:</span><span>${rno}</span></div>
      <div class="receipt-row"><span>Date:</span><span>${date}</span></div>
      <div class="receipt-row"><span>Customer:</span><span>${customerName}</span></div>
      ${repLine}
      <div class="receipt-row"><span>Payment:</span><span>${koroshoPayMethod.toUpperCase()}</span></div>
      <div class="receipt-row"><span>Type:</span><span>${koroshoPriceType.toUpperCase()}</span></div>
      <hr class="receipt-divider"/>
      ${items}
      <hr class="receipt-divider"/>
      <div class="receipt-row"><span>Subtotal:</span><span>${koroshoFmt(sub)}</span></div>
      ${discountLine}
      <div class="receipt-row receipt-total"><span>TOTAL:</span><span>${koroshoFmt(total)}</span></div>
      <div class="receipt-footer"><div>Thank you for your business!</div><div>Z-Series Products © 2026</div></div>
    </div>`;
  openModal('korosho-receipt-modal');
}

document.addEventListener('DOMContentLoaded', koroshoRenderCart);
</script>
JS;
require_once 'includes/footer.php';
?>
