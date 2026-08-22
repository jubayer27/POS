<?php
// admin/setup_enterprise.php
require_once '../config/db.php';

try {
    // Begin transaction
    $pdo->beginTransaction();

    // ==========================================
    // 1. Setup Base Currency
    // ==========================================
    $stmt_curr = $pdo->prepare("INSERT IGNORE INTO currencies (code, name, symbol, exchange_rate, is_base_currency) 
                                VALUES ('USD', 'US Dollar', '$', 1.000000, 1)");
    $stmt_curr->execute();

    // ==========================================
    // 2. Setup Default Tax Rates
    // ==========================================
    $pdo->exec("INSERT IGNORE INTO tax_rates (name, rate_percentage) VALUES 
                ('No Tax (0%)', 0.00),
                ('Standard VAT (10%)', 10.00),
                ('State Tax (5%)', 5.00)");

    // ==========================================
    // 3. Setup Standard Chart of Accounts (GL)
    // ==========================================
    // These are standard accounting codes used by CPAs globally
    $accounts = [
        // Assets (1000s)
        ['1000', 'Cash on Hand', 'Asset', 'Current Asset', 1],
        ['1010', 'Main Business Checking', 'Asset', 'Bank', 1],
        ['1200', 'Accounts Receivable (A/R)', 'Asset', 'Current Asset', 1],

        // Liabilities (2000s)
        ['2000', 'Accounts Payable (A/P)', 'Liability', 'Current Liability', 1],
        ['2100', 'Tax Payable', 'Liability', 'Current Liability', 1],
        ['2200', 'Payroll Liabilities', 'Liability', 'Current Liability', 1],

        // Equity (3000s)
        ['3000', 'Owner Equity', 'Equity', 'Equity', 1],
        ['3100', 'Retained Earnings', 'Equity', 'Equity', 1],

        // Revenue (4000s)
        ['4000', 'Product Sales Revenue', 'Revenue', 'Operating Revenue', 0],
        ['4100', 'Service Revenue', 'Revenue', 'Operating Revenue', 0],
        ['4900', 'Exchange Gain/Loss', 'Revenue', 'Other Revenue', 1],

        // Expenses (5000s)
        ['5000', 'Cost of Goods Sold (COGS)', 'Expense', 'Cost of Sales', 0],
        ['5100', 'Payroll & Salaries Expense', 'Expense', 'Operating Expense', 1],
        ['5200', 'Rent Expense', 'Expense', 'Operating Expense', 0],
        ['5300', 'Software & Subscriptions', 'Expense', 'Operating Expense', 0],
        ['5400', 'Marketing & Advertising', 'Expense', 'Operating Expense', 0],
        ['5500', 'Bank Fees & Charges', 'Expense', 'Operating Expense', 0]
    ];

    $stmt_coa = $pdo->prepare("INSERT IGNORE INTO chart_of_accounts (account_code, name, type, sub_type, is_system_account) 
                               VALUES (?, ?, ?, ?, ?)");
    foreach ($accounts as $acc) {
        $stmt_coa->execute($acc);
    }

    // ==========================================
    // 4. Setup Super Admin Role
    // ==========================================
    $pdo->exec("INSERT IGNORE INTO roles (name, description) VALUES ('Super Admin', 'Unrestricted access to all modules')");

    // Fetch the ID of the new Super Admin role
    $stmt_role = $pdo->query("SELECT id FROM roles WHERE name = 'Super Admin'");
    $role_id = $stmt_role->fetchColumn();

    // ==========================================
    // 5. Setup Admin User
    // ==========================================
    $username = 'admin';
    $password = 'admin123';
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $stmt_user = $pdo->prepare("INSERT IGNORE INTO users (role_id, username, email, password_hash) 
                                VALUES (?, ?, 'admin@company.com', ?)");
    $stmt_user->execute([$role_id, $username, $hashed_password]);

    // Commit everything to the database
    $pdo->commit();

    echo "<h3>✅ Enterprise Setup Complete!</h3>";
    echo "<p>Your foundational accounting data (Chart of Accounts, Tax Rates, Base Currency) has been injected.</p>";
    echo "<p><strong>Username:</strong> $username <br><strong>Password:</strong> $password</p>";
    echo "<p style='color:red;'><b>Important:</b> Please delete this <code>setup_enterprise.php</code> file immediately for security.</p>";
    echo "<a href='../index.php' style='display:inline-block; margin-top:15px; padding:10px 20px; background:blue; color:white; text-decoration:none; border-radius:5px;'>Go to Login</a>";

} catch (Exception $e) {
    // If anything fails, roll back the whole process
    $pdo->rollBack();
    die("<h3>❌ Setup Failed!</h3><p>" . $e->getMessage() . "</p>");
}
?>