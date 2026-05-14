// Z-Series POS — Main JavaScript

// MODAL
function openModal(id){document.getElementById(id).classList.add('show')}
function closeModal(id){document.getElementById(id).classList.remove('show')}
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.modal-overlay').forEach(m=>{
    // Keep dialogs open on backdrop clicks so accidental clicks do not discard form work.
    m.addEventListener('click',function(e){
      if(e.target===this) e.stopPropagation();
    });
  });
});

// TOAST
function showToast(msg, type='info'){
  const t = document.getElementById('toast');
  if(!t) return;
  const icons = {success:'✅', error:'❌', info:'💜', warning:'⚠️'};
  t.className = 'toast ' + type;
  document.getElementById('toast-icon').textContent = icons[type]||'💜';
  document.getElementById('toast-msg').textContent = msg;
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'), 2000);
}

function printReceipt(selector){
  const source = document.querySelector(selector);
  if(!source) return;

  const receiptHtml = source.outerHTML;
  const receiptStyles = `
    :root{color-scheme:light}
    *{box-sizing:border-box}
    body{margin:0;padding:24px;background:#f3f4f6;color:#111827;font-family:Arial,Helvetica,sans-serif}
    .receipt-box{background:#fff;color:#111;padding:24px;max-width:340px;margin:0 auto;font-size:12px;border-radius:12px;border:1px solid #e5e7eb;box-shadow:0 10px 30px rgba(0,0,0,0.08)}
    .receipt-header{text-align:center;margin-bottom:12px;padding-bottom:12px;border-bottom:1px dashed #d1d5db}
    .receipt-company{font-size:16px;font-weight:700;margin-bottom:4px}
    .receipt-sub{font-size:10px;color:#6b7280}
    .receipt-divider{border:none;border-top:1px dashed #d1d5db;margin:8px 0}
    .receipt-row{display:flex;justify-content:space-between;gap:12px;margin-bottom:4px}
    .receipt-total{font-weight:700;font-size:14px;border-top:2px solid #111;padding-top:8px;margin-top:8px}
    .receipt-footer{text-align:center;margin-top:12px;font-size:10px;color:#6b7280}
    .receipt-open-link{display:inline-flex;margin-top:6px;padding:5px 10px;font-size:10px;line-height:1.2}
    @page{margin:10mm}
  `;

  const printWindow = window.open('', '_blank', 'width=420,height=760');
  if(!printWindow) return;

  printWindow.document.open();
  printWindow.document.write(`
    <!doctype html>
    <html>
      <head>
        <title>Receipt</title>
        <style>${receiptStyles}</style>
      </head>
      <body>
        ${receiptHtml}
        <script>
          window.onload = function(){
            window.focus();
            window.print();
          };
          window.onafterprint = function(){ window.close(); };
        <\/script>
      </body>
    </html>
  `);
  printWindow.document.close();
}

function appUrl(path){
  const base = document.querySelector('meta[name="app-base-url"]')?.content || '';
  return `${base}/${String(path || '').replace(/^\/+/, '')}`;
}

// Confirm delete
document.addEventListener('DOMContentLoaded',function(){
  // keep existing behavior for forms but prefer custom modal when available
  document.querySelectorAll('form[data-confirm]').forEach(f=>{
    f.addEventListener('submit',function(e){
      const msg = this.dataset.confirm || this.getAttribute('data-confirm') || 'Are you sure?';
      // if global confirm modal exists, use it
      if (typeof showConfirm === 'function') {
        e.preventDefault();
        showConfirm(msg, ()=>{ this.submit(); });
      } else {
        if(!confirm(msg)){e.preventDefault();}
      }
    });
  });

  // intercept links and buttons with data-confirm to show custom modal
  document.querySelectorAll('a[data-confirm], button[data-confirm]').forEach(el=>{
    el.addEventListener('click', function(e){
      const href = this.getAttribute('href');
      const msg = this.dataset.confirm || 'Are you sure?';
      if (typeof showConfirm === 'function') {
        e.preventDefault();
        showConfirm(msg, ()=>{
          if (href) window.location.href = href;
          else if (this.type === 'button' && this.form) this.form.submit();
        });
      } else {
        if(!confirm(msg)) e.preventDefault();
      }
    });
  });
});

// Global custom confirm modal helper
function showConfirm(message, onOk){
  const modal = document.getElementById('global-confirm');
  if(!modal) {
    // fallback to native
    if (confirm(message)) onOk();
    return;
  }
  const body = document.getElementById('global-confirm-body');
  const okBtn = document.getElementById('global-confirm-ok');
  const cancelBtn = document.getElementById('global-confirm-cancel');
  body.textContent = message;
  openModal('global-confirm');
  function cleanup(){
    okBtn.removeEventListener('click', okHandler);
    cancelBtn.removeEventListener('click', cancelHandler);
    closeModal('global-confirm');
  }
  function okHandler(){ cleanup(); if (typeof onOk === 'function') onOk(); }
  function cancelHandler(){ cleanup(); }
  okBtn.addEventListener('click', okHandler);
  cancelBtn.addEventListener('click', cancelHandler);
}

// Auto-dismiss alerts after 4 seconds
document.addEventListener('DOMContentLoaded',function(){
  const alerts = document.querySelectorAll('.alert-auto');
  alerts.forEach(a=>{setTimeout(()=>a.style.display='none',4000);});
});

// Format number input with commas on blur
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('input[data-format="currency"]').forEach(inp=>{
    inp.addEventListener('blur',function(){
      if(this.value) this.value = parseFloat(this.value)||0;
    });
  });
});

// Notifications: fetch real business notifications from server + localStorage fallback
const NOTIF_KEY = 'zseries_notifications_v1';
const NOTIF_CACHE_KEY = 'zseries_notif_cache_v1';
const NOTIF_REFRESH_INTERVAL = 30000; // 30 seconds

let notificationRefreshTimer = null;

function loadNotifications(){
  try{ 
    const raw = localStorage.getItem(NOTIF_CACHE_KEY); 
    return raw?JSON.parse(raw):[]; 
  }catch(e){return [];}
}

function saveNotifications(list){
  try{ 
    localStorage.setItem(NOTIF_CACHE_KEY, JSON.stringify(list)); 
  }catch(e){console.error('saveNotifications',e);} 
}

function fetchNotificationsFromServer(){
  return fetch(appUrl('api/notifications.php'))
    .then(r=> r.ok ? r.json() : {notifications:[]})
    .then(data=> {
      if(data.notifications && Array.isArray(data.notifications)){
        saveNotifications(data.notifications);
        renderNotifications();
      }
    })
    .catch(e=>{ console.error('fetchNotificationsFromServer', e); });
}

function renderNotifications(){
  const list = loadNotifications();
  const container = document.getElementById('notif-list');
  const badge = document.getElementById('notif-badge');
  if(!container) return;
  if(!list.length){ container.innerHTML = '<div class="notif-empty">No notifications</div>'; if(badge) badge.style.display='none'; return; }
  container.innerHTML = list.map(n=>{
    const priorityClass = n.priority ? ' notif-'+n.priority : '';
    return `<div class="notif-item${priorityClass}" data-id="${n.id}" data-link="${n.link||''}">
      <div class="notif-item-title">${escapeHtml(n.title||'')}</div>
      <div class="notif-item-body">${escapeHtml(n.body||'')}</div>
      <div class="notif-item-ts">${n.ts?new Date(n.ts).toLocaleString() : 'Just now'}</div>
    </div>`;
  }).join('');
  const unreadCount = list.length;
  if(badge){ 
    if(unreadCount>0){ 
      badge.style.display='inline-block'; 
      badge.textContent = unreadCount; 
    } else badge.style.display='none'; 
  }
}
function addNotification(notif){
  const list = loadNotifications();
  const id = Date.now()+Math.floor(Math.random()*1000);
  const n = Object.assign({id:id, ts: Date.now()}, notif);
  list.unshift(n);
  saveNotifications(list);
  renderNotifications();
  showToast(n.title||'New notification','info');
}
function markAllRead(){
  // Since we fetch from server, we just clear cache and re-fetch
  localStorage.removeItem(NOTIF_CACHE_KEY);
  fetchNotificationsFromServer();
}
function clearNotifications(){
  localStorage.removeItem(NOTIF_CACHE_KEY);
  renderNotifications();
}
function escapeHtml(s){ return (s+'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

document.addEventListener('DOMContentLoaded', function(){
  // Fetch notifications from server on page load
  fetchNotificationsFromServer();
  
  // Set up auto-refresh (poll server every 30 seconds)
  notificationRefreshTimer = setInterval(fetchNotificationsFromServer, NOTIF_REFRESH_INTERVAL);
  
  const btn = document.getElementById('notif-btn');
  const panel = document.getElementById('notif-panel');
  if(btn && panel){
    btn.addEventListener('click', function(e){
      e.preventDefault();
      const open = panel.classList.toggle('show');
      panel.style.display = open ? 'block' : 'none';
      btn.setAttribute('aria-expanded', open?'true':'false');
      // refresh when panel opens
      if(open) fetchNotificationsFromServer();
    });
    
    // click on item to navigate
    const listEl = document.getElementById('notif-list');
    if(listEl){
      listEl.addEventListener('click', function(e){
        const item = e.target.closest('.notif-item');
        if(!item) return;
        const link = item.dataset.link;
        if(link){ window.location.href = link; }
      });
    }
    
    const markBtn = document.getElementById('notif-mark-read');
    if(markBtn) markBtn.addEventListener('click', function(){ markAllRead(); });
    
    const clearBtn = document.getElementById('notif-clear');
    if(clearBtn) clearBtn.addEventListener('click', function(){ if(confirm('Clear all notifications?')) clearNotifications(); });
    
    // close panel when clicking outside
    document.addEventListener('click', function(e){ 
      if(!btn.contains(e.target) && !panel.contains(e.target)) { 
        panel.classList.remove('show');
        panel.style.display='none'; 
      }
    });
  }
});
