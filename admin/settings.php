<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) redirect('../index.php');
$notice = $error = '';
$modules = ['invoices'=>'Invoices & payments','contacts'=>'Customers & vendors','items'=>'Products & services','expenses'=>'Expenses','payables'=>'Accounts payable','loans'=>'Loans','investments'=>'Investments','payroll'=>'HR & payroll','journal'=>'General ledger','cashflow'=>'Cash flow','reports'=>'Reports'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (isset($_POST['save_company'])) {
            foreach (['company_name','company_email','company_phone','company_address','company_tax_id','base_currency','currency_symbol','fiscal_year_start','date_format','theme_color','invoice_prefix','invoice_next_number','invoice_terms'] as $key) {
                set_setting($pdo, $key, trim((string)($_POST[$key] ?? '')));
            }
            $notice = 'Company and invoice preferences saved.';
        } elseif (isset($_POST['save_modules'])) {
            foreach ($modules as $key => $_) set_setting($pdo, 'module_'.$key, isset($_POST['module_'.$key]) ? '1' : '0');
            $notice = 'Module visibility updated.';
        } elseif (isset($_POST['save_profile'])) {
            $fullName = trim((string)$_POST['full_name']); $email = trim((string)$_POST['email']);
            $stmt = $pdo->prepare('UPDATE users SET full_name=?, email=? WHERE id=?'); $stmt->execute([$fullName,$email,$_SESSION['user_id']]);
            $_SESSION['full_name'] = $fullName; $notice = 'Profile updated.';
        } elseif (isset($_POST['change_password'])) {
            $stmt=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$stmt->execute([$_SESSION['user_id']]);$hash=$stmt->fetchColumn();
            if (!password_verify((string)$_POST['current_password'],$hash)) throw new RuntimeException('Current password is incorrect.');
            if (strlen((string)$_POST['new_password']) < 8) throw new RuntimeException('New password must be at least 8 characters.');
            if ($_POST['new_password'] !== $_POST['confirm_password']) throw new RuntimeException('New passwords do not match.');
            $stmt=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');$stmt->execute([password_hash((string)$_POST['new_password'],PASSWORD_DEFAULT),$_SESSION['user_id']]);
            $notice='Password changed successfully.';
        } elseif (isset($_POST['add_tax'])) {
            $stmt=$pdo->prepare('INSERT INTO tax_rates(name,rate_percentage) VALUES(?,?)');$stmt->execute([trim((string)$_POST['tax_name']),(float)$_POST['tax_rate']]);$notice='Tax rate added.';
        }
    } catch (Throwable $e) { $error=$e->getMessage(); }
}
$stmt=$pdo->prepare('SELECT full_name,email,username,role FROM users WHERE id=?');$stmt->execute([$_SESSION['user_id']]);$profile=$stmt->fetch();
$currencies=$pdo->query('SELECT * FROM currencies ORDER BY is_base_currency DESC,name')->fetchAll();
$taxes=$pdo->query('SELECT * FROM tax_rates ORDER BY rate_percentage')->fetchAll();
require_once 'includes/header.php'; require_once 'includes/sidebar.php';
?>
<main class="flex-1 h-screen overflow-y-auto app-scroll">
 <header class="bg-white border-b px-5 md:px-8 py-5 flex items-center gap-4 sticky top-0 z-10"><button data-sidebar-toggle class="lg:hidden text-2xl">☰</button><div><h2 class="text-2xl font-bold">Settings</h2><p class="text-sm text-slate-500">Customize the system for your business</p></div></header>
 <div class="p-5 md:p-8 max-w-6xl mx-auto space-y-7">
  <?php if($notice): ?><div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-xl"><?=h($notice)?></div><?php endif;?>
  <?php if($error): ?><div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-xl"><?=h($error)?></div><?php endif;?>
  <form method="post" class="bg-white rounded-2xl border shadow-sm p-6"><?=csrf_field()?>
   <div class="mb-6"><h3 class="text-lg font-bold">Company & document preferences</h3><p class="text-sm text-slate-500">These values appear throughout the app and on invoices.</p></div>
   <div class="grid md:grid-cols-2 gap-5">
    <?php foreach(['company_name'=>'Company name','company_email'=>'Company email','company_phone'=>'Phone','company_tax_id'=>'Tax / registration ID','invoice_prefix'=>'Invoice prefix','invoice_next_number'=>'Next invoice number','fiscal_year_start'=>'Fiscal year start (MM-DD)','date_format'=>'Date format'] as $key=>$label): ?>
     <label class="text-sm font-medium"><?=h($label)?><input name="<?=h($key)?>" value="<?=h(setting($pdo,$key))?>" class="mt-1 w-full border rounded-lg px-3 py-2.5"></label>
    <?php endforeach;?>
    <label class="text-sm font-medium">Base currency<select name="base_currency" class="mt-1 w-full border rounded-lg px-3 py-2.5 bg-white"><?php foreach($currencies as $c):?><option value="<?=h($c['code'])?>" <?=setting($pdo,'base_currency')===$c['code']?'selected':''?>><?=h($c['code'].' — '.$c['name'])?></option><?php endforeach;?></select></label>
    <label class="text-sm font-medium">Currency symbol<input name="currency_symbol" value="<?=h(setting($pdo,'currency_symbol','$'))?>" class="mt-1 w-full border rounded-lg px-3 py-2.5"></label>
    <label class="text-sm font-medium">Brand color<input type="color" name="theme_color" value="<?=h(setting($pdo,'theme_color','#2563eb'))?>" class="mt-1 w-full h-11 border rounded-lg p-1"></label>
    <label class="text-sm font-medium md:col-span-2">Company address<textarea name="company_address" rows="2" class="mt-1 w-full border rounded-lg px-3 py-2.5"><?=h(setting($pdo,'company_address'))?></textarea></label>
    <label class="text-sm font-medium md:col-span-2">Default invoice terms<textarea name="invoice_terms" rows="2" class="mt-1 w-full border rounded-lg px-3 py-2.5"><?=h(setting($pdo,'invoice_terms'))?></textarea></label>
   </div><button name="save_company" class="brand-bg text-white px-5 py-2.5 rounded-lg font-semibold mt-5">Save company settings</button>
  </form>
  <form method="post" class="bg-white rounded-2xl border shadow-sm p-6"><?=csrf_field()?>
   <h3 class="text-lg font-bold">App modules</h3><p class="text-sm text-slate-500 mb-5">Turn features on or off in navigation for a simpler workspace.</p>
   <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3"><?php foreach($modules as $key=>$label):?><label class="flex items-center justify-between border rounded-xl p-4 cursor-pointer"><span class="font-medium"><?=h($label)?></span><input type="checkbox" name="module_<?=h($key)?>" value="1" <?=module_enabled($pdo,$key)?'checked':''?> class="w-5 h-5"></label><?php endforeach;?></div>
   <button name="save_modules" class="brand-bg text-white px-5 py-2.5 rounded-lg font-semibold mt-5">Save module selection</button>
  </form>
  <div class="grid lg:grid-cols-2 gap-7">
   <form method="post" class="bg-white rounded-2xl border shadow-sm p-6 space-y-4"><?=csrf_field()?><h3 class="text-lg font-bold">Profile</h3>
    <label class="block text-sm font-medium">Username<input disabled value="<?=h($profile['username'])?>" class="mt-1 w-full bg-slate-100 border rounded-lg px-3 py-2.5"></label>
    <label class="block text-sm font-medium">Full name<input name="full_name" value="<?=h($profile['full_name'])?>" class="mt-1 w-full border rounded-lg px-3 py-2.5"></label>
    <label class="block text-sm font-medium">Email<input type="email" name="email" value="<?=h($profile['email'])?>" class="mt-1 w-full border rounded-lg px-3 py-2.5"></label>
    <button name="save_profile" class="brand-bg text-white px-5 py-2.5 rounded-lg font-semibold">Update profile</button>
   </form>
   <form method="post" class="bg-white rounded-2xl border shadow-sm p-6 space-y-4"><?=csrf_field()?><h3 class="text-lg font-bold">Security</h3>
    <label class="block text-sm font-medium">Current password<input type="password" name="current_password" required class="mt-1 w-full border rounded-lg px-3 py-2.5"></label>
    <label class="block text-sm font-medium">New password<input type="password" name="new_password" minlength="8" required class="mt-1 w-full border rounded-lg px-3 py-2.5"></label>
    <label class="block text-sm font-medium">Confirm new password<input type="password" name="confirm_password" minlength="8" required class="mt-1 w-full border rounded-lg px-3 py-2.5"></label>
    <button name="change_password" class="bg-slate-900 text-white px-5 py-2.5 rounded-lg font-semibold">Change password</button>
   </form>
  </div>
  <section class="bg-white rounded-2xl border shadow-sm p-6"><h3 class="text-lg font-bold mb-4">Tax rates</h3><div class="flex flex-wrap gap-2 mb-5"><?php foreach($taxes as $t):?><span class="bg-slate-100 px-3 py-2 rounded-lg text-sm"><?=h($t['name'])?> · <?=h((string)$t['rate_percentage'])?>%</span><?php endforeach;?></div>
   <form method="post" class="flex flex-wrap gap-3 items-end"><?=csrf_field()?><label class="text-sm">Name<input name="tax_name" required class="block border rounded-lg px-3 py-2"></label><label class="text-sm">Rate %<input type="number" name="tax_rate" step="0.001" min="0" required class="block border rounded-lg px-3 py-2"></label><button name="add_tax" class="brand-bg text-white px-5 py-2 rounded-lg font-semibold">Add tax rate</button></form>
  </section>
 </div>
</main>
<?php require_once 'includes/footer.php'; ?>
