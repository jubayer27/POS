-- ==============================================================================
-- 0. SYSTEM RESET (WARNING: This wipes the old dev database and starts fresh)
-- ==============================================================================
DROP DATABASE IF EXISTS accountsbase;
CREATE DATABASE accountsbase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE accountsbase;

-- ==========================================
-- 1. GLOBAL SETTINGS & MULTI-CURRENCY
-- ==========================================
CREATE TABLE system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT,
    description VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE currencies (
    code CHAR(3) PRIMARY KEY, -- e.g., 'USD', 'BDT', 'MYR'
    name VARCHAR(50) NOT NULL,
    symbol VARCHAR(5) NOT NULL,
    exchange_rate DECIMAL(15, 6) NOT NULL DEFAULT 1.000000,
    is_base_currency BOOLEAN DEFAULT FALSE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE tax_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL, -- e.g., 'VAT 15%', 'Zero Rated', 'State Tax'
    rate_percentage DECIMAL(5, 2) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB;

-- ==========================================
-- 2. RBAC (ROLE-BASED ACCESS CONTROL)
-- ==========================================
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(50) NOT NULL, -- e.g., 'Invoices', 'Payroll', 'Settings'
    action VARCHAR(50) NOT NULL, -- e.g., 'create', 'view', 'delete'
    UNIQUE KEY unique_permission (module, action)
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'suspended') DEFAULT 'active',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- ==========================================
-- 3. THE GENERAL LEDGER (CHART OF ACCOUNTS)
-- ==========================================
CREATE TABLE chart_of_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_code VARCHAR(20) NOT NULL UNIQUE, -- e.g., 1000, 2000, 4000
    name VARCHAR(100) NOT NULL,
    type ENUM('Asset', 'Liability', 'Equity', 'Revenue', 'Expense') NOT NULL,
    sub_type VARCHAR(50), -- e.g., 'Current Asset', 'Cost of Goods Sold'
    is_system_account BOOLEAN DEFAULT FALSE, -- Protects critical accounts like Retained Earnings from deletion
    is_active BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE journal_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_date DATE NOT NULL,
    reference_number VARCHAR(100), -- e.g., 'INV-0001', 'PAYROLL-OCT'
    description TEXT,
    status ENUM('draft', 'posted', 'voided') DEFAULT 'posted',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE journal_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    journal_entry_id INT NOT NULL,
    account_id INT NOT NULL,
    contact_id INT, -- Optional: links the line to a specific customer/vendor
    description VARCHAR(255),
    debit DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    credit DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ==========================================
-- 4. ENTITIES (CONTACTS & ITEMS)
-- ==========================================
CREATE TABLE contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contact_type ENUM('customer', 'vendor', 'both') NOT NULL,
    company_name VARCHAR(150),
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    billing_address TEXT,
    tax_id_number VARCHAR(50),
    currency_code CHAR(3) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (currency_code) REFERENCES currencies(code)
) ENGINE=InnoDB;

CREATE TABLE items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_type ENUM('service', 'product', 'digital') NOT NULL,
    name VARCHAR(150) NOT NULL,
    sku VARCHAR(50) UNIQUE,
    description TEXT,
    unit_price DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    income_account_id INT NOT NULL, -- Where revenue goes when sold
    expense_account_id INT, -- Where costs go when purchased
    tax_rate_id INT, -- Default tax rate for this item
    FOREIGN KEY (income_account_id) REFERENCES chart_of_accounts(id),
    FOREIGN KEY (expense_account_id) REFERENCES chart_of_accounts(id),
    FOREIGN KEY (tax_rate_id) REFERENCES tax_rates(id)
) ENGINE=InnoDB;

-- ==========================================
-- 5. ACCOUNTS RECEIVABLE (INVOICES & PAYMENTS)
-- ==========================================
CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    contact_id INT NOT NULL,
    journal_entry_id INT, -- Link to the GL
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    currency_code CHAR(3) NOT NULL,
    exchange_rate DECIMAL(15, 6) NOT NULL DEFAULT 1.000000,
    subtotal DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    tax_total DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    grand_total DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    status ENUM('draft', 'sent', 'unpaid', 'partial', 'paid', 'voided') DEFAULT 'draft',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contact_id) REFERENCES contacts(id),
    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id),
    FOREIGN KEY (currency_code) REFERENCES currencies(code)
) ENGINE=InnoDB;

CREATE TABLE invoice_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item_id INT NOT NULL,
    description VARCHAR(255),
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1.00,
    unit_price DECIMAL(15, 2) NOT NULL,
    tax_rate_id INT,
    tax_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(15, 2) NOT NULL, -- (Qty * Price) + Tax
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id),
    FOREIGN KEY (tax_rate_id) REFERENCES tax_rates(id)
) ENGINE=InnoDB;

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_type ENUM('inbound', 'outbound') NOT NULL,
    contact_id INT NOT NULL,
    account_id INT NOT NULL, -- The bank/cash account receiving or sending the money
    journal_entry_id INT,
    payment_date DATE NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    payment_method VARCHAR(50), -- e.g., 'Bank Transfer', 'Credit Card', 'Cash'
    reference_number VARCHAR(100),
    FOREIGN KEY (contact_id) REFERENCES contacts(id),
    FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id),
    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id)
) ENGINE=InnoDB;

CREATE TABLE payment_applications (
    -- This table links a single payment to one or multiple invoices/bills
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    invoice_id INT,
    applied_amount DECIMAL(15, 2) NOT NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================
-- 6. HUMAN RESOURCES & PAYROLL
-- ==========================================
CREATE TABLE employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL, -- Optional link if the employee logs into the system
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) UNIQUE,
    phone VARCHAR(20),
    designation VARCHAR(100),
    base_salary DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    status ENUM('active', 'terminated', 'on_leave') DEFAULT 'active',
    hire_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payroll_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    month_year VARCHAR(7) NOT NULL, -- e.g., '2026-10'
    journal_entry_id INT, -- Link the entire payroll run to the ledger
    total_gross DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    total_net DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    status ENUM('draft', 'approved', 'paid') DEFAULT 'draft',
    processed_date DATE,
    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id)
) ENGINE=InnoDB;

CREATE TABLE payslips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payroll_run_id INT NOT NULL,
    employee_id INT NOT NULL,
    basic_salary DECIMAL(15, 2) NOT NULL,
    allowances DECIMAL(15, 2) DEFAULT 0.00,
    deductions DECIMAL(15, 2) DEFAULT 0.00,
    net_payable DECIMAL(15, 2) NOT NULL,
    status ENUM('unpaid', 'paid') DEFAULT 'unpaid',
    FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB;

-- ==========================================
-- 7. ESSENTIAL SYSTEM INDEXES for SPEED
-- ==========================================
CREATE INDEX idx_journal_date ON journal_entries(entry_date);
CREATE INDEX idx_invoice_status ON invoices(status);
CREATE INDEX idx_invoice_contact ON invoices(contact_id);
CREATE INDEX idx_coa_type ON chart_of_accounts(type);