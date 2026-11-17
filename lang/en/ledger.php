<?php

declare(strict_types=1);

return [
    'unbalanced' => 'Unbalanced journal: total debit :debit does not equal total credit :credit.',
    'immutable' => 'Posted :model records cannot be modified or deleted. Use a reversal entry.',
    'cannot_reverse_reversal' => 'A reversal entry cannot itself be reversed.',
    'already_reversed' => 'This journal entry has already been reversed.',
    'min_lines' => 'A journal entry needs at least two lines.',
    'max_lines' => 'A journal entry may have at most :max lines.',
    'invalid_line' => 'Invalid journal line.',
    'currency_mismatch' => 'All journal lines must use the same currency.',
    'non_positive_line' => 'Every journal line amount must be greater than zero.',
    'account_not_usable' => 'Ledger account not found or not usable.',
    'category_not_found' => 'Category not found or inactive.',
    'category_wrong_kind' => 'Category must be of kind :kind.',
    'idempotency_conflict' => 'Idempotency-Key was already used for a different request.',
    'idempotency_in_progress' => 'A request with this Idempotency-Key is still being processed.',
    'transfer_same_account' => 'Source and destination accounts must differ.',
    'amount_positive' => 'Amount must be greater than zero.',
    'transaction_not_found' => 'Transaction not found.',
    'not_reversible' => 'A transaction with status :status cannot be reversed.',
    'account_not_found' => 'Account not found or inactive.',
    'currency_mismatch_account' => 'Currency :currency does not match account :account.',
    'insufficient_funds' => 'Insufficient balance in :account.',
    'future_date' => 'Transaction date cannot be in the future.',
    'account_type_invalid' => 'Invalid account type :type.',
    'institution_invalid' => 'Unknown financial institution :code.',
    'opening_balance_negative' => 'Opening balance cannot be negative.',
];
