-- AccountsBase complete installation schema (MySQL 8+/MariaDB 10.5+)
-- WARNING: importing this file replaces the accountsbase database.
DROP DATABASE IF EXISTS accountsbase;
CREATE DATABASE accountsbase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE accountsbase;

CREATE TABLE system_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NULL, description VARCHAR(255) NULL) ENGINE=InnoDB;
CREATE TABLE roles (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL UNIQUE, description VARCHAR(255) NULL) ENGINE=InnoDB;
CREATE TABLE users (
 id INT AUTO_INCREMENT PRIMARY KEY, role_id INT NULL, username VARCHAR(50) NOT NULL UNIQUE, full_name VARCHAR(120) NOT NULL DEFAULT '',
 email VARCHAR(120) NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(30) NOT NULL DEFAULT 'admin',
 status ENUM('active','suspended') NOT NULL DEFAULT 'active', last_login DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE permissions (id INT AUTO_INCREMENT PRIMARY KEY, module VARCHAR(50) NOT NULL, action VARCHAR(50) NOT NULL, UNIQUE KEY uq_permission(module,action)) ENGINE=InnoDB;
CREATE TABLE role_permissions (role_id INT NOT NULL, permission_id INT NOT NULL, PRIMARY KEY(role_id,permission_id), FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE, FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE) ENGINE=InnoDB;

CREATE TABLE currencies (code CHAR(3) PRIMARY KEY, name VARCHAR(50) NOT NULL, symbol VARCHAR(8) NOT NULL, exchange_rate DECIMAL(18,6) NOT NULL DEFAULT 1, is_base_currency TINYINT(1) NOT NULL DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE tax_rates (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL UNIQUE, rate_percentage DECIMAL(7,3) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB;
CREATE TABLE chart_of_accounts (
 id INT AUTO_INCREMENT PRIMARY KEY, account_code VARCHAR(20) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL,
 type ENUM('Asset','Liability','Equity','Revenue','Expense') NOT NULL, sub_type VARCHAR(50) NULL,
 is_system_account TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, INDEX idx_coa_type(type)
) ENGINE=InnoDB;
CREATE TABLE accounts (
 id INT AUTO_INCREMENT PRIMARY KEY, ledger_account_id INT NULL, account_name VARCHAR(120) NOT NULL,
 account_type ENUM('bank','cash','mobile_money') NOT NULL, account_number VARCHAR(80) NULL,
 current_balance DECIMAL(18,2) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(ledger_account_id) REFERENCES chart_of_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE contacts (
 id INT AUTO_INCREMENT PRIMARY KEY, contact_type ENUM('customer','vendor','both') NOT NULL, contact_type_id INT NULL, company_name VARCHAR(150) NOT NULL,
 contact_person VARCHAR(100) NULL, email VARCHAR(120) NULL, phone VARCHAR(30) NULL, billing_address TEXT NULL,
 tax_id_number VARCHAR(50) NULL, currency_code CHAR(3) NOT NULL DEFAULT 'USD', opening_balance DECIMAL(18,2) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(currency_code) REFERENCES currencies(code)
) ENGINE=InnoDB;
CREATE TABLE contact_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE, base_kind ENUM('customer','vendor','both') NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
ALTER TABLE contacts ADD FOREIGN KEY(contact_type_id) REFERENCES contact_types(id) ON DELETE SET NULL;
CREATE TABLE item_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE, base_kind ENUM('product','service','digital') NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE items (
 id INT AUTO_INCREMENT PRIMARY KEY, item_type ENUM('service','product','digital') NOT NULL, item_type_id INT NULL, name VARCHAR(150) NOT NULL,
 sku VARCHAR(50) NULL UNIQUE, description TEXT NULL, unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
 income_account_id INT NOT NULL, expense_account_id INT NULL, tax_rate_id INT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
 FOREIGN KEY(income_account_id) REFERENCES chart_of_accounts(id), FOREIGN KEY(expense_account_id) REFERENCES chart_of_accounts(id), FOREIGN KEY(tax_rate_id) REFERENCES tax_rates(id), FOREIGN KEY(item_type_id) REFERENCES item_types(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE item_prices (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, currency_code CHAR(3) NOT NULL, unit_price DECIMAL(18,2) NOT NULL DEFAULT 0, UNIQUE KEY uq_item_currency(item_id,currency_code), FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE, FOREIGN KEY(currency_code) REFERENCES currencies(code)) ENGINE=InnoDB;

CREATE TABLE journal_entries (
 id INT AUTO_INCREMENT PRIMARY KEY, entry_date DATE NOT NULL, reference_number VARCHAR(100) NULL, description TEXT NULL,
 status ENUM('draft','posted','voided') NOT NULL DEFAULT 'posted', source_type VARCHAR(30) NOT NULL DEFAULT 'manual', source_id INT NULL,
 created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL, INDEX idx_journal_date(entry_date)
) ENGINE=InnoDB;
CREATE TABLE journal_lines (
 id INT AUTO_INCREMENT PRIMARY KEY, journal_entry_id INT NOT NULL, account_id INT NOT NULL, contact_id INT NULL,
 description VARCHAR(255) NULL, debit DECIMAL(18,2) NOT NULL DEFAULT 0, credit DECIMAL(18,2) NOT NULL DEFAULT 0,
 FOREIGN KEY(journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE, FOREIGN KEY(account_id) REFERENCES chart_of_accounts(id), FOREIGN KEY(contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE invoices (
 id INT AUTO_INCREMENT PRIMARY KEY, invoice_number VARCHAR(50) NOT NULL UNIQUE, contact_id INT NOT NULL, invoice_template_id INT NULL, journal_entry_id INT NULL,
 issue_date DATE NOT NULL, due_date DATE NOT NULL, currency_code CHAR(3) NOT NULL DEFAULT 'USD', exchange_rate DECIMAL(18,6) NOT NULL DEFAULT 1,
 subtotal DECIMAL(18,2) NOT NULL DEFAULT 0, tax_total DECIMAL(18,2) NOT NULL DEFAULT 0, discount_total DECIMAL(18,2) NOT NULL DEFAULT 0,
 grand_total DECIMAL(18,2) NOT NULL DEFAULT 0, amount_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
 status ENUM('draft','sent','unpaid','partial','paid','voided') NOT NULL DEFAULT 'unpaid', notes TEXT NULL, terms TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(contact_id) REFERENCES contacts(id), FOREIGN KEY(journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL,
 FOREIGN KEY(currency_code) REFERENCES currencies(code), INDEX idx_invoice_status(status), INDEX idx_invoice_contact(contact_id)
) ENGINE=InnoDB;
CREATE TABLE invoice_templates (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, is_default TINYINT(1) NOT NULL DEFAULT 0, logo_path VARCHAR(255) NULL, primary_color VARCHAR(7) NOT NULL DEFAULT '#2563eb', accent_color VARCHAR(7) NOT NULL DEFAULT '#0f172a', font_family VARCHAR(60) NOT NULL DEFAULT 'Helvetica', column_labels JSON NOT NULL, block_order JSON NOT NULL, bank_details TEXT NULL, custom_details TEXT NULL, footer_text TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB;
ALTER TABLE invoices ADD FOREIGN KEY(invoice_template_id) REFERENCES invoice_templates(id) ON DELETE SET NULL;
CREATE TABLE invoice_lines (
 id INT AUTO_INCREMENT PRIMARY KEY, invoice_id INT NOT NULL, item_id INT NULL, description VARCHAR(255) NOT NULL,
 quantity DECIMAL(12,3) NOT NULL DEFAULT 1, unit_price DECIMAL(18,2) NOT NULL, tax_rate DECIMAL(7,3) NOT NULL DEFAULT 0,
 tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0, line_total DECIMAL(18,2) NOT NULL,
 FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE CASCADE, FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE payments (
 id INT AUTO_INCREMENT PRIMARY KEY, invoice_id INT NULL, contact_id INT NULL, account_id INT NOT NULL,
 payment_type ENUM('inbound','outbound') NOT NULL, payment_date DATE NOT NULL, amount DECIMAL(18,2) NOT NULL,
 payment_method VARCHAR(50) NULL, reference_number VARCHAR(100) NULL, notes TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE SET NULL, FOREIGN KEY(contact_id) REFERENCES contacts(id) ON DELETE SET NULL, FOREIGN KEY(account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

CREATE TABLE expense_categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB;
CREATE TABLE expenses (id INT AUTO_INCREMENT PRIMARY KEY, account_id INT NOT NULL, category_id INT NULL, category VARCHAR(100) NOT NULL, amount DECIMAL(18,2) NOT NULL, currency_code CHAR(3) NOT NULL DEFAULT 'USD', exchange_rate DECIMAL(18,6) NOT NULL DEFAULT 1, base_amount DECIMAL(18,2) NOT NULL DEFAULT 0, expense_date DATE NOT NULL, reference_note TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(account_id) REFERENCES accounts(id), FOREIGN KEY(category_id) REFERENCES expense_categories(id) ON DELETE SET NULL, FOREIGN KEY(currency_code) REFERENCES currencies(code)) ENGINE=InnoDB;
CREATE TABLE account_field_definitions (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(100) NOT NULL, field_key VARCHAR(100) NOT NULL UNIQUE, field_type ENUM('text','textarea','number','date') NOT NULL DEFAULT 'text', sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB;
CREATE TABLE account_field_values (account_id INT NOT NULL, field_definition_id INT NOT NULL, field_value TEXT NULL, PRIMARY KEY(account_id,field_definition_id), FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE, FOREIGN KEY(field_definition_id) REFERENCES account_field_definitions(id) ON DELETE CASCADE) ENGINE=InnoDB;
CREATE TABLE debits (id INT AUTO_INCREMENT PRIMARY KEY, account_id INT NOT NULL, payee_name VARCHAR(150) NOT NULL, amount DECIMAL(18,2) NOT NULL, debit_date DATE NOT NULL, description TEXT NULL, status ENUM('pending','cleared','cancelled') NOT NULL DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(account_id) REFERENCES accounts(id)) ENGINE=InnoDB;
CREATE TABLE loans (
 id INT AUTO_INCREMENT PRIMARY KEY, account_id INT NOT NULL, lender_name VARCHAR(150) NOT NULL, loan_type ENUM('borrowed','lent') NOT NULL DEFAULT 'borrowed',
 principal_amount DECIMAL(18,2) NOT NULL, outstanding_amount DECIMAL(18,2) NOT NULL, annual_interest_rate DECIMAL(7,3) NOT NULL DEFAULT 0,
 start_date DATE NOT NULL, due_date DATE NULL, status ENUM('active','settled','defaulted') NOT NULL DEFAULT 'active', notes TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;
CREATE TABLE loan_payments (id INT AUTO_INCREMENT PRIMARY KEY, loan_id INT NOT NULL, account_id INT NOT NULL, payment_date DATE NOT NULL, amount DECIMAL(18,2) NOT NULL, principal_component DECIMAL(18,2) NOT NULL DEFAULT 0, interest_component DECIMAL(18,2) NOT NULL DEFAULT 0, notes TEXT NULL, FOREIGN KEY(loan_id) REFERENCES loans(id) ON DELETE CASCADE, FOREIGN KEY(account_id) REFERENCES accounts(id)) ENGINE=InnoDB;
CREATE TABLE investments (id INT AUTO_INCREMENT PRIMARY KEY, account_id INT NOT NULL, investment_title VARCHAR(150) NOT NULL, amount DECIMAL(18,2) NOT NULL, investment_date DATE NOT NULL, expected_return_rate DECIMAL(7,3) NOT NULL DEFAULT 0, status ENUM('active','matured','sold') NOT NULL DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(account_id) REFERENCES accounts(id)) ENGINE=InnoDB;

CREATE TABLE employees (
 id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, employee_number VARCHAR(30) NULL UNIQUE,
 first_name VARCHAR(50) NOT NULL, last_name VARCHAR(50) NOT NULL, email VARCHAR(120) NULL UNIQUE, personal_email VARCHAR(120) NULL,
 phone VARCHAR(30) NULL, alternate_phone VARCHAR(30) NULL, date_of_birth DATE NULL, gender VARCHAR(30) NULL, marital_status VARCHAR(30) NULL,
 address TEXT NULL, city VARCHAR(80) NULL, state VARCHAR(80) NULL, postal_code VARCHAR(20) NULL, country VARCHAR(80) NULL,
 emergency_contact_name VARCHAR(120) NULL, emergency_contact_relationship VARCHAR(60) NULL, emergency_contact_phone VARCHAR(30) NULL,
 designation VARCHAR(100) NOT NULL, department VARCHAR(100) NULL, employment_type ENUM('full_time','part_time','contract','intern','temporary') NOT NULL DEFAULT 'full_time',
 work_location VARCHAR(120) NULL, manager_name VARCHAR(120) NULL, base_salary DECIMAL(18,2) NOT NULL DEFAULT 0,
 joined_date DATE NOT NULL, probation_end_date DATE NULL, end_date DATE NULL,
 bank_name VARCHAR(120) NULL, bank_account_name VARCHAR(120) NULL, bank_account_number VARCHAR(80) NULL, bank_routing_number VARCHAR(80) NULL,
 tax_id VARCHAR(80) NULL, national_id VARCHAR(80) NULL, notes TEXT NULL,
 status ENUM('active','inactive','terminated','on_leave') NOT NULL DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE payslips (id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, month_year CHAR(7) NOT NULL, basic_salary DECIMAL(18,2) NOT NULL, allowances DECIMAL(18,2) NOT NULL DEFAULT 0, deductions DECIMAL(18,2) NOT NULL DEFAULT 0, net_payable DECIMAL(18,2) NOT NULL, payment_date DATE NULL, status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_employee_period(employee_id,month_year), FOREIGN KEY(employee_id) REFERENCES employees(id)) ENGINE=InnoDB;

INSERT INTO roles(name,description) VALUES ('Super Admin','Unrestricted access'),('Accountant','Accounting access'),('Viewer','Read-only access');
INSERT INTO currencies VALUES ('USD','US Dollar','$',1,1,NOW()),('BDT','Bangladeshi Taka','৳',109,0,NOW()),('EUR','Euro','€',0.92,0,NOW()),('GBP','Pound Sterling','£',0.79,0,NOW());
INSERT INTO tax_rates(name,rate_percentage) VALUES ('No Tax',0),('VAT 5%',5),('VAT 10%',10),('VAT 15%',15);
INSERT INTO contact_types(name,base_kind) VALUES ('Customer','customer'),('Vendor','vendor'),('Customer & Vendor','both'),('Wholesale Customer','customer');
INSERT INTO item_types(name,base_kind) VALUES ('Product','product'),('Service','service'),('Digital Good','digital');
INSERT INTO expense_categories(name) VALUES ('Rent'),('Utilities'),('Travel'),('Office supplies'),('Marketing'),('Software'),('Professional fees'),('Other');
INSERT INTO account_field_definitions(label,field_key,field_type,sort_order) VALUES ('Account number','account_number','text',10),('Branch','branch','text',20),('SWIFT / routing code','routing_code','text',30);
INSERT INTO invoice_templates(name,is_default,column_labels,block_order,bank_details,footer_text) VALUES ('Modern',1,'{"description":"Description","quantity":"Qty","unit_price":"Rate","tax":"Tax","amount":"Amount"}','["company","invoice_meta","customer","items","totals","bank","notes"]','Bank: Main Business Account\nAccount: Configure in template settings','Thank you for your business.');
INSERT INTO chart_of_accounts(account_code,name,type,sub_type,is_system_account) VALUES
('1000','Cash on Hand','Asset','Cash',1),('1010','Business Bank','Asset','Bank',1),('1200','Accounts Receivable','Asset','Current Asset',1),('1500','Investments','Asset','Non-current Asset',1),
('2000','Accounts Payable','Liability','Current Liability',1),('2100','Tax Payable','Liability','Current Liability',1),('2300','Loans Payable','Liability','Non-current Liability',1),('3000','Owner Equity','Equity','Equity',1),('3100','Retained Earnings','Equity','Equity',1),
('4000','Sales Revenue','Revenue','Operating Revenue',1),('4100','Service Revenue','Revenue','Operating Revenue',0),('5000','Cost of Goods Sold','Expense','Cost of Sales',0),
('5100','Payroll Expense','Expense','Operating Expense',1),('5200','Rent Expense','Expense','Operating Expense',0),('5300','General Expense','Expense','Operating Expense',1),('5400','Interest Expense','Expense','Finance Cost',1);
INSERT INTO accounts(ledger_account_id,account_name,account_type,current_balance) SELECT id,'Main Business Account','bank',0 FROM chart_of_accounts WHERE account_code='1010';
INSERT INTO system_settings(setting_key,setting_value,description) VALUES
('company_name','AccountsBase Demo Company','Displayed business name'),('company_email','accounts@example.com','Company email'),('company_phone','','Company phone'),('company_address','','Company address'),('company_tax_id','','Tax identifier'),
('base_currency','USD','Reporting currency'),('currency_symbol','$','Currency symbol'),('invoice_prefix','INV-','Invoice prefix'),('invoice_next_number','1001','Next invoice sequence'),('invoice_terms','Payment is due by the date shown.','Default invoice terms'),
('fiscal_year_start','01-01','Fiscal year start'),('date_format','M d, Y','PHP date format'),('theme_color','#2563eb','Brand color'),
('module_invoices','1','Module toggle'),('module_contacts','1','Module toggle'),('module_items','1','Module toggle'),('module_expenses','1','Module toggle'),('module_payables','1','Module toggle'),('module_loans','1','Module toggle'),
('module_investments','1','Module toggle'),('module_payroll','1','Module toggle'),('module_journal','1','Module toggle'),('module_cashflow','1','Module toggle'),('module_reports','1','Module toggle');
INSERT INTO users(role_id,username,full_name,email,password_hash,role) VALUES (1,'admin','System Administrator','admin@example.com','$2y$12$gP6NawR4d8qNjbvj7k3QxegWhWN5SCBuhjGzjyYiPwxlXrR3wpZDa','admin');
-- Default password: admin123. Change it immediately after signing in.
