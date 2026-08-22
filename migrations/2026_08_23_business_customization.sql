USE accountsbase;
CREATE TABLE contact_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE, base_kind ENUM('customer','vendor','both') NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
INSERT INTO contact_types(name,base_kind) VALUES ('Customer','customer'),('Vendor','vendor'),('Customer & Vendor','both'),('Wholesale Customer','customer');
ALTER TABLE contacts ADD COLUMN contact_type_id INT NULL AFTER contact_type, ADD CONSTRAINT fk_contacts_custom_type FOREIGN KEY(contact_type_id) REFERENCES contact_types(id) ON DELETE SET NULL;
UPDATE contacts c JOIN contact_types t ON t.base_kind=c.contact_type AND ((c.contact_type='customer' AND t.name='Customer') OR (c.contact_type='vendor' AND t.name='Vendor') OR (c.contact_type='both' AND t.name='Customer & Vendor')) SET c.contact_type_id=t.id;

CREATE TABLE item_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE, base_kind ENUM('product','service','digital') NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
INSERT INTO item_types(name,base_kind) VALUES ('Product','product'),('Service','service'),('Digital Good','digital');
ALTER TABLE items ADD COLUMN item_type_id INT NULL AFTER item_type, ADD CONSTRAINT fk_items_custom_type FOREIGN KEY(item_type_id) REFERENCES item_types(id) ON DELETE SET NULL;
UPDATE items i JOIN item_types t ON t.base_kind=i.item_type SET i.item_type_id=t.id;
CREATE TABLE item_prices (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, currency_code CHAR(3) NOT NULL, unit_price DECIMAL(18,2) NOT NULL DEFAULT 0, UNIQUE KEY uq_item_currency(item_id,currency_code), FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE, FOREIGN KEY(currency_code) REFERENCES currencies(code)) ENGINE=InnoDB;
INSERT INTO item_prices(item_id,currency_code,unit_price) SELECT id,'USD',unit_price FROM items;

CREATE TABLE invoice_templates (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, is_default TINYINT(1) NOT NULL DEFAULT 0, logo_path VARCHAR(255) NULL, primary_color VARCHAR(7) NOT NULL DEFAULT '#2563eb', accent_color VARCHAR(7) NOT NULL DEFAULT '#0f172a', font_family VARCHAR(60) NOT NULL DEFAULT 'Helvetica', column_labels JSON NOT NULL, block_order JSON NOT NULL, bank_details TEXT NULL, custom_details TEXT NULL, footer_text TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB;
INSERT INTO invoice_templates(name,is_default,column_labels,block_order,bank_details,footer_text) VALUES ('Modern',1,'{"description":"Description","quantity":"Qty","unit_price":"Rate","tax":"Tax","amount":"Amount"}','["company","invoice_meta","customer","items","totals","bank","notes"]','Bank: Main Business Account\nAccount: Configure in template settings','Thank you for your business.');
ALTER TABLE invoices ADD COLUMN invoice_template_id INT NULL AFTER contact_id, ADD CONSTRAINT fk_invoices_template FOREIGN KEY(invoice_template_id) REFERENCES invoice_templates(id) ON DELETE SET NULL;

CREATE TABLE expense_categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB;
INSERT INTO expense_categories(name) VALUES ('Rent'),('Utilities'),('Travel'),('Office supplies'),('Marketing'),('Software'),('Professional fees'),('Other');
ALTER TABLE expenses ADD COLUMN category_id INT NULL AFTER account_id, ADD COLUMN currency_code CHAR(3) NOT NULL DEFAULT 'USD' AFTER amount, ADD COLUMN exchange_rate DECIMAL(18,6) NOT NULL DEFAULT 1 AFTER currency_code, ADD COLUMN base_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER exchange_rate, ADD CONSTRAINT fk_expenses_category FOREIGN KEY(category_id) REFERENCES expense_categories(id) ON DELETE SET NULL, ADD CONSTRAINT fk_expenses_currency FOREIGN KEY(currency_code) REFERENCES currencies(code);
UPDATE expenses e JOIN expense_categories c ON c.name=e.category SET e.category_id=c.id;
UPDATE expenses SET base_amount=amount WHERE base_amount=0;

CREATE TABLE account_field_definitions (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(100) NOT NULL, field_key VARCHAR(100) NOT NULL UNIQUE, field_type ENUM('text','textarea','number','date') NOT NULL DEFAULT 'text', sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB;
CREATE TABLE account_field_values (account_id INT NOT NULL, field_definition_id INT NOT NULL, field_value TEXT NULL, PRIMARY KEY(account_id,field_definition_id), FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE, FOREIGN KEY(field_definition_id) REFERENCES account_field_definitions(id) ON DELETE CASCADE) ENGINE=InnoDB;
INSERT INTO account_field_definitions(label,field_key,field_type,sort_order) VALUES ('Account number','account_number','text',10),('Branch','branch','text',20),('SWIFT / routing code','routing_code','text',30);
INSERT INTO account_field_values(account_id,field_definition_id,field_value) SELECT a.id,f.id,a.account_number FROM accounts a JOIN account_field_definitions f ON f.field_key='account_number' WHERE a.account_number IS NOT NULL;
