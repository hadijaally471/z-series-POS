<?php
$page_title = 'Point of Sale';
$content_class = 'content pos-content';
require_once 'includes/header.php';
requirePrivilege('pos');
$categories = $conn->query("SELECT * FROM categories ORDER BY name");
$products_result = $conn->query("SELECT p.*, c.name as cat_name FROM products p LEFT JOIN categories c ON p.category_id=c.id WHERE p.status='active' ORDER BY c.name, p.name");
$products = [];
while ($r = $products_result->fetch_assoc()) $products[] = $r;
$customers_result = $conn->query("SELECT * FROM customers WHERE status = 'active' ORDER BY name");
$client_categories = ['wholesale' => 'Wholesale', 'retail' => 'Retail'];
$sales_reps = $conn->query("SELECT id, name, role FROM employees WHERE status='active' AND role='Sales Rep' ORDER BY name");
$business_address = getSetting($conn,'business_address');
$cities_list = ['Arusha', 'Dar es Salaam', 'Dodoma', 'Kilimanjaro', 'Mbeya', 'Moshi', 'Mwanza', 'Iringa', 'Morogoro', 'Tanga', 'Kigoma', 'Singida', 'Tabora'];
?>
<div class="pos-layout">
  <!-- LEFT: Products -->
  <div class="pos-left">
    <input type="text" class="pos-search" id="product-search" placeholder="Search products by name..." oninput="filterProducts(this.value)"/>
    <div class="pos-cats">
      <select class="pos-cat-select" id="pos-cat-select" onchange="setCatSelect(this.value)">
        <option value="all">All Categories</option>
        <?php $categories->data_seek(0); while($cat = $categories->fetch_assoc()): ?>
        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="pos-products" id="pos-products-grid">
      <?php foreach($products as $p): ?>
      <div class="pos-product <?= $p['stock']==0?'out-of-stock':'' ?>" 
           data-id="<?= $p['id'] ?>" 
           data-cat="<?= $p['category_id'] ?>"
           data-name="<?= htmlspecialchars($p['name']) ?>"
           data-cat-name="<?= htmlspecialchars($p['cat_name']) ?>"
           data-rejareja="<?= $p['rejareja_price'] ?>"
           data-jumla="<?= $p['jumla_price'] ?>"
           data-stock="<?= $p['stock'] ?>"
           data-unit="<?= $p['unit'] ?>"
           onclick="addToCart(this)">
        <div class="pos-product-name"><?= htmlspecialchars($p['name']) ?></div>
        <div class="pos-product-cat"><?= htmlspecialchars($p['cat_name']) ?></div>
        <div class="pos-product-prices">
          <div class="pos-product-price">Rejareja: <span><?= tzs($p['rejareja_price']) ?></span></div>
          <div class="pos-product-price">Jumla: <span><?= tzs($p['jumla_price']) ?></span></div>
        </div>
        <div class="pos-product-stock <?= $p['stock']==0?'out':($p['stock']<=$p['low_stock_threshold']?'low':'') ?>">
          <?= $p['stock']==0 ? 'Out of stock' : 'Stock: '.$p['stock'].' '.unitLabel($p['unit']) ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- RIGHT: Cart -->
  <div class="pos-right">
    <div class="pos-right-header">
      <div class="pos-right-title">Current Sale</div>
      <div style="font-size:11px;color:var(--text3);margin-top:2px" id="receipt-num">RCP-<?= str_pad(rand(100,999), 4, '0', STR_PAD_LEFT) ?></div>
    </div>
    
    <!-- Customer select -->
    <div style="padding:10px 12px 0">
      <div style="position:relative">
        <input type="text" class="form-control" id="customer-search" style="font-size:12px" placeholder="Walk-in Customer — search to select..." autocomplete="off"/>
        <div id="customer-search-results" style="display:none;position:absolute;z-index:20;top:100%;left:0;right:0;max-height:220px;overflow-y:auto;background:var(--card-bg);border:1px solid var(--border);border-radius:8px;margin-top:4px;box-shadow:0 8px 20px rgba(0,0,0,0.35)"></div>
      </div>
      <select id="customer-select" style="display:none">
        <option value="">Walk-in Customer</option>
        <?php $customers_result->data_seek(0); while($c = $customers_result->fetch_assoc()): ?>
        <option value="<?= $c['id'] ?>" data-location="<?= htmlspecialchars($c['location'] ?? '') ?>"><?= htmlspecialchars($c['name']) ?><?= $c['category'] ? ' ('.htmlspecialchars($client_categories[$c['category']]).')' : '' ?></option>
        <?php endwhile; ?>
      </select>
      <div style="margin-top:8px">
        <select class="form-control" id="customer-city" style="font-size:12px">
          <option value="">Choose Region</option>
          <?php foreach ($cities_list as $city): ?>
          <option value="<?= htmlspecialchars($city) ?>"><?= htmlspecialchars($city) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-top:8px">
        <select class="form-control" id="sales-rep-select" style="font-size:12px">
          <option value="">— Sales Rep (optional) —</option>
          <?php while($rep = $sales_reps->fetch_assoc()): ?>
          <option value="<?= $rep['id'] ?>"><?= htmlspecialchars($rep['name']) ?></option>
          <?php endwhile; ?>
        </select>
      </div>
    </div>

    <!-- Price type -->
    <div class="price-type-btns" style="padding:10px 12px 0">
      <button class="price-type-btn active" id="btn-rejareja" onclick="setPriceType('rejareja')">Rejareja (Retail)</button>
      <button class="price-type-btn" id="btn-jumla" onclick="setPriceType('jumla')">Jumla (Wholesale)</button>
    </div>

    <!-- Cart items -->
    <div class="pos-cart" id="pos-cart">
      <div class="cart-empty">No items yet<br/><small>Click a product to add</small></div>
    </div>

    <!-- Footer -->
    <div class="pos-footer">
      <div class="pos-totals">
        <div class="pos-total-row"><span>Subtotal</span><span id="cart-subtotal">TZS 0</span></div>
        <div class="pos-total-row"><span>Discount (TZS)</span><input type="number" id="discount-input" class="discount-input" value="0" min="0" oninput="updateTotals()"/></div>
        <div class="pos-total-row grand"><span>TOTAL</span><span id="cart-total">TZS 0</span></div>
      </div>
      <div class="pay-methods">
        <button class="pay-method active" id="pay-cash" onclick="setPayMethod('cash')">Cash</button>
        <button class="pay-method" id="pay-lipa" onclick="setPayMethod('lipa')">Lipa</button>
        <button class="pay-method" id="pay-bank" onclick="setPayMethod('bank')">Bank</button>
        <button class="pay-method" id="pay-debt" onclick="openDebtModal()">Debt</button>
      </div>
      <button class="pay-btn" onclick="processSale()">Complete Sale & Print</button>
    </div>
  </div>
</div>

<!-- Receipt Modal -->
<div class="modal-overlay" id="receipt-modal" data-dismiss="true">
  <div class="modal" style="width:380px">
    <div class="modal-header">
      <span class="modal-title">Receipt</span>
      <button class="modal-close" onclick="closeReceipt()">&times;</button>
    </div>
    <div class="modal-body" id="receipt-content"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeReceipt()">Close</button>
      <button class="btn btn-primary" onclick="printReceipt('#receipt-content .receipt-box')">Print</button>
    </div>
  </div>
</div>

<!-- Debt Details Modal -->
<div class="modal-overlay" id="debt-modal" data-dismiss="true">
  <div class="modal" style="width:380px">
    <div class="modal-header">
      <span class="modal-title">Debt Sale Details</span>
      <button class="modal-close" onclick="closeModal('debt-modal')">&times;</button>
    </div>
    <div class="modal-body">
      <div style="margin-bottom:14px;padding:14px;background:var(--bg3);border-radius:10px">
        <div style="font-size:13px;color:var(--text2)">Total Sale: <strong id="debt-total-display" style="color:var(--text)"></strong></div>
      </div>
      <div class="form-group"><label class="form-label">Amount Paid Now (TZS)</label><input type="number" id="debt-amount-paid" class="form-control" value="0" min="0" oninput="updateDebtBalance()"/></div>
      <div class="form-group"><label class="form-label">Remaining Balance</label><input type="text" id="debt-balance-display" class="form-control" readonly/></div>
      <div class="form-group"><label class="form-label">Due Date *</label><input type="date" id="debt-due-date" class="form-control"/></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" onclick="closeModal('debt-modal')">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="confirmDebtModal()">Confirm</button>
    </div>
  </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
let cart = [], priceType = 'rejareja', payMethod = 'cash', debtAmountPaid = 0, debtDueDate = '';
const businessAddress = %s;

function fmt(n){return 'TZS '+(+n).toLocaleString();}

function filterProducts(q){
  const cat = document.getElementById('pos-cat-select')?.value || 'all';
  document.querySelectorAll('.pos-product').forEach(p=>{
    const name = p.dataset.name.toLowerCase();
    const matchQ = name.includes(q.toLowerCase());
    const matchC = cat==='all'||p.dataset.cat===cat;
    p.style.display = (matchQ&&matchC)?'':'none';
  });
}

function setCatSelect(cat){
  const q = document.getElementById('product-search').value;
  document.querySelectorAll('.pos-product').forEach(p=>{
    const matchC = cat==='all'||p.dataset.cat===cat;
    const matchQ = p.dataset.name.toLowerCase().includes(q.toLowerCase());
    p.style.display=(matchC&&matchQ)?'':'none';
  });
}

function setPriceType(type){
  priceType = type;
  document.getElementById('btn-rejareja').classList.toggle('active', type==='rejareja');
  document.getElementById('btn-jumla').classList.toggle('active', type==='jumla');
  renderCart();
}

function setPayMethod(m){
  payMethod = m;
  ['cash','lipa','bank','debt'].forEach(x=>document.getElementById('pay-'+x).classList.toggle('active',x===m));
}

function openDebtModal(){
  if(!cart.length){showToast('Cart is empty!','error');return;}
  if(!document.getElementById('customer-select').value){showToast('Select a customer for debt sales','error');return;}
  const total = getTotal();
  document.getElementById('debt-total-display').textContent = fmt(total);
  document.getElementById('debt-amount-paid').value = 0;
  document.getElementById('debt-amount-paid').max = total;
  document.getElementById('debt-balance-display').value = fmt(total);
  const d = new Date(); d.setDate(d.getDate()+30);
  document.getElementById('debt-due-date').value = d.toISOString().slice(0,10);
  openModal('debt-modal');
}

function updateDebtBalance(){
  const total = getTotal();
  let paid = +document.getElementById('debt-amount-paid').value || 0;
  if(paid<0) paid = 0;
  if(paid>total) paid = total;
  document.getElementById('debt-balance-display').value = fmt(total-paid);
}

function confirmDebtModal(){
  const total = getTotal();
  let paid = +document.getElementById('debt-amount-paid').value || 0;
  if(paid<0) paid = 0;
  if(paid>total) paid = total;
  const due = document.getElementById('debt-due-date').value;
  if(!due){showToast('Due date is required','error');return;}
  debtAmountPaid = paid;
  debtDueDate = due;
  setPayMethod('debt');
  closeModal('debt-modal');
}

document.getElementById('customer-select')?.addEventListener('change', (e) => {
  const selected = e.target.selectedOptions[0];
  const loc = selected?.dataset?.location || '';
  if (loc) {
    document.getElementById('customer-city').value = loc;
  }
});

// --- Customer search box: filters the hidden <select id="customer-select"> so all existing logic (change listener, .value, .selectedOptions[0].text, save/restore) keeps working unchanged ---
const customerOptions = Array.from(document.getElementById('customer-select').options).filter(o => o.value);

function selectCustomer(id, label){
  const sel = document.getElementById('customer-select');
  sel.value = id;
  sel.dispatchEvent(new Event('change'));
  document.getElementById('customer-search').value = label;
  document.getElementById('customer-search-results').style.display = 'none';
}

function renderCustomerResults(matches){
  const box = document.getElementById('customer-search-results');
  if(!matches.length){ box.style.display='none'; box.innerHTML=''; return; }
  box.innerHTML = matches.slice(0,50).map(o =>
    `<div class="customer-result-item" data-id="${o.value}" style="padding:8px 12px;cursor:pointer;font-size:12px;border-bottom:1px solid var(--border2)" onmousedown="selectCustomer('${o.value}', this.textContent)">${o.text}</div>`
  ).join('');
  box.style.display = 'block';
}

document.getElementById('customer-search')?.addEventListener('input', (e) => {
  const q = e.target.value.trim().toLowerCase();
  if(!q){ document.getElementById('customer-search-results').style.display='none'; return; }
  renderCustomerResults(customerOptions.filter(o => o.text.toLowerCase().includes(q)));
});

document.getElementById('customer-search')?.addEventListener('focus', (e) => {
  if(e.target.value.trim()) renderCustomerResults(customerOptions.filter(o => o.text.toLowerCase().includes(e.target.value.trim().toLowerCase())));
});

document.addEventListener('click', (e) => {
  if(!e.target.closest('#customer-search') && !e.target.closest('#customer-search-results')){
    document.getElementById('customer-search-results').style.display = 'none';
  }
});

function addToCart(el){
  if(el.classList.contains('out-of-stock')){showToast('Out of stock!','error');return;}
  const id = el.dataset.id;
  const existing = cart.find(x=>x.id===id);
  if(existing){
    if(existing.qty>=parseInt(el.dataset.stock)){showToast('Not enough stock!','error');return;}
    existing.qty++;
  } else {
    cart.push({id,name:el.dataset.name,catName:el.dataset.catName,rejareja:+el.dataset.rejareja,jumla:+el.dataset.jumla,stock:+el.dataset.stock,unit:el.dataset.unit,qty:1});
  }
  renderCart();
  showToast(el.dataset.name+' added!','info');
}

function changeQty(id, delta){
  const item = cart.find(x=>x.id===id);
  if(!item) return;
  item.qty += delta;
  if(item.qty<=0) cart = cart.filter(x=>x.id!==id);
  renderCart();
}

function renderCart(){
  const el = document.getElementById('pos-cart');
  if(!cart.length){el.innerHTML='<div class="cart-empty">No items yet<br/><small>Click a product to add</small></div>';updateTotals();return;}
  el.innerHTML = cart.map(item=>`
    <div class="cart-item">
      <div class="cart-item-name">${item.name}<small>${item.catName} • ${fmt(item[priceType])} each</small></div>
      <div class="cart-qty">
        <button class="qty-btn" onclick="changeQty('${item.id}',-1)">−</button>
        <span class="qty-val">${item.qty}</span>
        <button class="qty-btn" onclick="changeQty('${item.id}',1)">+</button>
      </div>
      <div class="cart-item-price">${fmt(item[priceType]*item.qty)}</div>
    </div>`).join('');
  updateTotals();
}

function getSubtotal(){
  return cart.reduce((s,i)=>s+i[priceType]*i.qty,0);
}

function getDiscount(){
  const sub = getSubtotal();
  let d = +document.getElementById('discount-input').value || 0;
  if(d<0) d = 0;
  if(d>sub) d = sub;
  return d;
}

function getTotal(){
  return getSubtotal() - getDiscount();
}

function updateTotals(){
  const sub = getSubtotal();
  const total = getTotal();
  document.getElementById('cart-subtotal').textContent = fmt(sub);
  document.getElementById('cart-total').textContent    = fmt(total);
}

function processSale(){
  if(!cart.length){showToast('Cart is empty!','error');return;}
  const sub = getSubtotal();
  const discount = getDiscount();
  const total = getTotal();
  const customerId = document.getElementById('customer-select').value;
  const customerName = document.getElementById('customer-select').selectedOptions[0].text;
  const customerCity = document.getElementById('customer-city').value;
  const salesRepId = document.getElementById('sales-rep-select').value;
  const salesRepName = salesRepId ? document.getElementById('sales-rep-select').selectedOptions[0].text : '';
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

  fetch('api/sales.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},
    body: JSON.stringify({cart,priceType,payMethod,discount,total,sub,customerId,customerName:customerId?customerName:'Walk-in',customerCity,salesRepId,debtAmountPaid:payMethod==='debt'?debtAmountPaid:0,debtDueDate:payMethod==='debt'?debtDueDate:''})
  })
  .then(r=>r.json())
  .then(data=>{
    if(data.success){
      showReceipt(data.receipt_number, customerName, total, sub, discount, salesRepName);
      cart=[];
      document.getElementById('discount-input').value = 0;
      renderCart();
      localStorage.removeItem(POS_STORAGE_KEY);
      document.getElementById('customer-select').value='';
      document.getElementById('customer-search').value='';
      document.getElementById('sales-rep-select').value='';
    } else showToast(data.message||'Error!','error');
  }).catch(()=>showToast('Connection error!','error'));
}

function showReceipt(rno, customer, total, sub, discount, salesRepName){
  const date = new Date().toLocaleString('en-TZ');
  const items = cart.map(i=>`<div class="receipt-row"><span>${i.name} x${i.qty}</span><span>${fmt(i[priceType]*i.qty)}</span></div>`).join('');
  const repLine = salesRepName ? `<div class="receipt-row"><span>Sales Rep:</span><span>${salesRepName}</span></div>` : '';
  const discountLine = discount>0 ? `<div class="receipt-row"><span>Discount:</span><span>-${fmt(discount)}</span></div>` : '';
  document.getElementById('receipt-content').innerHTML = `
    <div class="receipt-box">
      <div class="receipt-header">
        <div class="receipt-company">Z-SERIES PRODUCTS</div>
        <div class="receipt-sub">${businessAddress}</div>
        <div class="receipt-sub">+255 755 059 387</div>
      </div>
      <div class="receipt-row"><span>Receipt:</span><span>${rno}</span></div>
      <div class="receipt-row"><span>Date:</span><span>${date}</span></div>
      <div class="receipt-row"><span>Customer:</span><span>${customer}</span></div>
      ${repLine}
      <div class="receipt-row"><span>Payment:</span><span>${payMethod.toUpperCase()}</span></div>
      <div class="receipt-row"><span>Type:</span><span>${priceType.toUpperCase()}</span></div>
      <hr class="receipt-divider"/>
      ${items}
      <hr class="receipt-divider"/>
      <div class="receipt-row"><span>Subtotal:</span><span>${fmt(sub)}</span></div>
      ${discountLine}
      <div class="receipt-row receipt-total"><span>TOTAL:</span><span>${fmt(total)}</span></div>
      <div class="receipt-footer"><div>Thank you for your business!</div><div>Z-Series Products © 2026</div></div>
    </div>`;
  document.getElementById('receipt-modal').classList.add('show');
}

function closeReceipt(){document.getElementById('receipt-modal').classList.remove('show');}

// --- Cart persistence: save/load cart & settings to localStorage so refresh doesn't lose data ---
const POS_STORAGE_KEY = 'zseries_pos_state_v1';

function savePosState(){
  try{
    const state = {
      cart: cart,
      priceType: priceType,
      payMethod: payMethod,
      discount: document.getElementById('discount-input') ? document.getElementById('discount-input').value : 0,
      debtAmountPaid: debtAmountPaid,
      debtDueDate: debtDueDate,
      customer: document.getElementById('customer-select') ? document.getElementById('customer-select').value : '',
      salesRep: document.getElementById('sales-rep-select') ? document.getElementById('sales-rep-select').value : ''
    };
    localStorage.setItem(POS_STORAGE_KEY, JSON.stringify(state));
  }catch(e){console.error('savePosState failed', e);}
}

function loadPosState(){
  try{
    const raw = localStorage.getItem(POS_STORAGE_KEY);
    if(!raw) return;
    const s = JSON.parse(raw);
    if(Array.isArray(s.cart)) cart = s.cart;
    if(s.priceType) priceType = s.priceType;
    if(s.payMethod) payMethod = s.payMethod;
    if(document.getElementById('discount-input') && typeof s.discount !== 'undefined') document.getElementById('discount-input').value = s.discount;
    if(s.debtAmountPaid) debtAmountPaid = s.debtAmountPaid;
    if(s.debtDueDate) debtDueDate = s.debtDueDate;
    if(document.getElementById('customer-select') && typeof s.customer !== 'undefined'){
      document.getElementById('customer-select').value = s.customer;
      const opt = document.getElementById('customer-select').selectedOptions[0];
      document.getElementById('customer-search').value = (opt && opt.value) ? opt.text : '';
    }
    if(document.getElementById('sales-rep-select') && typeof s.salesRep !== 'undefined') document.getElementById('sales-rep-select').value = s.salesRep;
    if(priceType) {
      document.getElementById('btn-rejareja').classList.toggle('active', priceType==='rejareja');
      document.getElementById('btn-jumla').classList.toggle('active', priceType==='jumla');
    }
    ['cash','lipa','bank','debt'].forEach(x=>{ if(document.getElementById('pay-'+x)) document.getElementById('pay-'+x).classList.toggle('active', payMethod===x); });
  }catch(e){console.error('loadPosState failed', e);}
}

// persist on every cart render and on relevant changes
const orig_renderCart = renderCart;
renderCart = function(){ orig_renderCart(); savePosState(); };
const orig_setPriceType = setPriceType;
setPriceType = function(t){ orig_setPriceType(t); savePosState(); };
const orig_setPayMethod = setPayMethod;
setPayMethod = function(m){ orig_setPayMethod(m); savePosState(); };
const orig_changeQty = changeQty;
changeQty = function(id, delta){ orig_changeQty(id, delta); savePosState(); };
const orig_addToCart = addToCart;
addToCart = function(el){ orig_addToCart(el); savePosState(); };
const orig_updateTotals = updateTotals;
updateTotals = function(){ orig_updateTotals(); savePosState(); };

// initialize state from localStorage on page load
document.addEventListener('DOMContentLoaded', function(){ loadPosState(); renderCart(); });

// clear storage after successful sale
const orig_processSale = processSale;
processSale = function(){ orig_processSale(); };
</script>
JS;
$extra_js = sprintf($extra_js, json_encode($business_address, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
require_once 'includes/footer.php';
?>
