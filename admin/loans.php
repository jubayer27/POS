<?php
// admin/loans.php
require_once '../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$success_msg = '';
$error_msg = '';

// ==========================================
// 1. POST ACTION: CREATE / DISBURSE NEW LOAN
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_loan'])) {
    verify_csrf();

    $lender_id = (int) ($_POST['lender_id'] ?? 0);
    $reference = trim((string) ($_POST['loan_reference'] ?? ''));
    $principal = round((float) ($_POST['principal_amount'] ?? 0), 2);
    $interest_rate = round((float) ($_POST['interest_rate'] ?? 0), 2);
    $term_months = (int) ($_POST['term_months'] ?? 12);
    $start_date = trim((string) ($_POST['start_date'] ?? ''));
    $bank_account_id = (int) ($_POST['disbursement_account_id'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if (!$lender_id || empty($reference) || $principal <= 0 || empty($start_date) || !$bank_account_id) {
        $error_msg = 'Please fill all required fields and enter a valid principal amount.';
    } else {
        try {
            $pdo->beginTransaction();

            // Fetch Bank Ledger Account
            $s_acc = $pdo->prepare("SELECT ledger_account_id FROM accounts WHERE id = ?");
            $s_acc->execute([$bank_account_id]);
            $bankLedgerId = $s_acc->fetchColumn();
            if (!$bankLedgerId) {
                $bankLedgerId = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1010'")->fetchColumn();
            }

            // Fetch Loan Liability Account (2500)
            $loanLiabAcc = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '2500'")->fetchColumn();
            if (!$loanLiabAcc) {
                throw new RuntimeException("Account '2500' (Bank Loans & Notes Payable) missing from Chart of Accounts.");
            }

            // 1. Post General Ledger Entry for Loan Inflow (Debit Bank, Credit Loan Liability)
            $journal = [
                ['account_id' => $bankLedgerId, 'contact_id' => $lender_id, 'description' => "Loan disbursement receipt: {$reference}", 'debit' => $principal, 'credit' => 0.00],
                ['account_id' => $loanLiabAcc, 'contact_id' => $lender_id, 'description' => "Loan liability recognized: {$reference}", 'debit' => 0.00, 'credit' => $principal]
            ];
            $je_id = post_journal($pdo, $start_date, $reference, "Loan disbursement: {$reference}", $journal, 'loan', null);

            // 2. Insert Loan Record
            $ins = $pdo->prepare("INSERT INTO loans (lender_id, loan_reference, principal_amount, interest_rate, term_months, start_date, disbursement_account_id, journal_entry_id, notes, status) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $ins->execute([
                $lender_id,
                $reference,
                $principal,
                $interest_rate,
                $term_months,
                $start_date,
                $bank_account_id,
                $je_id,
                $notes
            ]);

            $pdo->commit();
            header("Location: loans.php?saved=loan");
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            $error_msg = ($e instanceof PDOException && $e->getCode() === '23000')
                ? 'That loan reference number already exists.'
                : $e->getMessage();
        }
    }
}

// ==========================================
// 2. POST ACTION: RECORD LOAN REPAYMENT
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_repayment'])) {
    verify_csrf();

    $loan_id = (int) ($_POST['loan_id'] ?? 0);
    $payment_date = trim((string) ($_POST['payment_date'] ?? date('Y-m-d')));
    $principal_paid = round((float) ($_POST['principal_paid'] ?? 0), 2);
    $interest_paid = round((float) ($_POST['interest_paid'] ?? 0), 2);
    $source_acc_id = (int) ($_POST['source_account_id'] ?? 0);
    $reference_number = trim((string) ($_POST['reference_number'] ?? ''));

    $total_repayment = round($principal_paid + $interest_paid, 2);

    if (!$loan_id || $principal_paid < 0 || $interest_paid < 0 || $total_repayment <= 0 || !$source_acc_id) {
        $error_msg = 'Invalid repayment amounts. Principal or interest must be greater than zero.';
    } else {
        try {
            $pdo->beginTransaction();

            $s_loan = $pdo->prepare("SELECT * FROM loans WHERE id = ? FOR UPDATE");
            $s_loan->execute([$loan_id]);
            $loan = $s_loan->fetch();

            if (!$loan || $loan['status'] === 'paid_off') {
                throw new RuntimeException('This loan is not active or is already paid off.');
            }

            $remainingPrincipal = round((float) $loan['principal_amount'] - (float) $loan['amount_repaid'], 2);
            if ($principal_paid > $remainingPrincipal) {
                throw new RuntimeException("Principal repayment ({$principal_paid}) exceeds remaining loan balance ({$remainingPrincipal}).");
            }

            // Fetch Bank Ledger Account
            $s_acc = $pdo->prepare("SELECT ledger_account_id FROM accounts WHERE id = ?");
            $s_acc->execute([$source_acc_id]);
            $bankLedgerId = $s_acc->fetchColumn() ?: (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '1010'")->fetchColumn();

            $loanLiabAcc = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '2500'")->fetchColumn();
            $interestExpAcc = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '5600'")->fetchColumn();

            // Build General Ledger Balancing Journal (Debit Liability, Debit Interest, Credit Bank)
            $journal = [];
            if ($principal_paid > 0) {
                $journal[] = ['account_id' => $loanLiabAcc, 'contact_id' => $loan['lender_id'], 'description' => "Principal payment: {$loan['loan_reference']}", 'debit' => $principal_paid, 'credit' => 0.00];
            }
            if ($interest_paid > 0) {
                $journal[] = ['account_id' => $interestExpAcc, 'contact_id' => $loan['lender_id'], 'description' => "Loan interest expense: {$loan['loan_reference']}", 'debit' => $interest_paid, 'credit' => 0.00];
            }
            $journal[] = ['account_id' => $bankLedgerId, 'contact_id' => $loan['lender_id'], 'description' => "Loan installment disbursement: {$loan['loan_reference']}", 'debit' => 0.00, 'credit' => $total_repayment];

            $je_id = post_journal($pdo, $payment_date, $reference_number ?: "REPAY-{$loan['loan_reference']}", "Loan installment for {$loan['loan_reference']}", $journal, 'loan_repayment', $loan_id);

            // Insert Repayment Record
            $ins_p = $pdo->prepare("INSERT INTO loan_payments (loan_id, payment_date, principal_amount, interest_amount, total_amount, source_account_id, journal_entry_id, reference_number) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins_p->execute([
                $loan_id,
                $payment_date,
                $principal_paid,
                $interest_paid,
                $total_repayment,
                $source_acc_id,
                $je_id,
                $reference_number
            ]);

            // Update Loan Principal Repaid & Status
            $newRepaid = round((float) $loan['amount_repaid'] + $principal_paid, 2);
            $newStatus = ($newRepaid >= (float) $loan['principal_amount']) ? 'paid_off' : 'active';

            $pdo->prepare("UPDATE loans SET amount_repaid = ?, status = ? WHERE id = ?")
                ->execute([$newRepaid, $newStatus, $loan_id]);

            $pdo->commit();
            header("Location: loans.php?saved=payment");
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            $error_msg = $e->getMessage();
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === 'loan') {
    $success_msg = 'Loan registered, funds disbursed to bank, and liability posted to General Ledger.';
} elseif (isset($_GET['saved']) && $_GET['saved'] === 'payment') {
    $success_msg = 'Repayment recorded and interest expense booked successfully.';
}

// 3. Fetch Data for Views
$lenders = $pdo->query("SELECT id, company_name FROM contacts WHERE contact_type IN ('vendor', 'both') ORDER BY company_name ASC")->fetchAll();
$bankAccounts = $pdo->query("SELECT id, account_name, account_type FROM accounts WHERE is_active = 1 ORDER BY account_name ASC")->fetchAll();

$loans = $pdo->query("
    SELECT l.*, c.company_name AS lender_name, a.account_name AS disbursement_bank,
           (SELECT COALESCE(SUM(interest_amount), 0) FROM loan_payments lp WHERE lp.loan_id = l.id) AS total_interest_paid
    FROM loans l
    JOIN contacts c ON c.id = l.lender_id
    JOIN accounts a ON a.id = l.disbursement_account_id
    ORDER BY l.created_at DESC
")->fetchAll();

// Aggregated KPIs
$kpis = $pdo->query("
    SELECT 
        COALESCE(SUM(principal_amount), 0) AS total_borrowed,
        COALESCE(SUM(amount_repaid), 0) AS total_principal_repaid,
        COALESCE(SUM(CASE WHEN status = 'active' THEN (principal_amount - amount_repaid) ELSE 0 END), 0) AS outstanding_liability,
        (SELECT COALESCE(SUM(interest_amount), 0) FROM loan_payments) AS total_interest_expensed
    FROM loans
")->fetch();

require_once 'includes/header.php';
require_once 'includes/sidebar.php';
$input = 'mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white outline-none focus:ring-2 focus:ring-blue-500';
?>

<main class="flex-1 h-screen overflow-y-auto app-scroll bg-slate-100">
    <!-- Header -->
    <header class="bg-white border-b px-5 md:px-8 py-5 flex items-center justify-between sticky top-0 z-10 shadow-sm">
        <div class="flex items-center gap-4">
            <button data-sidebar-toggle class="lg:hidden text-2xl text-slate-700">☰</button>
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Loans & Borrowing</h2>
                <p class="text-xs text-slate-500">Track liabilities, debt amortization schedules, and interest expenses</p>
            </div>
        </div>
    </header>

    <div class="p-5 md:p-8 max-w-7xl mx-auto space-y-6">
        
        <!-- Alerts -->
        <?php if ($success_msg): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-xl shadow-sm text-sm">
                    <?= htmlspecialchars($success_msg) ?>
                </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-xl shadow-sm text-sm">
                    <?= htmlspecialchars($error_msg) ?>
                </div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Borrowed</p>
                <p class="text-2xl font-black text-slate-900 mt-1"><?= money($pdo, $kpis['total_borrowed']) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Cumulative Principal</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Active Debt Liability</p>
                <p class="text-2xl font-black text-rose-600 mt-1"><?= money($pdo, $kpis['outstanding_liability']) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Remaining Principal Due</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Principal Repaid</p>
                <p class="text-2xl font-black text-emerald-600 mt-1"><?= money($pdo, $kpis['total_principal_repaid']) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Settled Liability</p>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Interest Expensed</p>
                <p class="text-2xl font-black text-amber-600 mt-1"><?= money($pdo, $kpis['total_interest_expensed']) ?></p>
                <p class="text-[11px] text-slate-500 mt-1">Finance Costs (GL 5600)</p>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-7">

            <!-- Left Column: Add Loan & Record Repayment Forms -->
            <div class="xl:col-span-1 space-y-6">
                
                <!-- Create Loan Card -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-4">
                    <div class="border-b pb-3">
                        <h3 class="font-bold text-base text-slate-900">Record New Loan</h3>
                        <p class="text-xs text-slate-400">Disburses cash to bank & credits liability</p>
                    </div>

                    <form action="loans.php" method="POST" class="space-y-3">
                        <?= csrf_field() ?>

                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Lender / Financial Institution *</label>
                            <select name="lender_id" required class="<?= $input ?>">
                                <option value="">-- Select Lender (Vendor) --</option>
                                <?php foreach ($lenders as $len): ?>
                                        <option value="<?= $len['id'] ?>"><?= h($len['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Loan Reference # *</label>
                            <input name="loan_reference" required placeholder="e.g. LOAN-2026-01" class="<?= $input ?>">
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Principal ($) *</label>
                                <input type="number" step="0.01" min="1" name="principal_amount" required placeholder="10000.00" class="<?= $input ?> font-bold">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Interest Rate (%)</label>
                                <input type="number" step="0.01" min="0" name="interest_rate" value="5.00" class="<?= $input ?>">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Term (Months)</label>
                                <input type="number" min="1" name="term_months" value="12" class="<?= $input ?>">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Disbursed Date *</label>
                                <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required class="<?= $input ?>">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Deposit To Bank Account *</label>
                            <select name="disbursement_account_id" required class="<?= $input ?>">
                                <?php foreach ($bankAccounts as $ba): ?>
                                        <option value="<?= $ba['id'] ?>"><?= h($ba['account_name']) ?> (<?= ucfirst($ba['account_type']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Notes / Terms</label>
                            <textarea name="notes" rows="2" placeholder="Collateral details, amortization conditions..." class="<?= $input ?>"></textarea>
                        </div>

                        <button type="submit" name="create_loan" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 rounded-xl transition-colors shadow-sm text-sm mt-2">
                            Receive Loan & Post GL
                        </button>
                    </form>
                </div>

                <!-- Record Repayment Card -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 space-y-4">
                    <div class="border-b pb-3">
                        <h3 class="font-bold text-base text-slate-900">Record Installment / Repayment</h3>
                        <p class="text-xs text-slate-400">Splits principal liability & interest expense</p>
                    </div>

                    <form action="loans.php" method="POST" class="space-y-3">
                        <?= csrf_field() ?>

                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Target Loan *</label>
                            <select name="loan_id" required class="<?= $input ?>">
                                <option value="">-- Choose Active Loan --</option>
                                <?php foreach ($loans as $l):
                                    if ($l['status'] !== 'active')
                                        continue; ?>
                                        <option value="<?= $l['id'] ?>">
                                            <?= h($l['loan_reference']) ?> (<?= h($l['lender_name']) ?> - Bal: <?= money($pdo, $l['principal_amount'] - $l['amount_repaid']) ?>)
                                        </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Principal Repaid ($)</label>
                                <input type="number" step="0.01" min="0" name="principal_paid" required value="0.00" class="<?= $input ?> font-bold">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Interest Paid ($)</label>
                                <input type="number" step="0.01" min="0" name="interest_paid" value="0.00" class="<?= $input ?> font-bold text-amber-600">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Payment Date *</label>
                            <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required class="<?= $input ?>">
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Paid From Bank Account *</label>
                            <select name="source_account_id" required class="<?= $input ?>">
                                <?php foreach ($bankAccounts as $ba): ?>
                                        <option value="<?= $ba['id'] ?>"><?= h($ba['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Transaction Ref #</label>
                            <input name="reference_number" placeholder="e.g. Wire confirmation, Cheque #" class="<?= $input ?>">
                        </div>

                        <button type="submit" name="record_repayment" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-xl transition-colors shadow-sm text-sm mt-2">
                            Disburse Installment
                        </button>
                    </form>
                </div>

            </div>

            <!-- Right Column: Loans Registry -->
            <div class="xl:col-span-2">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/70 flex justify-between items-center">
                        <h3 class="font-bold text-slate-900 text-sm">Active & Historical Loan Facilities</h3>
                        <span class="text-xs text-slate-500"><?= count($loans) ?> Facilities</span>
                    </div>

                    <div class="overflow-x-auto max-h-[720px]">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead class="sticky top-0 bg-slate-50 text-slate-600 text-xs border-b">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">Reference & Lender</th>
                                    <th class="px-5 py-3 font-semibold">Terms</th>
                                    <th class="px-5 py-3 font-semibold text-right">Principal</th>
                                    <th class="px-5 py-3 font-semibold text-right">Remaining Balance</th>
                                    <th class="px-5 py-3 font-semibold text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (count($loans) > 0): ?>
                                        <?php foreach ($loans as $l):
                                            $remaining = (float) $l['principal_amount'] - (float) $l['amount_repaid'];
                                            ?>
                                                <tr class="hover:bg-slate-50/80 transition-colors">
                                                    <td class="px-5 py-4">
                                                        <p class="font-bold text-slate-900"><?= h($l['loan_reference']) ?></p>
                                                        <p class="text-xs text-slate-500 mt-0.5"><?= h($l['lender_name']) ?></p>
                                                    </td>
                                                    <td class="px-5 py-4 text-xs text-slate-600">
                                                        <p><?= floatval($l['interest_rate']) ?>% APR · <?= $l['term_months'] ?> mos</p>
                                                        <p class="text-slate-400 mt-0.5">Start: <?= date('M j, Y', strtotime($l['start_date'])) ?></p>
                                                    </td>
                                                    <td class="px-5 py-4 text-right font-semibold text-slate-800">
                                                        <?= money($pdo, $l['principal_amount']) ?>
                                                    </td>
                                                    <td class="px-5 py-4 text-right font-black <?= $remaining > 0 ? 'text-rose-600' : 'text-slate-400' ?>">
                                                        <?= money($pdo, $remaining) ?>
                                                    </td>
                                                    <td class="px-5 py-4 text-center">
                                                        <?php if ($l['status'] === 'active'): ?>
                                                                <span class="px-2.5 py-1 inline-flex rounded-full text-xs font-bold bg-amber-100 text-amber-800">Active</span>
                                                        <?php elseif ($l['status'] === 'paid_off'): ?>
                                                                <span class="px-2.5 py-1 inline-flex rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">Paid Off</span>
                                                        <?php else: ?>
                                                                <span class="px-2.5 py-1 inline-flex rounded-full text-xs font-bold bg-rose-100 text-rose-800"><?= ucfirst($l['status']) ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                        <?php endforeach; ?>
                                <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="p-12 text-center text-slate-500 text-sm">
                                                No loan records found. Register your first loan facility on the left.
                                            </td>
                                        </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>