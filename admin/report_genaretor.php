<?php
// admin/report_genaretor.php
require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$start = $_GET['start_date'] ?? date('Y-01-01');
$end = $_GET['end_date'] ?? date('Y-m-d');
$type = $_GET['type'] ?? 'profit_loss';

if (!in_array($type, ['profit_loss', 'balance_sheet', 'receivables', 'expenses'], true)) {
    $type = 'profit_loss';
}

$data = [];
$summary = [];

// ==========================================
// 1. REPORT: PROFIT & LOSS (INCOME STATEMENT)
// ==========================================
if ($type === 'profit_loss') {
    // A. Revenue Lines
    $revStmt = $pdo->prepare("
        SELECT coa.account_code, coa.name, 
               COALESCE(SUM(jl.credit - jl.debit), 0.00) AS amount
        FROM chart_of_accounts coa
        LEFT JOIN journal_lines jl ON jl.account_id = coa.id
        LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id 
             AND je.status = 'posted' 
             AND je.entry_date BETWEEN ? AND ?
        WHERE coa.type = 'Revenue' AND coa.is_active = 1
        GROUP BY coa.id
        HAVING amount != 0 OR coa.is_system_account = 1
        ORDER BY coa.account_code ASC
    ");
    $revStmt->execute([$start, $end]);
    $revenueRows = $revStmt->fetchAll();

    // B. Expense Lines
    $expStmt = $pdo->prepare("
        SELECT coa.account_code, coa.name, coa.sub_type,
               COALESCE(SUM(jl.debit - jl.credit), 0.00) AS amount
        FROM chart_of_accounts coa
        LEFT JOIN journal_lines jl ON jl.account_id = coa.id
        LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id 
             AND je.status = 'posted' 
             AND je.entry_date BETWEEN ? AND ?
        WHERE coa.type = 'Expense' AND coa.is_active = 1
        GROUP BY coa.id
        HAVING amount != 0 OR coa.is_system_account = 1
        ORDER BY coa.account_code ASC
    ");
    $expStmt->execute([$start, $end]);
    $expenseRows = $expStmt->fetchAll();

    $totalRevenue = array_sum(array_column($revenueRows, 'amount'));
    $totalExpenses = array_sum(array_column($expenseRows, 'amount'));
    $netProfit = $totalRevenue - $totalExpenses;
    $margin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;

    $data = [
        'revenue' => $revenueRows,
        'expenses' => $expenseRows
    ];

    $summary = [
        'Total Revenue' => $totalRevenue,
        'Total Expenses' => $totalExpenses,
        'Net Profit/Loss' => $netProfit,
        'Net Margin' => number_format($margin, 1) . '%'
    ];
}

// ==========================================
// 2. REPORT: BALANCE SHEET
// ==========================================
elseif ($type === 'balance_sheet') {
    // Balance Sheet is cumulative up to the selected $end date
    $bsStmt = $pdo->prepare("
        SELECT coa.type, coa.account_code, coa.name, coa.sub_type,
               CASE 
                   WHEN coa.type IN ('Liability', 'Equity') THEN COALESCE(SUM(jl.credit - jl.debit), 0.00)
                   ELSE COALESCE(SUM(jl.debit - jl.credit), 0.00)
               END AS balance
        FROM chart_of_accounts coa
        LEFT JOIN journal_lines jl ON jl.account_id = coa.id
        LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id 
             AND je.status = 'posted' 
             AND je.entry_date <= ?
        WHERE coa.type IN ('Asset', 'Liability', 'Equity') AND coa.is_active = 1
        GROUP BY coa.id
        ORDER BY FIELD(coa.type, 'Asset', 'Liability', 'Equity'), coa.account_code ASC
    ");
    $bsStmt->execute([$end]);
    $data = $bsStmt->fetchAll();

    // Compute period net income to retain in equity
    $pnlStmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN coa.type = 'Revenue' THEN (jl.credit - jl.debit) ELSE (jl.credit - jl.debit) END), 0.00)
        FROM journal_lines jl
        JOIN chart_of_accounts coa ON coa.id = jl.account_id
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        WHERE coa.type IN ('Revenue', 'Expense') 
          AND je.status = 'posted' 
          AND je.entry_date <= ?
    ");
    $pnlStmt->execute([$end]);
    $retainedFromOperations = (float) $pnlStmt->fetchColumn();

    $assets = 0;
    $liabilities = 0;
    $equity = 0;
    foreach ($data as $row) {
        if ($row['type'] === 'Asset')
            $assets += (float) $row['balance'];
        if ($row['type'] === 'Liability')
            $liabilities += (float) $row['balance'];
        if ($row['type'] === 'Equity')
            $equity += (float) $row['balance'];
    }
    $equity += $retainedFromOperations;

    $summary = [
        'Total Assets' => $assets,
        'Total Liabilities' => $liabilities,
        'Total Equity' => $equity,
        'Check (A - L - E)' => round($assets - ($liabilities + $equity), 2)
    ];
}

// ==========================================
// 3. REPORT: ACCOUNTS RECEIVABLE AGING
// ==========================================
elseif ($type === 'receivables') {
    $recStmt = $pdo->prepare("
        SELECT i.id, i.invoice_number, i.issue_date, i.due_date, i.currency_code, cur.symbol,
               c.company_name,
               (i.grand_total - i.amount_paid) AS amount_due,
               ((i.grand_total - i.amount_paid) / i.exchange_rate) AS base_amount_due,
               DATEDIFF(CURDATE(), COALESCE(i.due_date, i.issue_date)) AS days_overdue
        FROM invoices i
        JOIN contacts c ON c.id = i.contact_id
        JOIN currencies cur ON cur.code = i.currency_code
        WHERE i.status IN ('sent', 'unpaid', 'partial')
          AND i.issue_date BETWEEN ? AND ?
        ORDER BY days_overdue DESC, i.issue_date ASC
    ");
    $recStmt->execute([$start, $end]);
    $data = $recStmt->fetchAll();

    $totalOutstanding = 0;
    $currentDue = 0;
    $overdue30 = 0;
    $overdue60 = 0;
    $overdue90 = 0;

    foreach ($data as $r) {
        $base = (float) $r['base_amount_due'];
        $days = (int) $r['days_overdue'];
        $totalOutstanding += $base;

        if ($days <= 0) {
            $currentDue += $base;
        } elseif ($days <= 30) {
            $overdue30 += $base;
        } elseif ($days <= 60) {
            $overdue60 += $base;
        } else {
            $overdue90 += $base;
        }
    }

    $summary = [
        'Total Outstanding' => $totalOutstanding,
        'Current (On-Time)' => $currentDue,
        '1-30 Days Overdue' => $overdue30,
        '31+ Days Overdue' => $overdue60 + $overdue90
    ];
}

// ==========================================
// 4. REPORT: EXPENSE ANALYSIS
// ==========================================
else {
    $expAnalStmt = $pdo->prepare("
        SELECT coa.name AS category, coa.account_code,
               COUNT(jl.id) AS transactions,
               COALESCE(SUM(jl.debit - jl.credit), 0.00) AS total_spent
        FROM chart_of_accounts coa
        JOIN journal_lines jl ON jl.account_id = coa.id
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        WHERE coa.type = 'Expense' 
          AND je.status = 'posted'
          AND je.entry_date BETWEEN ? AND ?
        GROUP BY coa.id
        HAVING total_spent > 0
        ORDER BY total_spent DESC
    ");
    $expAnalStmt->execute([$start, $end]);
    $data = $expAnalStmt->fetchAll();

    $totalSpent = array_sum(array_column($data, 'total_spent'));
    $totalTrans = array_sum(array_column($data, 'transactions'));

    $summary = [
        'Total Spent' => $totalSpent,
        'Expense Accounts' => count($data),
        'Total Disbursals' => $totalTrans
    ];
}

$titles = [
    'profit_loss' => 'Income Statement (Profit & Loss)',
    'balance_sheet' => 'Statement of Financial Position (Balance Sheet)',
    'receivables' => 'Accounts Receivable Aging Schedule',
    'expenses' => 'Operational Expense Analysis'
];

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<main class="flex-1 h-screen overflow-y-auto app-scroll bg-slate-100">
    
    <!-- Action Header -->
    <header class="bg-white border-b px-5 md:px-8 py-5 flex justify-between items-center sticky top-0 z-10 shadow-sm print:hidden">
        <div class="flex items-center gap-4">
            <button data-sidebar-toggle class="lg:hidden text-2xl text-slate-700">☰</button>
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Financial Reporting Engine</h2>
                <p class="text-xs text-slate-500">General Ledger compliance, P&L, balance sheets, and receivables auditing</p>
            </div>
        </div>
        <button onclick="window.print()" class="bg-slate-900 hover:bg-slate-800 text-white font-semibold px-4 py-2 rounded-lg text-sm transition-colors flex items-center gap-1.5 shadow-sm">
            🖨 Print / Export PDF
        </button>
    </header>

    <div class="p-5 md:p-8 max-w-6xl mx-auto space-y-6">
        
        <!-- Filter Controls -->
        <form method="get" class="bg-white border border-slate-200 rounded-2xl p-5 flex flex-wrap gap-4 items-end shadow-sm print:hidden">
            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Report Module</label>
                <select name="type" class="border border-slate-300 rounded-lg px-3 py-2 bg-white text-sm outline-none focus:ring-2 focus:ring-blue-500">
                    <?php foreach ($titles as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Start Date</label>
                <input type="date" name="start_date" value="<?= h($start) ?>" class="border border-slate-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">End Date</label>
                <input type="date" name="end_date" value="<?= h($end) ?>" class="border border-slate-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2.5 rounded-lg text-sm transition-colors shadow-sm">
                Generate Statement
            </button>
        </form>

        <!-- Printable Document Container -->
        <section id="report" class="bg-white border border-slate-200 rounded-2xl p-8 md:p-12 shadow-sm space-y-8">
            
            <!-- Document Title & Header -->
            <div class="text-center border-b border-slate-200 pb-6">
                <h1 class="text-3xl font-black text-slate-900 tracking-tight"><?= h(setting($pdo, 'company_name', 'Acme Corporation')) ?></h1>
                <h2 class="text-lg font-bold text-slate-700 mt-1 uppercase tracking-wider"><?= h($titles[$type]) ?></h2>
                <p class="text-xs text-slate-500 mt-1">
                    <?= $type === 'balance_sheet' ? 'As of ' . date('F j, Y', strtotime($end)) : 'For Period: ' . date('M j, Y', strtotime($start)) . ' — ' . date('M j, Y', strtotime($end)) ?>
                </p>
                <span class="inline-block mt-2 text-[11px] font-semibold px-3 py-0.5 bg-slate-100 text-slate-600 rounded-full">
                    Currency: <?= h(setting($pdo, 'base_currency', 'USD')) ?>
                </span>
            </div>

            <!-- Summary KPI Cards -->
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ($summary as $lbl => $val): ?>
                        <div class="bg-slate-50 border border-slate-200/80 rounded-xl p-4 text-center">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400"><?= h($lbl) ?></p>
                            <p class="text-xl font-black text-slate-900 mt-1">
                                <?= is_numeric($val) ? money($pdo, $val) : h((string) $val) ?>
                            </p>
                        </div>
                <?php endforeach; ?>
            </div>

            <!-- Report Body -->
            <div class="overflow-x-auto">
                
                <!-- 1. P&L Layout -->
                <?php if ($type === 'profit_loss'): ?>
                        <div class="space-y-6">
                            <!-- Revenue Table -->
                            <div>
                                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Operating Revenue (4000s)</h3>
                                <table class="w-full text-sm border-collapse">
                                    <thead class="bg-slate-50 text-slate-600 text-xs border-b">
                                        <tr>
                                            <th class="p-3 text-left font-semibold">Account Code</th>
                                            <th class="p-3 text-left font-semibold">Account Name</th>
                                            <th class="p-3 text-right font-semibold">Total Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($data['revenue'] as $r): ?>
                                                <tr>
                                                    <td class="p-3 font-mono text-xs text-slate-500"><?= h($r['account_code']) ?></td>
                                                    <td class="p-3 font-medium text-slate-800"><?= h($r['name']) ?></td>
                                                    <td class="p-3 text-right font-semibold text-slate-900"><?= money($pdo, $r['amount']) ?></td>
                                                </tr>
                                        <?php endforeach; ?>
                                        <tr class="bg-slate-50 font-bold border-t">
                                            <td colspan="2" class="p-3 text-slate-700">Total Operating Revenue</td>
                                            <td class="p-3 text-right text-emerald-600"><?= money($pdo, $summary['Total Revenue']) ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Expenses Table -->
                            <div>
                                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Operating & Administrative Expenses (5000s)</h3>
                                <table class="w-full text-sm border-collapse">
                                    <thead class="bg-slate-50 text-slate-600 text-xs border-b">
                                        <tr>
                                            <th class="p-3 text-left font-semibold">Account Code</th>
                                            <th class="p-3 text-left font-semibold">Account Name</th>
                                            <th class="p-3 text-right font-semibold">Total Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($data['expenses'] as $r): ?>
                                                <tr>
                                                    <td class="p-3 font-mono text-xs text-slate-500"><?= h($r['account_code']) ?></td>
                                                    <td class="p-3 font-medium text-slate-800"><?= h($r['name']) ?></td>
                                                    <td class="p-3 text-right font-semibold text-slate-900"><?= money($pdo, $r['amount']) ?></td>
                                                </tr>
                                        <?php endforeach; ?>
                                        <tr class="bg-slate-50 font-bold border-t">
                                            <td colspan="2" class="p-3 text-slate-700">Total Operating Expenses</td>
                                            <td class="p-3 text-right text-rose-600"><?= money($pdo, $summary['Total Expenses']) ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Net Profit / Loss Box -->
                            <div class="p-4 rounded-xl border flex justify-between items-center text-lg font-black <?= $summary['Net Profit/Loss'] >= 0 ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : 'bg-rose-50 border-rose-200 text-rose-900' ?>">
                                <span>Net Operating Income (Profit / Loss)</span>
                                <span><?= money($pdo, $summary['Net Profit/Loss']) ?></span>
                            </div>
                        </div>

                    <!-- 2. Balance Sheet Layout -->
                <?php elseif ($type === 'balance_sheet'): ?>
                        <table class="w-full text-sm border-collapse">
                            <thead class="bg-slate-900 text-white text-xs">
                                <tr>
                                    <th class="p-3 text-left font-semibold">Classification</th>
                                    <th class="p-3 text-left font-semibold">Code</th>
                                    <th class="p-3 text-left font-semibold">Account Title</th>
                                    <th class="p-3 text-right font-semibold">Net Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($data as $r): ?>
                                        <tr>
                                            <td class="p-3">
                                                <span class="px-2 py-0.5 rounded text-xs font-bold 
                                            <?= $r['type'] === 'Asset' ? 'bg-blue-100 text-blue-800' : ($r['type'] === 'Liability' ? 'bg-purple-100 text-purple-800' : 'bg-emerald-100 text-emerald-800') ?>">
                                                    <?= h($r['type']) ?>
                                                </span>
                                            </td>
                                            <td class="p-3 font-mono text-xs text-slate-500"><?= h($r['account_code']) ?></td>
                                            <td class="p-3 font-medium text-slate-800"><?= h($r['name']) ?></td>
                                            <td class="p-3 text-right font-bold text-slate-900"><?= money($pdo, $r['balance']) ?></td>
                                        </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                    <!-- 3. Receivables Aging Layout -->
                <?php elseif ($type === 'receivables'): ?>
                        <table class="w-full text-sm border-collapse">
                            <thead class="bg-slate-900 text-white text-xs">
                                <tr>
                                    <th class="p-3 text-left font-semibold">Invoice #</th>
                                    <th class="p-3 text-left font-semibold">Customer Name</th>
                                    <th class="p-3 text-left font-semibold">Due Date</th>
                                    <th class="p-3 text-center font-semibold">Aging Status</th>
                                    <th class="p-3 text-right font-semibold">Outstanding Due</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($data as $r):
                                    $days = (int) $r['days_overdue'];
                                    ?>
                                        <tr>
                                            <td class="p-3 font-bold text-blue-600"><?= h($r['invoice_number']) ?></td>
                                            <td class="p-3 font-medium text-slate-800"><?= h($r['company_name']) ?></td>
                                            <td class="p-3 text-xs text-slate-500"><?= !empty($r['due_date']) ? date('M j, Y', strtotime($r['due_date'])) : 'On Demand' ?></td>
                                            <td class="p-3 text-center">
                                                <?php if ($days <= 0): ?>
                                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">Current</span>
                                                <?php elseif ($days <= 30): ?>
                                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800">1-30 Days</span>
                                                <?php else: ?>
                                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-rose-100 text-rose-800"><?= $days ?> Days Overdue</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-3 text-right font-black text-slate-900"><?= money($pdo, $r['base_amount_due']) ?></td>
                                        </tr>
                                <?php endforeach; ?>
                                <?php if (!$data): ?>
                                        <tr>
                                            <td colspan="5" class="p-8 text-center text-slate-400">All customer accounts are settled with zero outstanding receivables.</td>
                                        </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>

                    <!-- 4. Expense Analysis Layout -->
                <?php else: ?>
                        <table class="w-full text-sm border-collapse">
                            <thead class="bg-slate-900 text-white text-xs">
                                <tr>
                                    <th class="p-3 text-left font-semibold">Account / Category</th>
                                    <th class="p-3 text-center font-semibold">GL Code</th>
                                    <th class="p-3 text-center font-semibold">Disbursals Logged</th>
                                    <th class="p-3 text-right font-semibold">Total Expensed</th>
                                    <th class="p-3 text-right font-semibold">Share of Outflow</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($data as $r):
                                    $pct = $summary['Total Spent'] > 0 ? ($r['total_spent'] / $summary['Total Spent']) * 100 : 0;
                                    ?>
                                        <tr>
                                            <td class="p-3 font-bold text-slate-800"><?= h($r['category']) ?></td>
                                            <td class="p-3 text-center font-mono text-xs text-slate-500"><?= h($r['account_code']) ?></td>
                                            <td class="p-3 text-center text-slate-600"><?= (int) $r['transactions'] ?></td>
                                            <td class="p-3 text-right font-black text-slate-900"><?= money($pdo, $r['total_spent']) ?></td>
                                            <td class="p-3 text-right text-xs font-bold text-slate-500"><?= number_format($pct, 1) ?>%</td>
                                        </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                <?php endif; ?>

            </div>

            <!-- Sign-Off Section (Print view only) -->
            <div class="hidden print:flex justify-between items-center mt-20 pt-10 border-t border-slate-300 text-xs">
                <div class="text-center w-52">
                    <div class="border-b border-slate-400 pb-8 mb-2"></div>
                    <span class="font-bold text-slate-700 uppercase">Prepared By (Finance)</span>
                </div>
                <div class="text-center w-52">
                    <div class="border-b border-slate-400 pb-8 mb-2"></div>
                    <span class="font-bold text-slate-700 uppercase">Authorized Officer</span>
                </div>
            </div>

        </section>

    </div>
</main>

<style>
@media print {
    aside, header, #sidebarOverlay, form, .print\:hidden {
        display: none !important;
    }
    main {
        height: auto !important;
        overflow: visible !important;
        background: white !important;
        padding: 0 !important;
    }
    #report {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>