<?php
// admin/dashboard.php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE)
    session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// Fallback helper functions
if (!function_exists('h')) {
    function h($str)
    {
        return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('money')) {
    function money($pdo, $amount)
    {
        return '$' . number_format((float) $amount, 2);
    }
}

// ==========================================
// 1. LIVE LEDGER & KPI AGGREGATIONS
// ==========================================

// Available Cash & Bank Liquidity (GL Codes 1000 - 1099)
$cashQuery = "
    SELECT COALESCE(SUM(jl.debit - jl.credit), 0) 
    FROM journal_lines jl 
    JOIN chart_of_accounts coa ON jl.account_id = coa.id 
    WHERE coa.account_code BETWEEN '1000' AND '1099'
";
$availableCash = (float) $pdo->query($cashQuery)->fetchColumn();

// Gross Invoiced Revenue (Total Billed)
$grossBilled = (float) $pdo->query("SELECT COALESCE(SUM(grand_total / exchange_rate), 0) FROM invoices WHERE status != 'voided'")->fetchColumn();

// Collected Revenue (Inbound Payments / Paid Invoices)
$collectedRevenue = (float) $pdo->query("SELECT COALESCE(SUM(amount_paid / exchange_rate), 0) FROM invoices WHERE status != 'voided'")->fetchColumn();

// Total Operating Expenses (Sum of Expense accounts in GL)
$expensesQuery = "
    SELECT COALESCE(SUM(jl.debit - jl.credit), 0) 
    FROM journal_lines jl 
    JOIN chart_of_accounts coa ON jl.account_id = coa.id 
    WHERE coa.type = 'Expense'
";
$operatingExpenses = (float) $pdo->query($expensesQuery)->fetchColumn();

// Outstanding Accounts Receivable (Unpaid Invoices)
$receivables = (float) $pdo->query("SELECT COALESCE(SUM((grand_total - amount_paid) / exchange_rate), 0) FROM invoices WHERE status IN ('sent', 'unpaid', 'partial')")->fetchColumn();

// Overdue Receivables
$overdueReceivables = (float) $pdo->query("SELECT COALESCE(SUM((grand_total - amount_paid) / exchange_rate), 0) FROM invoices WHERE status IN ('sent', 'unpaid', 'partial') AND due_date < CURDATE()")->fetchColumn();

// Pending Accounts Payable (Vendor Debits / Bills)
$payables = 0.00;
try {
    $payables = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM debits WHERE status = 'pending'")->fetchColumn();
} catch (Exception $e) {
    // Fallback if bills table is used
    $payables = (float) $pdo->query("SELECT COALESCE(SUM(grand_total / exchange_rate), 0) FROM bills WHERE status = 'unpaid'")->fetchColumn();
}

// Net Operating Position & Net Margin
$netOperatingIncome = $collectedRevenue - $operatingExpenses;
$netMargin = $grossBilled > 0 ? ($netOperatingIncome / $grossBilled) * 100 : 0;

// Runway Computation (Cash divided by average monthly burn rate over last 3 months)
$threeMonthBurn = (float) $pdo->query("
    SELECT COALESCE(SUM(jl.debit - jl.credit), 0) / 3 
    FROM journal_lines jl 
    JOIN journal_entries je ON jl.journal_entry_id = je.id 
    JOIN chart_of_accounts coa ON jl.account_id = coa.id 
    WHERE coa.type = 'Expense' AND je.entry_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
")->fetchColumn();
$runwayMonths = ($threeMonthBurn > 0 && $availableCash > 0) ? round($availableCash / $threeMonthBurn, 1) : ($availableCash > 0 ? 12 : 0);

// ==========================================
// 2. DATA LISTS & TRENDS
// ==========================================

// Recent Invoices
$recentInvoices = $pdo->query("
    SELECT i.id, i.invoice_number, i.issue_date, i.due_date, i.grand_total, i.amount_paid, i.status, i.currency_code, c.company_name 
    FROM invoices i 
    JOIN contacts c ON c.id = i.contact_id 
    ORDER BY i.created_at DESC 
    LIMIT 6
")->fetchAll();

// Top Selling Items / Services
$topItems = $pdo->query("
    SELECT it.name, it.item_type, COUNT(il.id) AS sales_count, COALESCE(SUM(il.line_total), 0) AS total_revenue 
    FROM invoice_lines il 
    JOIN items it ON il.item_id = it.id 
    GROUP BY it.id 
    ORDER BY total_revenue DESC 
    LIMIT 4
")->fetchAll();

// 6-Month Monthly Inflow Trend
$monthlyTrend = $pdo->query("
    SELECT DATE_FORMAT(issue_date, '%Y-%m') AS month_key, 
           COALESCE(SUM(grand_total / exchange_rate), 0) AS billed_amount,
           COALESCE(SUM(amount_paid / exchange_rate), 0) AS collected_amount 
    FROM invoices 
    WHERE issue_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH) AND status != 'voided'
    GROUP BY month_key 
    ORDER BY month_key ASC
")->fetchAll();

$maxChartValue = 1;
foreach ($monthlyTrend as $m) {
    if ($m['billed_amount'] > $maxChartValue)
        $maxChartValue = (float) $m['billed_amount'];
    if ($m['collected_amount'] > $maxChartValue)
        $maxChartValue = (float) $m['collected_amount'];
}

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<main class="flex-1 h-screen overflow-y-auto app-scroll bg-slate-50">

    <!-- Top Header -->
    <header
        class="bg-white border-b px-5 md:px-8 py-5 flex flex-wrap gap-4 items-center justify-between sticky top-0 z-10 shadow-sm">
        <div class="flex items-center gap-4">
            <button data-sidebar-toggle class="lg:hidden text-2xl text-slate-700">☰</button>
            <div>
                <h2 class="text-2xl font-bold text-slate-900">Executive Dashboard</h2>
                <p class="text-xs text-slate-500 font-medium"><?= h(date('l, F j, Y')) ?> · Double-Entry General Ledger
                    Active</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="report_genaretor.php"
                class="border border-slate-300 hover:bg-slate-50 text-slate-700 px-4 py-2 rounded-lg font-semibold text-sm transition-colors">
                Financial Reports
            </a>
            <a href="invoice/create_invoice.php"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-semibold text-sm transition-colors shadow-sm flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                New Invoice
            </a>
        </div>
    </header>

    <div class="p-5 md:p-8 max-w-7xl mx-auto space-y-7">

        <!-- 6 Core KPI Cards -->
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">

            <div class="bg-white border rounded-2xl p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Cash on Hand</p>
                    <span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span>
                </div>
                <p class="text-2xl font-black text-slate-900 mt-2"><?= money($pdo, $availableCash) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Liquid Bank & Cash</p>
            </div>

            <div class="bg-white border rounded-2xl p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Billed</p>
                    <span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span>
                </div>
                <p class="text-2xl font-black text-slate-900 mt-2"><?= money($pdo, $grossBilled) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Gross Invoiced</p>
            </div>

            <div class="bg-white border rounded-2xl p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Collected</p>
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                </div>
                <p class="text-2xl font-black text-emerald-600 mt-2"><?= money($pdo, $collectedRevenue) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Settled Income</p>
            </div>

            <div class="bg-white border rounded-2xl p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Operating Cost</p>
                    <span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                </div>
                <p class="text-2xl font-black text-rose-600 mt-2"><?= money($pdo, $operatingExpenses) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">General Ledger Outflow</p>
            </div>

            <div class="bg-white border rounded-2xl p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Receivables</p>
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                </div>
                <p class="text-2xl font-black text-amber-600 mt-2"><?= money($pdo, $receivables) ?></p>
                <p
                    class="text-[11px] <?= $overdueReceivables > 0 ? 'text-rose-500 font-semibold' : 'text-slate-500' ?> mt-1">
                    <?= money($pdo, $overdueReceivables) ?> Overdue
                </p>
            </div>

            <div class="bg-white border rounded-2xl p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Payables</p>
                    <span class="w-2.5 h-2.5 rounded-full bg-purple-500"></span>
                </div>
                <p class="text-2xl font-black text-purple-700 mt-2"><?= money($pdo, $payables) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Pending Outflow</p>
            </div>

        </div>

        <!-- Main Middle Grid -->
        <div class="grid xl:grid-cols-3 gap-7">

            <!-- Left 2 Cols: Invoices List & Trends -->
            <div class="xl:col-span-2 space-y-7">

                <!-- Recent Invoices Table -->
                <section class="bg-white border rounded-2xl shadow-sm overflow-hidden">
                    <div class="p-5 border-b flex justify-between items-center bg-slate-50/50">
                        <div>
                            <h3 class="font-bold text-lg text-slate-900">Recent Customer Invoices</h3>
                            <p class="text-xs text-slate-500">Latest accounts receivable activity</p>
                        </div>
                        <a href="invoice/invoices.php"
                            class="text-sm font-semibold text-blue-600 hover:text-blue-800">View All →</a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left border-collapse">
                            <thead class="bg-slate-50 text-slate-500 text-xs border-b">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">Invoice #</th>
                                    <th class="px-5 py-3 font-semibold">Customer</th>
                                    <th class="px-5 py-3 font-semibold text-right">Amount</th>
                                    <th class="px-5 py-3 font-semibold text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($recentInvoices as $inv): ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <td class="px-5 py-4">
                                            <a class="font-bold text-blue-600 hover:underline"
                                                href="invoice/view_invoice.php?id=<?= $inv['id'] ?>">
                                                <?= h($inv['invoice_number']) ?>
                                            </a>
                                            <p class="text-[11px] text-slate-400">Due:
                                                <?= h(date('M j, Y', strtotime($inv['due_date']))) ?>
                                            </p>
                                        </td>
                                        <td class="px-5 py-4">
                                            <span class="font-medium text-slate-800"><?= h($inv['company_name']) ?></span>
                                        </td>
                                        <td class="px-5 py-4 text-right">
                                            <span
                                                class="font-bold text-slate-900"><?= money($pdo, $inv['grand_total']) ?></span>
                                            <?php if ($inv['amount_paid'] > 0 && $inv['amount_paid'] < $inv['grand_total']): ?>
                                                <p class="text-[10px] text-emerald-600">Paid:
                                                    <?= money($pdo, $inv['amount_paid']) ?>
                                                </p>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-5 py-4 text-center">
                                            <?php
                                            $st = $inv['status'];
                                            $badge = 'bg-slate-100 text-slate-700';
                                            if ($st === 'paid')
                                                $badge = 'bg-emerald-100 text-emerald-800';
                                            if ($st === 'partial')
                                                $badge = 'bg-amber-100 text-amber-800';
                                            if ($st === 'unpaid')
                                                $badge = 'bg-rose-100 text-rose-800';
                                            ?>
                                            <span class="px-2.5 py-1 rounded-full text-xs font-bold <?= $badge ?>">
                                                <?= h(ucfirst($st)) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$recentInvoices): ?>
                                    <tr>
                                        <td colspan="4" class="p-10 text-center text-slate-500">
                                            No invoices created yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- 6-Month Invoiced vs. Collected Revenue Trend -->
                <section class="bg-white border rounded-2xl p-6 shadow-sm">
                    <div class="flex justify-between items-center mb-6">
                        <div>
                            <h3 class="font-bold text-slate-900">Invoicing vs. Collection Trend</h3>
                            <p class="text-xs text-slate-500">Historical performance over the last 6 months</p>
                        </div>
                        <div class="flex items-center gap-4 text-xs font-medium">
                            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-blue-600"></span>
                                Billed</span>
                            <span class="flex items-center gap-1.5"><span
                                    class="w-3 h-3 rounded-sm bg-emerald-500"></span> Collected</span>
                        </div>
                    </div>

                    <div class="h-48 flex items-end gap-6 pt-4 border-b border-slate-100">
                        <?php foreach ($monthlyTrend as $m):
                            $billedH = max(6, ($m['billed_amount'] / $maxChartValue) * 140);
                            $collectedH = max(6, ($m['collected_amount'] / $maxChartValue) * 140);
                            ?>
                            <div class="flex-1 flex flex-col items-center gap-2">
                                <div class="w-full flex justify-center items-end gap-1.5 h-[140px]">
                                    <div class="w-3.5 bg-blue-600 rounded-t-sm" style="height: <?= $billedH ?>px"
                                        title="Billed: $<?= number_format($m['billed_amount'], 2) ?>"></div>
                                    <div class="w-3.5 bg-emerald-500 rounded-t-sm" style="height: <?= $collectedH ?>px"
                                        title="Collected: $<?= number_format($m['collected_amount'], 2) ?>"></div>
                                </div>
                                <span
                                    class="text-xs text-slate-500 font-medium"><?= h(date('M', strtotime($m['month_key'] . '-01'))) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$monthlyTrend): ?>
                            <div class="w-full text-center text-slate-400 py-12 text-sm">No transaction history to chart.
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

            </div>

            <!-- Right Sidebar: Financial Position & Top Items -->
            <aside class="space-y-7">

                <!-- Net Financial Health Card -->
                <section class="bg-slate-900 text-white rounded-2xl p-6 shadow-md">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-xs uppercase font-bold tracking-wider text-slate-400">Net Operating Position
                            </p>
                            <p class="text-3xl font-black mt-2 text-white"><?= money($pdo, $netOperatingIncome) ?></p>
                        </div>
                        <span
                            class="px-2 py-1 rounded-md text-xs font-bold <?= $netMargin >= 0 ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-rose-500/20 text-rose-400 border border-rose-500/30' ?>">
                            <?= number_format($netMargin, 1) ?>% Margin
                        </span>
                    </div>

                    <div class="mt-6 pt-5 border-t border-slate-800 grid grid-cols-2 gap-4 text-xs">
                        <div>
                            <p class="text-slate-400">Est. Runway</p>
                            <p class="text-lg font-extrabold text-white mt-0.5"><?= $runwayMonths ?> <span
                                    class="text-xs font-normal text-slate-400">months</span></p>
                        </div>
                        <div>
                            <p class="text-slate-400">Net Working Capital</p>
                            <p class="text-lg font-extrabold text-white mt-0.5">
                                

                                                            <?= money($pdo, ($availableCash + $receivables) - $payables) ?></p>
                        </div>
                    </div>
                </section>

                <!-- Top Revenue Generators (Items / Services) -->
                <section class="bg-white border rounded-2xl p-6 shadow-sm">
                    <h3 class="font-bold text-slate-900">Top Revenue Drivers</h3>
                    <p class="text-xs text-slate-500 mb-4">Highest yielding products & services</p>

                    <div class="space-y-3">
                    <?php foreach ($topItems as $item): ?>
                            <div
                                class="flex items-center justify-between p-3 rounded-xl bg-slate-50 hover:bg-slate-100 transition-colors">
                                <div>
                                    <p class="text-sm font-bold text-slate-800"><?= h($item['name']) ?></p>
                                    <p class="text-[11px] text-slate-500"><?= $item['sales_count'] ?> sales logged</p>
                                </div>
                                <span
                                    class="text-sm font-black text-slate-900"><?= money($pdo, $item['total_revenue']) ?></span>
                                </div>
                        <?php endforeach; ?>
                    <?php if (!$topItems): ?>
                            <p class="text-xs text-slate-400 text-center py-4">Item performance metrics will appear after
                                    generating invoices.</p>
                        <?php endif; ?>
                    </div>
                </section>

            </aside>

        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>