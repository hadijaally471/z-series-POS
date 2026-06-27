</div><!-- end content -->
</div><!-- end main -->
</div><!-- end layout -->

<!-- TOAST -->
<div id="toast" class="toast">
  <span class="toast-icon" id="toast-icon"></span>
  <span class="toast-msg" id="toast-msg">Done!</span>
</div>
<!-- GLOBAL CONFIRM MODAL -->
<div class="modal-overlay" id="global-confirm" aria-hidden="true" data-static="true">
  <div role="dialog" aria-modal="true" aria-labelledby="global-confirm-title" style="background:linear-gradient(180deg,rgba(26,20,48,0.99),rgba(19,16,30,0.99));border:1px solid rgba(124,58,237,0.22);border-radius:20px;width:360px;max-width:92vw;box-shadow:0 32px 64px rgba(0,0,0,0.45);padding:32px 28px 24px;text-align:center">
    <div style="width:60px;height:60px;border-radius:50%;background:rgba(239,68,68,0.12);border:1.5px solid rgba(239,68,68,0.25);display:flex;align-items:center;justify-content:center;font-size:26px;margin:0 auto 18px"></div>
    <div id="global-confirm-title" style="font-size:17px;font-weight:700;color:var(--text);font-family:'Syne',sans-serif;margin-bottom:8px">Are you sure?</div>
    <div id="global-confirm-body" style="font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:24px">This action cannot be undone.</div>
    <div style="display:flex;gap:10px;justify-content:center">
      <button type="button" id="global-confirm-cancel" class="btn btn-outline" style="min-width:110px">Cancel</button>
      <button type="button" id="global-confirm-ok" class="btn btn-danger" style="min-width:110px">Delete</button>
    </div>
  </div>
</div>

<script src="<?= htmlspecialchars(appPath('js/main.js'), ENT_QUOTES) ?>"></script>
<?php if(isset($extra_js)) echo $extra_js; ?>
</body>
</html>
