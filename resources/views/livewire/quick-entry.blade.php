<section class="rounded-lg border border-slate-200 bg-white p-5">
    <h2 class="mb-1 font-semibold">Catat transaksi</h2>
    <p class="mb-3 text-sm text-slate-500">Tulis seperti chat, misalnya <em>"gaji November 10jt"</em>, <em>"pinjam dari Pak Budi 2jt"</em>, <em>"hasil jualan kue 500rb"</em>, atau <em>"bayar hutang ke Budi 1jt"</em>.</p>

    @if ($flash)
        <div class="mb-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $flash }}</div>
    @endif

    <form wire:submit="interpret" class="flex gap-2">
        <input wire:model="text" type="text" maxlength="500" placeholder="Apa yang terjadi dengan uangmu?" aria-label="Deskripsi transaksi"
               class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 focus:border-slate-900 focus:outline-none">
        <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50">Pahami</button>
    </form>
    @error('text') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

    <button type="button" wire:click="$set('intent', 'EXPENSE')" class="mt-2 text-xs text-slate-500 underline" @if ($intent !== '') hidden @endif>atau isi manual</button>

    @if ($intent !== '' || $confidence !== null)
        <form wire:submit="save" class="mt-4 space-y-4 rounded-md border border-slate-200 bg-slate-50 p-4">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-slate-500">Dipahami sebagai</span>
                @if ($intentEnum)
                    <span class="rounded bg-slate-900 px-2 py-0.5 font-medium text-white">{{ $intentEnum->label() }}</span>
                @else
                    <span class="rounded bg-yellow-100 px-2 py-0.5 text-yellow-800">belum jelas — pilih jenisnya</span>
                @endif
                @if ($confidence)
                    <span class="text-xs text-slate-400">keyakinan: {{ $confidence }}</span>
                @endif
            </div>

            @foreach ($warnings as $warning)
                <p class="rounded-md bg-yellow-50 px-3 py-2 text-sm text-yellow-800">⚠ {{ match ($warning) {
                    'LENDING_OUT_NOT_SUPPORTED' => 'Sepertinya Anda meminjamkan uang ke orang lain (piutang). Ini belum didukung, jadi catat sebagai pengeluaran atau transfer dulu.',
                    'RECEIVABLE_COLLECTION_NOT_SUPPORTED' => 'Sepertinya ini pengembalian piutang. Ini belum didukung, jadi catat sebagai pemasukan lainnya dulu.',
                    'NO_OPEN_DEBT_FOR_COUNTERPARTY' => 'Tidak ada hutang aktif ke pihak ini. Periksa nama pihak atau pilih akun hutang.',
                    'CHOOSE_DEBT_ACCOUNT' => 'Pihak ini punya lebih dari satu hutang, jadi pilih hutang yang dibayar.',
                    'INTENT_UNKNOWN' => 'Teks belum dikenali. Pilih jenis transaksi secara manual.',
                    default => $warning,
                } }}</p>
            @endforeach

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="block text-sm">
                    <span class="mb-1 block font-medium">Jenis</span>
                    <select wire:model.live="intent" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                        <option value="">— pilih —</option>
                        @foreach ($this->selectableIntents() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                    @error('intent') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium">Nominal (Rp)</span>
                    <input wire:model="amount" type="text" inputmode="decimal" placeholder="mis. 2.000.000 atau 2jt" class="w-full rounded-md border border-slate-300 px-3 py-2 tabular-nums">
                    @error('amount') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium">Tanggal</span>
                    <input wire:model="transactionDate" type="date" max="{{ now(config('finance.display_timezone'))->toDateString() }}" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    @error('transactionDate') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                @if (in_array($intent, ['EXPENSE', 'DEBT_REPAYMENT', 'INTERNAL_TRANSFER'], true))
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">{{ $intent === 'INTERNAL_TRANSFER' ? 'Dari akun' : 'Dibayar dari' }}</span>
                        <select wire:model="sourceAccountId" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                            <option value="">— pilih akun —</option>
                            @foreach ($this->assetAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }} ({{ $account->balance()->format() }})</option>
                            @endforeach
                            @if ($intent === 'EXPENSE')
                                @foreach ($this->debtAccounts->where('account_type_code', 'CREDIT_CARD') as $card)
                                    <option value="{{ $card->id }}">{{ $card->name }} (kartu kredit)</option>
                                @endforeach
                            @endif
                        </select>
                        @error('sourceAccountId') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endif

                @if ($intentEnum?->isIncome() || in_array($intent, ['LOAN_RECEIVED', 'INTERNAL_TRANSFER'], true))
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Masuk ke akun</span>
                        <select wire:model="destinationAccountId" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                            <option value="">— pilih akun —</option>
                            @foreach ($this->assetAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                        @error('destinationAccountId') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endif

                @if ($intentEnum?->isIncome() || $intent === 'EXPENSE')
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Kategori</span>
                        <select wire:model="categoryId" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                            <option value="">— pilih kategori —</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('categoryId') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endif

                @if ($intent !== 'INTERNAL_TRANSFER' && $intent !== '')
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">
                            {{ match ($intent) { 'LOAN_RECEIVED' => 'Dipinjam dari', 'DEBT_REPAYMENT' => 'Dibayar ke', 'SALARY_INCOME' => 'Pemberi kerja', 'EXPENSE' => 'Dibayar ke (opsional)', default => 'Dari pihak (opsional)' } }}
                        </span>
                        <input wire:model="counterparty" type="text" maxlength="128" placeholder="mis. Pak Budi, PT Maju Jaya" class="w-full rounded-md border border-slate-300 px-3 py-2">
                        @error('counterparty') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endif

                @if (in_array($intent, ['LOAN_RECEIVED', 'DEBT_REPAYMENT'], true))
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Hutang</span>
                        <select wire:model="debtAccountId" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2">
                            <option value="">{{ $intent === 'LOAN_RECEIVED' ? 'Otomatis (buat/ pakai hutang ke pihak ini)' : 'Otomatis (hutang ke pihak di atas)' }}</option>
                            @foreach ($this->debtAccounts as $debt)
                                <option value="{{ $debt->id }}">{{ $debt->name }}{{ $debt->counterparty ? ' — '.$debt->counterparty->name : '' }} (sisa {{ $debt->balance()->format() }})</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if ($intent === 'ACTIVITY_INCOME')
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Aktivitas</span>
                        <input wire:model="activity" type="text" maxlength="64" placeholder="mis. jualan kue, proyek website" class="w-full rounded-md border border-slate-300 px-3 py-2">
                    </label>
                @endif

                <label class="block text-sm sm:col-span-2 lg:col-span-3">
                    <span class="mb-1 block font-medium">Catatan</span>
                    <input wire:model="description" type="text" maxlength="255" class="w-full rounded-md border border-slate-300 px-3 py-2">
                </label>
            </div>

            @error('save') <p class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror

            <div class="flex gap-2">
                <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-600 disabled:opacity-50">Simpan</button>
                <button type="button" wire:click="cancel" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-white">Batal</button>
            </div>
        </form>
    @endif
</section>
