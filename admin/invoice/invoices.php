<?php
// admin/invoice/invoices.php
require_once '../../config/db.php';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';

$status = $_GET['status'] ?? '';
$q = trim((string) ($_GET['q'] ?? ''));
$params = [];
$where = [];

// Filter Logic handling optional/nullable due dates
if ($status === 'pending') {
    $where[] = "i.status IN ('sent', 'unpaid', 'partial') AND (i.due_date IS NULL OR i.due_date >= CURDATE())";
} elseif ($status === 'overdue') {
    $where[] = "i.status IN ('sent', 'unpaid', 'partial') AND i.due_date IS NOT NULL AND i.due_date < CURDATE()";
} elseif ($status === 'due_today') {
    $where[] = "i.status IN ('sent', 'unpaid', 'partial') AND i.due_date = CURDATE()";
} elseif (in_array($status, ['draft', 'sent', 'unpaid', 'partial', 'paid', 'voided'], true)) {
    $where[] = 'i.status = ?';
    $params[] = $status;
}

if ($q !== '') {
    $where[] = '(i.invoice_number LIKE ? OR c.company_name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$sql = "SELECT i.*, c.company_name, cur.symbol, t.name AS template_name 
        FROM invoices i 
        JOIN contacts c ON c.id = i.contact_id 
        JOIN currencies cur ON cur.code = i.currency_code 
        LEFT JOIN invoice_templates t ON t.id = i.invoice_template_id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY i.created_at DESC";

$s = $pdo->prepare($sql);
$s->execute($params);
$invoices = $s->fetchAll();

// Aggregated Totals in Base Currency
$totals = $pdo->query("
    SELECT COUNT(*) AS total,
           COALESCE(SUM(grand_total / exchange_rate), 0) AS billed,
           COALESCE(SUM(amount_paid / exchange_rate), 0) AS paid,
           COALESCE(SUM((grand_total - amount_paid) / exchange_rate), 0) AS due 
    FROM invoices 
    WHERE status <> 'voided'
")->fetch();

$filters = [
    'pending' => 'Pending / Active',
    'due_today' => 'Due Today',
    'overdue' => 'Overdue',
    'unpaid' => 'Unpaid',
    'partial' => 'Partial',
    'paid' => 'Paid',
    'draft' => 'Draft',
    'sent' => 'Sent',
    'voided' => 'Voided'
];
?>

<main class="flex-1 h-screen overflow-y-auto app-scroll bg-slate-100">

    <!-- Top Action Header -->
    <header class="bg-white border-b px-5 md:px-8 py-5 flex justify-between items-center sticky top-0 z-10 shadow-sm">
        <div class="flex gap-4 items-center">
            <button data-sidebar-toggle class="lg:hidden text-2xl text-slate-700">☰</button>
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Invoices & Receivables</h2>
                <p class="text-xs text-slate-500">Track collections, manage customer billing, and monitor overdue
                    accounts</p>
            </div>
        </div>
        <a href="create_invoice.php"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-lg text-sm font-semibold transition-colors flex items-center gap-1.5 shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Create Invoice
        </a>
    </header>

    <div class="p-5 md:p-8 max-w-7xl mx-auto space-y-6">

        <!-- Summary Cards -->
        <div class="grid sm:grid-cols-3 gap-4">
            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Invoiced</p>
                <p class="text-2xl font-black text-slate-900 mt-1"><?= money($pdo, $totals['billed']) ?></p>
                <p class="text-[11px] text-slate-500 mt-1"><?= number_format($totals['total']) ?> Total Records</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Collected</p>
                <p class="text-2xl font-black text-emerald-600 mt-1"><?= money($pdo, $totals['paid']) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Settled Funds</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Outstanding Balance</p>
                <p class="text-2xl font-black text-amber-600 mt-1"><?= money($pdo, $totals['due']) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Pending Receivables</p>
            </div>
        </div>

        <!-- Invoices List & Filter Bar -->
        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm">

            <form method="get" class="p-4 border-b flex flex-wrap md:flex-nowrap gap-3 bg-slate-50/70">
                <input name="q" value="<?= h($q) ?>" placeholder="Search by invoice number or customer name..."
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm flex-1 outline-none bg-white focus:ring-2 focus:ring-blue-500">
                <select name="status"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white outline-none">
                    <option value="">All Statuses</option>
                    <?php foreach ($filters as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit"
                    class="bg-slate-900 hover:bg-slate-800 text-white rounded-lg px-5 py-2 text-sm font-semibold transition-colors">
                    Filter
                </button>
            </form>

            <div class="overflow-x-auto max-h-[720px]">
                <table class="w-full text-sm text-left border-collapse">
                    <thead class="bg-slate-50 text-slate-600 text-xs border-b sticky top-0">
                        <tr>
                            <th class="p-4 font-semibold">Invoice #</th>
                            <th class="p-4 font-semibold">Customer</th>
                            <th class="p-4 font-semibold">Dates</th>
                            <th class="p-4 font-semibold text-right">Total</th>
                            <th class="p-4 font-semibold text-right">Balance Due</th>
                            <th class="p-4 font-semibold text-center">Status</th>
                            <th class="p-4 text-right font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($invoices as $i):
                            $hasDue = !empty($i['due_date']);
                            $isOverdue = $hasDue && strtotime($i['due_date']) < strtotime(date('Y-m-d')) && !in_array($i['status'], ['paid', 'voided'], true);
                            $balance = (float) $i['grand_total'] - (float) $i['amount_paid'];
                            ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="p-4">
                                    <a href="view_invoice.php?id=<?= $i['id'] ?>"
                                        class="font-bold text-blue-600 hover:underline">
                                            <?= h($i['invoice_number']) ?>
                                    </a>
                                    <p class="text-xs text-slate-400 mt-0.5">
                                        <?= h($i['template_name'] ?: 'Standard Template') ?></p>
                                </td>

                                <td class="p-4 font-medium text-slate-900">
                                        <?= h($i['company_name']) ?>
                                </td>

                                <td class="p-4 text-xs">
                                    <p class="text-slate-600">Issued: <?= date('M j, Y', strtotime($i['issue_date'])) ?></p>
                                        <?php if ($hasDue): ?>
                                        <p class="<?= $isOverdue ? 'text-rose-600 font-bold' : 'text-slate-500' ?> mt-0.5">
                                            Due: <?= date('M j, Y', strtotime($i['due_date'])) ?>
                                                    <?= $isOverdue ? ' (Overdue)' : '' ?>
                                        </p>
                                        <?php else: ?>
                                        <p class="text-slate-400 mt-0.5 italic">No Due Date</p>
                                        <?php endif; ?>
                                </td>

                                <td class="p-4 text-right text-slate-800 font-semibold">
                                        <?= h($i['symbol']) . number_format((float) $i['grand_total'], 2) ?>
                                </td>

                                <td
                                    class="p-4 text-right font-bold <?= $balance > 0 ? 'text-amber-600' : 'text-slate-400' ?>">
                                        <?= h($i['symbol']) . number_format($balance, 2) ?>
                                </td>

                                <td class="p-4 text-center">
                                        <?php
                                        $badge = 'bg-slate-100 text-slate-700';
                                        if ($i['status'] === 'paid')
                                            $badge = 'bg-emerald-100 text-emerald-800';
                                        elseif ($i['status'] === 'partial')
                                            $badge = 'bg-amber-100 text-amber-800';
                                        elseif ($isOverdue)
                                            $badge = 'bg-rose-100 text-rose-800';
                                        elseif ($i['status'] === 'unpaid')
                                            $badge = 'bg-blue-50 text-blue-700';
                                        ?>
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold <?= $badge ?>">
                                            <?= h($isOverdue && $i['status'] !== 'paid' ? 'Overdue' : ucfirst($i['status'])) ?>
                                    </span>
                                </td>

                                <td class="p-4 text-right">
                                    <a href="view_invoice.php?id=<?= $i['id'] ?>"
                                        class="text-blue-600 hover:text-blue-800 font-semibold text-xs inline-flex items-center gap-1">
                                        View →
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$invoices): ?>
                            <tr>
                                <td colspan="7" class="p-12 text-center text-slate-500 text-sm">
                                    No invoices matched your current query or filter criteria.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</main>

<?php require_once '../includes/footer.php'; ?>