<?php
$page_title = 'Settings';
require_once 'includes/header.php';
requirePrivilege('settings');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action']??'';
    if ($action === 'settings') {
        $keys = ['business_name','business_phone','business_address','business_email','tin_number','currency','low_stock_threshold','tax_rate','receipt_footer'];
        $stmt = $conn->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach($keys as $key) {
            if(isset($_POST[$key])) {
                $val = sanitizeString($_POST[$key], 1000);
                $stmt->bind_param('ss', $key, $val);
                $stmt->execute();
            }
        }
        logActivity($conn,'System settings updated','system');
        $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">✅ Settings saved!</div>';
    }
    if ($action === 'password') {
        $current = $_POST['current_password'];
        $new_pass = $_POST['new_password'];
        $uid = $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (password_verify($current, $user['password'])) {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param('si', $hash, $uid);
            $stmt->execute();
            logActivity($conn,'Password changed','system');
            $msg='<div style="color:var(--success);padding:10px;background:rgba(16,185,129,0.1);border-radius:8px;margin-bottom:14px">✅ Password updated!</div>';
        } else {
            $msg='<div style="color:var(--danger);padding:10px;background:rgba(239,68,68,0.1);border-radius:8px;margin-bottom:14px">❌ Current password is incorrect!</div>';
        }
    }
}
function gs($conn,$k){return htmlspecialchars(getSetting($conn,$k));}
?>
<?=$msg?>
<div class="grid-2">
<div class="card"><div class="card-header"><span class="card-title">Business Information</span></div>
<div class="card-body">
<form method="POST"><input type="hidden" name="action" value="settings"><?= csrfInput() ?>
<div class="form-row"><div class="form-group"><label class="form-label">Business Name</label><input name="business_name" class="form-control" value="<?=gs($conn,'business_name')?>"/></div><div class="form-group"><label class="form-label">Phone</label><input name="business_phone" class="form-control" value="<?=gs($conn,'business_phone')?>"/></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Email</label><input name="business_email" class="form-control" value="<?=gs($conn,'business_email')?>"/></div><div class="form-group"><label class="form-label">TIN Number</label><input name="tin_number" class="form-control" value="<?=gs($conn,'tin_number')?>"/></div></div>
<div class="form-group"><label class="form-label">Address</label><input name="business_address" class="form-control" value="<?=gs($conn,'business_address')?>"/></div>
<div class="form-row"><div class="form-group"><label class="form-label">Currency</label><select name="currency" class="form-control"><option value="TZS" <?=gs($conn,'currency')==='TZS'?'selected':''?>>TZS — Tanzanian Shilling</option><option value="USD">USD</option></select></div><div class="form-group"><label class="form-label">Tax Rate (%)</label><input type="number" name="tax_rate" class="form-control" value="<?=gs($conn,'tax_rate')?>"/></div></div>
<div class="form-row"><div class="form-group"><label class="form-label">Low Stock Threshold</label><input type="number" name="low_stock_threshold" class="form-control" value="<?=gs($conn,'low_stock_threshold')?>"/></div><div class="form-group"><label class="form-label">Receipt Footer</label><input name="receipt_footer" class="form-control" value="<?=gs($conn,'receipt_footer')?>"/></div></div>
<button type="submit" class="btn btn-primary">💾 Save Settings</button>
</form></div></div>

<div><div class="card"><div class="card-header"><span class="card-title">Change Password</span></div>
<div class="card-body"><form method="POST"><input type="hidden" name="action" value="password"><?= csrfInput() ?>
<div class="form-group"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" required/></div>
<div class="form-group"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" required/></div>
<button type="submit" class="btn btn-primary">Update Password</button>
</form></div></div>

<div class="card" style="margin-top:0"><div class="card-header"><span class="card-title">System Info</span></div>
<div class="card-body">
<div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0;border-bottom:1px solid var(--border2)"><span style="color:var(--text3)">System Version</span><span>Z-Series POS v1.0</span></div>
<div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0;border-bottom:1px solid var(--border2)"><span style="color:var(--text3)">PHP Version</span><span><?=phpversion()?></span></div>
<div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0;border-bottom:1px solid var(--border2)"><span style="color:var(--text3)">Database</span><span>MySQL / MariaDB</span></div>
<div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0"><span style="color:var(--text3)">Developed By</span><span>Kitaa Digital Solutions</span></div>
</div></div></div>
</div>
<?php require_once 'includes/footer.php'; ?>
