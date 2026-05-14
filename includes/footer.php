</div><!-- end content -->
</div><!-- end main -->
</div><!-- end layout -->

<!-- TOAST -->
<div id="toast" class="toast">
  <span class="toast-icon" id="toast-icon">✅</span>
  <span class="toast-msg" id="toast-msg">Done!</span>
</div>
<!-- GLOBAL CONFIRM MODAL -->
<div class="modal-overlay" id="global-confirm" aria-hidden="true" data-static="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="global-confirm-title">
    <div class="modal-header"><span class="modal-title" id="global-confirm-title">Please confirm</span><button class="modal-close" onclick="closeModal('global-confirm')">✕</button></div>
    <div class="modal-body" id="global-confirm-body">Are you sure?</div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" id="global-confirm-cancel">Cancel</button>
      <button type="button" class="btn btn-danger" id="global-confirm-ok">OK</button>
    </div>
  </div>
</div>

<script src="<?= htmlspecialchars(appPath('js/main.js'), ENT_QUOTES) ?>"></script>
<?php if(isset($extra_js)) echo $extra_js; ?>
</body>
</html>
