<?php

declare(strict_types=1);

return [
    'unbalanced' => 'Jurnal tidak seimbang: total debit :debit tidak sama dengan total kredit :credit.',
    'immutable' => 'Data :model yang sudah diposting tidak dapat diubah atau dihapus. Gunakan jurnal pembalik.',
    'cannot_reverse_reversal' => 'Jurnal pembalik tidak dapat dibalik lagi.',
    'already_reversed' => 'Jurnal ini sudah pernah dibalik.',
    'min_lines' => 'Jurnal harus memiliki minimal dua baris.',
    'max_lines' => 'Jurnal maksimal memiliki :max baris.',
    'invalid_line' => 'Baris jurnal tidak valid.',
    'currency_mismatch' => 'Semua baris jurnal harus menggunakan mata uang yang sama.',
    'non_positive_line' => 'Nominal setiap baris jurnal harus lebih besar dari nol.',
    'account_not_usable' => 'Akun buku besar tidak ditemukan atau tidak dapat digunakan.',
    'category_not_found' => 'Kategori tidak ditemukan atau tidak aktif.',
    'category_wrong_kind' => 'Kategori harus berjenis :kind.',
    'idempotency_conflict' => 'Idempotency-Key sudah digunakan untuk permintaan yang berbeda.',
    'idempotency_in_progress' => 'Permintaan dengan Idempotency-Key ini sedang diproses.',
    'transfer_same_account' => 'Akun sumber dan tujuan transfer tidak boleh sama.',
    'amount_positive' => 'Nominal harus lebih besar dari nol.',
    'transaction_not_found' => 'Transaksi tidak ditemukan.',
    'not_reversible' => 'Transaksi berstatus :status tidak dapat dibatalkan.',
    'account_not_found' => 'Akun tidak ditemukan atau tidak aktif.',
    'currency_mismatch_account' => 'Mata uang :currency tidak sesuai dengan akun :account.',
    'insufficient_funds' => 'Saldo :account tidak mencukupi.',
    'future_date' => 'Tanggal transaksi tidak boleh di masa depan.',
    'account_type_invalid' => 'Jenis akun :type tidak valid.',
    'institution_invalid' => 'Institusi keuangan :code tidak dikenal.',
    'opening_balance_negative' => 'Saldo awal tidak boleh negatif.',
];
