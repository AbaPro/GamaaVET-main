-- Manual customer-wallet deposits recorded a destination safe/bank but never
-- credited it. Post each one exactly once, logged in the table below.
--
-- When the account had a balance adjustment after the deposit, that adjustment
-- already set the real absolute balance, so the deposit is folded into the
-- adjustment's previous_balance instead of the current balance. Otherwise the
-- account balance is credited directly.
--
-- Runs once: from this release the wallet page credits the safe/bank itself, so
-- a rerun must not pick up those newer deposits. Deploy together with the code.

CREATE TABLE IF NOT EXISTS `customer_wallet_deposit_postings` (
  `wallet_transaction_id` int(11) NOT NULL,
  `account_type` enum('safe','bank') NOT NULL,
  `account_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `adjustment_id` int(11) DEFAULT NULL,
  `applied` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`wallet_transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `customer_wallet_deposit_backfill_runs` (
  `id` tinyint(1) NOT NULL,
  `ran_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

START TRANSACTION;

SET @first_run = (SELECT COUNT(*) = 0 FROM customer_wallet_deposit_backfill_runs);

INSERT IGNORE INTO `customer_wallet_deposit_postings`
  (`wallet_transaction_id`, `account_type`, `account_id`, `amount`, `adjustment_id`)
SELECT wt.id, 'safe', wt.safe_id, wt.amount,
       (SELECT a.id FROM finance_account_balance_adjustments a
        WHERE a.account_type = 'safe' AND a.account_id = wt.safe_id AND a.created_at > wt.created_at
        ORDER BY a.created_at, a.id LIMIT 1)
FROM customer_wallet_transactions wt
WHERE wt.type = 'deposit'
  AND COALESCE(wt.reference_type, 'manual') = 'manual'
  AND wt.payment_method = 'cash'
  AND wt.safe_id IS NOT NULL
  AND @first_run = 1;

INSERT IGNORE INTO `customer_wallet_deposit_postings`
  (`wallet_transaction_id`, `account_type`, `account_id`, `amount`, `adjustment_id`)
SELECT wt.id, 'bank', wt.bank_account_id, wt.amount,
       (SELECT a.id FROM finance_account_balance_adjustments a
        WHERE a.account_type = 'bank' AND a.account_id = wt.bank_account_id AND a.created_at > wt.created_at
        ORDER BY a.created_at, a.id LIMIT 1)
FROM customer_wallet_transactions wt
WHERE wt.type = 'deposit'
  AND COALESCE(wt.reference_type, 'manual') = 'manual'
  AND wt.payment_method = 'transfer'
  AND wt.bank_account_id IS NOT NULL
  AND @first_run = 1;

UPDATE finance_account_balance_adjustments a
JOIN (SELECT adjustment_id, SUM(amount) AS total
      FROM customer_wallet_deposit_postings
      WHERE applied = 0 AND adjustment_id IS NOT NULL
      GROUP BY adjustment_id) p ON p.adjustment_id = a.id
SET a.previous_balance = a.previous_balance + p.total;

UPDATE safes s
JOIN (SELECT account_id, SUM(amount) AS total
      FROM customer_wallet_deposit_postings
      WHERE applied = 0 AND adjustment_id IS NULL AND account_type = 'safe'
      GROUP BY account_id) p ON p.account_id = s.id
SET s.balance = s.balance + p.total;

UPDATE bank_accounts b
JOIN (SELECT account_id, SUM(amount) AS total
      FROM customer_wallet_deposit_postings
      WHERE applied = 0 AND adjustment_id IS NULL AND account_type = 'bank'
      GROUP BY account_id) p ON p.account_id = b.id
SET b.balance = b.balance + p.total;

UPDATE customer_wallet_deposit_postings SET applied = 1 WHERE applied = 0;
INSERT IGNORE INTO customer_wallet_deposit_backfill_runs (id) VALUES (1);

COMMIT;
