ALTER TABLE customer_wallet_transactions
    ADD COLUMN payment_method VARCHAR(20) DEFAULT 'cash' AFTER notes,
    ADD COLUMN bank_account_id INT NULL DEFAULT NULL AFTER payment_method,
    ADD CONSTRAINT fk_wallet_bank_account FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL;
