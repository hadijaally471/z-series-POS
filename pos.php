<?php
$page_title = 'Point of Sale';
$content_class = 'content pos-content';
require_once 'includes/header.php';
requirePrivilege('pos');
$categories = $conn->query("SELECT * FROM categories ORDER BY name");
$products_result = $conn->query("SELECT p.*, c.name as cat_name FROM products p LEFT JOIN categories c ON p.category_id=c.id WHERE p.status='active' ORDER BY c.name, p.name");
$products = [];
while ($r = $products_result->fetch_assoc()) $products[] = $r;
$customers_result = $conn->query("SELECT * FROM customers ORDER BY name");
?>
<div class="pos-layout">
  <!-- LEFT: Products -->
  <div class="pos-left">
    <input type="text" class="pos-search" id="product-search" placeholder="🔍  Search products by name..." oninput="filterProducts(this.value)"/>
    <div class="pos-cats">
      <button class="pos-cat active" onclick="setCat('all',this)">All</button>
      <?php $categories->data_seek(0); while($cat = $categories->fetch_assoc()): ?>
      <button class="pos-cat" onclick="setCat('<?= $cat['id'] ?>',this)"><?= htmlspecialchars($cat['name']) ?></button>
      <?php endwhile; ?>
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
          <?= $p['stock']==0 ? 'Out of stock' : 'Stock: '.$p['stock'].' '.$p['unit'] ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- RIGHT: Cart -->
  <div class="pos-right">
    <div class="pos-right-header">
      <div class="pos-right-title">🛒 Current Sale</div>
      <div style="font-size:11px;color:var(--text3);margin-top:2px" id="receipt-num">RCP-<?= str_pad(rand(100,999), 4, '0', STR_PAD_LEFT) ?></div>
    </div>
    
    <!-- Customer select -->
    <div style="padding:10px 12px 0">
      <select class="form-control" id="customer-select" style="font-size:12px">
        <option value="">Walk-in Customer</option>
        <?php $customers_result->data_seek(0); while($c = $customers_result->fetch_assoc()): ?>
        <option value="<?= $c['id'] ?>" data-type="<?= $c['type'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= ucfirst($c['type']) ?>)</option>
        <?php endwhile; ?>
      </select>
    </div>

    <!-- Price type -->
    <div class="price-type-btns" style="padding:10px 12px 0">
      <button class="price-type-btn active" id="btn-rejareja" onclick="setPriceType('rejareja')">Rejareja (Retail)</button>
      <button class="price-type-btn" id="btn-jumla" onclick="setPriceType('jumla')">Jumla (Wholesale)</button>
    </div>

    <!-- Cart items -->
    <div class="pos-cart" id="pos-cart">
      <div class="cart-empty">🛒<br/>No items yet<br/><small>Click a product to add</small></div>
    </div>

    <!-- Footer -->
    <div class="pos-footer">
      <!-- Discount -->
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px">
        <span style="font-size:11px;color:var(--text3);flex-shrink:0">Discount (TZS):</span>
        <input type="number" id="discount-input" class="form-control" style="flex:1;padding:6px 10px;font-size:12px" placeholder="0" value="0" oninput="updateTotals()"/>
      </div>
      <div class="pos-totals">
        <div class="pos-total-row"><span>Subtotal</span><span id="cart-subtotal">TZS 0</span></div>
        <div class="pos-total-row"><span>Discount</span><span id="cart-discount">TZS 0</span></div>
        <div class="pos-total-row grand"><span>TOTAL</span><span id="cart-total">TZS 0</span></div>
      </div>
      <div class="pay-methods">
        <button class="pay-method active" id="pay-cash" onclick="setPayMethod('cash')">💵 Cash</button>
        <button class="pay-method" id="pay-mpesa" onclick="setPayMethod('mpesa')">📱 M-Pesa</button>
        <button class="pay-method" id="pay-debt" onclick="setPayMethod('debt')">📒 Debt</button>
      </div>
      <button class="pay-btn" onclick="processSale()">Complete Sale & Print</button>
    </div>
  </div>
</div>

<!-- Receipt Modal -->
<div class="modal-overlay" id="receipt-modal" data-dismiss="true">
  <div class="modal" style="width:380px">
    <div class="modal-header">
      <span class="modal-title">🧾 Receipt</span>
      <button class="modal-close" onclick="closeReceipt()">✕</button>
    </div>
    <div class="modal-body" id="receipt-content"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeReceipt()">Close</button>
      <button class="btn btn-primary" onclick="printReceipt('#receipt-content .receipt-box')">🖨️ Print</button>
    </div>
  </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
let cart = [], priceType = 'rejareja', payMethod = 'cash';

function fmt(n){return 'TZS '+(+n).toLocaleString();}

function filterProducts(q){
  const cat = document.querySelector('.pos-cat.active').dataset.cat||'all';
  document.querySelectorAll('.pos-product').forEach(p=>{
    const name = p.dataset.name.toLowerCase();
    const matchQ = name.includes(q.toLowerCase());
    const matchC = cat==='all'||p.dataset.cat===cat;
    p.style.display = (matchQ&&matchC)?'':'none';
  });
}

function setCat(cat, el){
  document.querySelectorAll('.pos-cat').forEach(b=>b.classList.remove('active'));
  el.classList.add('active');
  el.dataset.cat = cat;
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
  ['cash','mpesa','debt'].forEach(x=>document.getElementById('pay-'+x).classList.toggle('active',x===m));
}

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
  if(!cart.length){el.innerHTML='<div class="cart-empty">🛒<br/>No items yet<br/><small>Click a product to add</small></div>';updateTotals();return;}
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

function updateTotals(){
  const sub = cart.reduce((s,i)=>s+i[priceType]*i.qty,0);
  const disc = Math.max(0,+document.getElementById('discount-input').value||0);
  const total = Math.max(0, sub-disc);
  document.getElementById('cart-subtotal').textContent = fmt(sub);
  document.getElementById('cart-discount').textContent  = fmt(disc);
  document.getElementById('cart-total').textContent    = fmt(total);
}

function processSale(){
  if(!cart.length){showToast('Cart is empty!','error');return;}
  const sub = cart.reduce((s,i)=>s+i[priceType]*i.qty,0);
  const disc = Math.max(0,+document.getElementById('discount-input').value||0);
  if(disc > sub){showToast('Discount cannot exceed subtotal.','error');return;}
  const total = Math.max(0,sub-disc);
  const customerId = document.getElementById('customer-select').value;
  const customerName = document.getElementById('customer-select').selectedOptions[0].text;
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

  fetch('api/sales.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},
    body: JSON.stringify({cart,priceType,payMethod,discount:disc,total,sub,customerId,customerName:customerId?customerName:'Walk-in'})
  })
  .then(r=>r.json())
  .then(data=>{
    if(data.success){
      showReceipt(data.receipt_number, customerName, total, disc, sub);
      cart=[];renderCart();
      document.getElementById('discount-input').value=0;
      document.getElementById('customer-select').value='';
    } else showToast(data.message||'Error!','error');
  }).catch(()=>showToast('Connection error!','error'));
}

function showReceipt(rno, customer, total, disc, sub){
  const date = new Date().toLocaleString('en-TZ');
  const items = cart.map(i=>`<div class="receipt-row"><span>${i.name} x${i.qty}</span><span>${fmt(i[priceType]*i.qty)}</span></div>`).join('');
  document.getElementById('receipt-content').innerHTML = `
    <div class="receipt-box">
      <div class="receipt-header">
        <div class="receipt-company">Z-SERIES PRODUCTS</div>
        <div class="receipt-sub">Dar es Salaam, Tanzania</div>
        <div class="receipt-sub">+255 755 059 387</div>
      </div>
      <div class="receipt-row"><span>Receipt:</span><span>${rno}</span></div>
      <div class="receipt-row"><span>Date:</span><span>${date}</span></div>
      <div class="receipt-row"><span>Customer:</span><span>${customer}</span></div>
      <div class="receipt-row"><span>Payment:</span><span>${payMethod.toUpperCase()}</span></div>
      <div class="receipt-row"><span>Type:</span><span>${priceType.toUpperCase()}</span></div>
      <hr class="receipt-divider"/>
      ${items}
      <hr class="receipt-divider"/>
      <div class="receipt-row"><span>Subtotal:</span><span>${fmt(sub)}</span></div>
      <div class="receipt-row"><span>Discount:</span><span>${fmt(disc)}</span></div>
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
      customer: document.getElementById('customer-select') ? document.getElementById('customer-select').value : ''
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
    if(document.getElementById('customer-select') && typeof s.customer !== 'undefined') document.getElementById('customer-select').value = s.customer;
    // apply UI for price type and pay method
    if(priceType) {
      document.getElementById('btn-rejareja').classList.toggle('active', priceType==='rejareja');
      document.getElementById('btn-jumla').classList.toggle('active', priceType==='jumla');
    }
    ['cash','mpesa','debt'].forEach(x=>{ if(document.getElementById('pay-'+x)) document.getElementById('pay-'+x).classList.toggle('active', payMethod===x); });
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

// initialize state from localStorage on page load
document.addEventListener('DOMContentLoaded', function(){ loadPosState(); renderCart(); });

// clear storage after successful sale
const orig_processSale = processSale;
processSale = function(){
  orig_processSale();
  // server response clears cart inside then(); but also remove storage here to be safe
  localStorage.removeItem(POS_STORAGE_KEY);
};
</script>
JS;
require_once 'includes/footer.php';
?>
