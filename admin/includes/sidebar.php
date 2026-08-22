<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$currentFolder = basename(dirname($_SERVER['PHP_SELF']));
$path = $currentFolder === 'invoice' ? '../' : '';
$invoicePath = $currentFolder === 'invoice' ? '' : 'invoice/';
$nav = [
 'Workspace' => [
  ['dashboard.php','Dashboard','dashboard',true], ['contacts.php','Customers & Vendors','contacts',module_enabled($pdo,'contacts')], ['items.php','Products & Services','items',module_enabled($pdo,'items')]
 ],
 'Sales' => [
  ['invoice/create_invoice.php','Create Invoice','create_invoice',module_enabled($pdo,'invoices')], ['invoice/invoices.php','Invoices & Payments','invoices',module_enabled($pdo,'invoices')]
 ],
 'Accounting' => [
  ['accounts.php','Bank & Cash','accounts',true], ['expenses.php','Expenses','expenses',module_enabled($pdo,'expenses')], ['debit.php','Payables','debit',module_enabled($pdo,'payables')],
  ['loans.php','Loans','loans',module_enabled($pdo,'loans')], ['investments.php','Investments','investments',module_enabled($pdo,'investments')], ['journal.php','General Ledger','journal',module_enabled($pdo,'journal')], ['cashflow.php','Cash Flow','cashflow',module_enabled($pdo,'cashflow')]
 ],
 'People & insight' => [
  ['employee.php','Employees','employee',module_enabled($pdo,'payroll')], ['payslip.php','Payroll & Payslips','payslip',module_enabled($pdo,'payroll')], ['report_genaretor.php','Financial Reports','report_genaretor',module_enabled($pdo,'reports')]
 ]
];
function nav_url(string $url, string $path, string $invoicePath): string { return str_starts_with($url,'invoice/') ? $invoicePath . substr($url,8) : $path . $url; }
?>
<aside id="sidebar" class="w-72 bg-slate-950 h-screen flex flex-col text-slate-300 transition-transform duration-200 shrink-0">
  <div class="h-20 px-5 flex items-center border-b border-slate-800">
    <div class="w-10 h-10 brand-bg rounded-xl flex items-center justify-center text-white font-black mr-3">A</div>
    <div class="min-w-0"><h1 class="font-bold text-white truncate"><?= h(setting($pdo,'company_name','AccountsBase')) ?></h1><p class="text-xs text-slate-500">Business finance suite</p></div>
    <button id="closeSidebar" class="lg:hidden ml-auto text-2xl">×</button>
  </div>
  <nav class="flex-1 px-3 py-4 overflow-y-auto app-scroll">
    <?php foreach($nav as $group => $links): ?>
      <p class="px-3 mt-4 mb-1 text-[11px] font-bold text-slate-600 uppercase tracking-widest"><?= h($group) ?></p>
      <?php foreach($links as [$url,$label,$key,$enabled]): if(!$enabled) continue; $active = str_starts_with($currentPage,$key) || ($key==='employee' && in_array($currentPage,['add_employee.php','view_employee.php'],true)); ?>
        <a href="<?= h(nav_url($url,$path,$invoicePath)) ?>" class="block px-3 py-2.5 mb-1 rounded-lg text-sm font-medium <?= $active?'nav-active':'hover:bg-slate-900 hover:text-white' ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="p-3 border-t border-slate-800 space-y-1">
    <a href="<?= $path ?>settings.php" class="block px-3 py-2.5 rounded-lg text-sm <?= $currentPage==='settings.php'?'nav-active':'hover:bg-slate-900' ?>">Settings & modules</a>
    <a href="<?= $path ?>logout.php" class="block px-3 py-2.5 rounded-lg text-sm text-rose-400 hover:bg-slate-900">Sign out</a>
  </div>
</aside>
