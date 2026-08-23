<?php
// admin/cash_flow.php
require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');
$params = ['start' => $start, 'end' => $end];

// 1. Calculate Historical Opening Cash Balance (Before $start date)
$openingCashStmt = $pdo->prepare("
    SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
    FROM journal_lines jl
    JOIN journal_entries je ON je.id = jl.journal_entry_id
    JOIN chart_of_accounts coa ON coa.id = jl.account_id
    WHERE coa.account_code BETWEEN '1000' AND '1099'
      AND je.status = 'posted'
      AND je.entry_date < :start
");
$openingCashStmt->execute(['start' => $start]);
$openingBalance = (float) $openingCashStmt->fetchColumn();

// 2. Structured Cash Flow Query Map
$flowDefinitions = [
    // --- Operating Activities (Inflows) ---
    'Customer Inbound Receipts' => [
        "sql" => "SELECT COALESCE(SUM(p.amount), 0) 
                  FROM payments p 
                  WHERE p.payment_type = 'inbound' 
                    AND p.payment_date BETWEEN :start AND :end",
        "type" => "in",
        "category" => "Operating Activities"
    ],

    // --- Operating Activities (Outflows) ---
    'Supplier & Vendor Disbursements' => [
        "sql" => "SELECT COALESCE(SUM(p.amount), 0) 
                  FROM payments p 
                  WHERE p.payment_type = 'outbound' 
                    AND p.payment_date BETWEEN :start AND :end",
        "type" => "out",
        "category" => "Operating Activities"
    ],
    'General Operating & Admin Expenses' => [
        "sql" => "SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
                  FROM journal_lines jl
                  JOIN journal_entries je ON je.id = jl.journal_entry_id
                  JOIN chart_of_accounts coa ON coa.id = jl.account_id
                  WHERE coa.type = 'Expense' 
                    AND coa.account_code != '5100'
                    AND je.status = 'posted'
                    AND je.entry_date BETWEEN :start AND :end",
        "type" => "out",
        "category" => "Operating Activities"
    ],
    'Staff Payroll & Salaries' => [
        "sql" => "SELECT COALESCE(SUM(p.net_payable), 0) 
                  FROM payslips p 
                  JOIN payroll_runs pr ON pr.id = p.payroll_run_id 
                  WHERE p.status = 'paid' 
                    AND pr.processed_date BETWEEN :start AND :end",
        "type" => "out",
        "category" => "Operating Activities"
    ],

    // --- Financing & Capital Activities ---
    'Owner Capital Contributions' => [
        "sql" => "SELECT COALESCE(SUM(jl.credit - jl.debit), 0)
                  FROM journal_lines jl
                  JOIN journal_entries je ON je.id = jl.journal_entry_id
                  JOIN chart_of_accounts coa ON coa.id = jl.account_id
                  WHERE coa.account_code = '3000'
                    AND je.status = 'posted'
                    AND je.entry_date BETWEEN :start AND :end",
        "type" => "in",
        "category" => "Financing & Equity"
    ]
];

$rows = [];
$totalIn = 0.0;
$totalOut = 0.0;

foreach ($flowDefinitions as $label => $def) {
    try {
        $stmt = $pdo->prepare($def['sql']);
        $stmt->execute($params);
        $amt = (float) $stmt->fetchColumn();
    } catch (Exception $e) {
        $amt = 0.0;
    }

    $rows[] = [
        'label' => $label,
        'type' => $def['type'],
        'category' => $def['category'],
        'amount' => $amt
    ];

    if ($def['type'] === 'in') {
        $totalIn += $amt;
    } else {
        $totalOut += $amt;
    }
}

$netMovement = $totalIn - $totalOut;
$closingBalance = $openingBalance + $netMovement;

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<main class="flex-1 h-screen overflow-y-auto app-scroll bg-slate-100">
    <!-- Header -->
    <header class="bg-white border-b px-5 md:px-8 py-5 flex items-center justify-between sticky top-0 z-10 shadow-sm">
        <div class="flex items-center gap-4">
            <button data-sidebar-toggle class="lg:hidden text-2xl text-slate-700">☰</button>
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Statement of Cash Flows</h2>
                <p class="text-xs text-slate-500">Track liquid cash movements across operating, payroll, and equity
                    accounts</p>
            </div>
        </div>
        <button onclick="window.print()"
            class="border border-slate-300 hover:bg-slate-50 text-slate-700 px-4 py-2 rounded-lg text-sm font-semibold transition-colors flex items-center gap-1.5 shadow-sm print:hidden">
            🖨 Print Statement
        </button>
    </header>

    <div class="p-5 md:p-8 max-w-5xl mx-auto space-y-6">
        <!-- Date Filter Card -->
        <form method="get"
            class="bg-white border border-slate-200 rounded-2xl p-5 flex flex-wrap gap-4 items-end shadow-sm print:hidden">
            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">From Date</label>
                <input type="date" name="start" value="<?= h($start) ?>"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm outline-none bg-white focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">To Date</label>
                <input type="date" name="end" value="<?= h($end) ?>"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm outline-none bg-white focus:ring-2 focus:ring-blue-500">
            </div>
            <button type="submit"
                class="bg-slate-900 hover:bg-slate-800 text-white px-5 py-2.5 rounded-lg font-semibold text-sm transition-colors">
                Apply Date Filter
            </button>
        </form>

        <!-- KPI Summary Cards -->
        <div class="grid sm:grid-cols-3 gap-4">
            <div class="bg-white border border-emerald-200 rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-emerald-600">Total Cash Inflow</p>
                <p class="text-2xl font-black text-emerald-700 mt-1"><?= money($pdo, $totalIn) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Receipts & Capital</p>
            </div>

            <div class="bg-white border border-rose-200 rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-rose-600">Total Cash Outflow</p>
                <p class="text-2xl font-black text-rose-700 mt-1"><?= money($pdo, $totalOut) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Expenses & Disbursements</p>
            </div>

            <div class="bg-slate-900 text-white rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Net Cash Movement</p>
                <p class="text-2xl font-black mt-1 <?= $netMovement >= 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                    <?= $netMovement >= 0 ? '+' : '' ?><?= money($pdo, $netMovement) ?>
                </p>
                <p class="text-[11px] text-slate-400 mt-1">Net Period Change</p>
            </div>
        </div>

        <!-- Cash Flow Statement Table -->
        <section class="bg-white border border-slate-200 rounded-2xl p-6 md:p-8 shadow-sm space-y-6">
            <div class="border-b pb-4 flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Cash Flow Report</h3>
                    <p class="text-xs text-slate-500"><?= date('M j, Y', strtotime($start)) ?> —
                        <?= date('M j, Y', strtotime($end)) ?></p>
                </div>
                <span class="text-xs font-semibold px-2.5 py-1 bg-slate-100 text-slate-700 rounded-full">
                    Base Currency: <?= h(setting($pdo, 'base_currency', 'USD')) ?>
                </span>
            </div>

            <!-- Opening Position -->
            <div
                class="flex justify-between items-center py-3.5 px-4 bg-slate-50 rounded-xl border border-slate-100 text-sm">
                <span class="font-bold text-slate-700">Opening Cash & Bank Balance (as of
                    <?= date('M j, Y', strtotime($start)) ?>)</span>
                <strong class="font-black text-slate-900 text-base"><?= money($pdo, $openingBalance) ?></strong>
            </div>

            <!-- Flow Breakdown -->
            <div class="space-y-4">
                <?php
                $categories = array_unique(array_column($rows, 'category'));
                foreach ($categories as $cat):
                    ?>
                    <div class="pt-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2"><?= h($cat) ?></h4>
                        <div class="divide-y divide-slate-100 border border-slate-100 rounded-xl overflow-hidden">
                                <?php foreach ($rows as $row):
                                    if ($row['category'] !== $cat)
                                        continue; ?>
                                <div
                                    class="flex justify-between items-center p-3 text-sm hover:bg-slate-50/50 transition-colors">
                                    <span class="text-slate-700"><?= h($row['label']) ?></span>
                                    <strong
                                        class="<?= $row['type'] === 'in' ? 'text-emerald-600' : 'text-rose-600' ?> font-semibold">
                                                <?= $row['type'] === 'in' ? '+' : '-' ?>                <?= money($pdo, $row['amount']) ?>
                                    </strong>
                                </div>
                                <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Closing Position -->
            <div class="border-t-2 border-slate-900 pt-5 mt-6 flex justify-between items-center text-lg">
                <div>
                    <strong class="font-black text-slate-900">Closing Cash & Bank Balance</strong>
                    <p class="text-xs text-slate-400 font-normal">Opening balance adjusted for net periodic flows</p>
                </div>
                <strong class="font-black text-2xl text-slate-900"><?= money($pdo, $closingBalance) ?></strong>
            </div>
        </section>
    </div>
</main>

<style>
    @media print {

        aside,
        header,
        #sidebarOverlay,
        form,
        .print\:hidden {
            display: none !important;
        }

        main {
            height: auto !important;
            overflow: visible !important;
            background: white !important;
            padding: 0 !important;
        }
    }
</style>

<?php require_once 'includes/footer.php'; ?>