<div class="space-y-6">
    <header class="rounded-lg border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Panduan setup bot Telegram</h1>
                <p class="mt-1 text-sm text-slate-600">Catat transaksi cukup dengan chat, misalnya <code class="rounded bg-slate-100 px-1">makan siang 45rb</code>. Ikuti langkah 1–8 sekali saja. Status di bawah diperbarui otomatis.</p>
            </div>
            @if ($botEnabled)
                <span class="rounded bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-800">✓ Bot aktif</span>
            @else
                <span class="rounded bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800">Bot belum aktif</span>
            @endif
        </div>
        <nav class="mt-4 flex flex-wrap gap-2 text-xs" aria-label="Isi panduan">
            @foreach ([['status', 'Status'], ['cara-kerja', 'Cara kerja'], ['langkah-1', '1. BotFather'], ['langkah-2', '2. ID Telegram'], ['langkah-3', '3. Secret'], ['langkah-4', '4. Environment'], ['langkah-5', '5. Redeploy'], ['langkah-6', '6. Webhook'], ['langkah-7', '7. Hubungkan akun'], ['langkah-8', '8. Uji coba'], ['perintah', 'Perintah bot'], ['pengguna', 'Kelola pengguna'], ['masalah', 'Mengatasi masalah'], ['keamanan', 'Keamanan & privasi']] as [$id, $label])
                <a href="#{{ $id }}" class="rounded-md border border-slate-200 px-2 py-1 text-slate-600 hover:bg-slate-100">{{ $label }}</a>
            @endforeach
        </nav>
    </header>

    {{-- STATUS --}}
    <section id="status" class="rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="mb-1 text-lg font-semibold">Status konfigurasi</h2>
        <p class="mb-3 text-sm text-slate-500">Dibaca dari environment server. Token dan secret tidak pernah ditampilkan, hanya apakah sudah benar.</p>

        <ul class="divide-y divide-slate-100 text-sm">
            @foreach ($checks as $key => $check)
                <li class="flex items-start gap-3 py-2" wire:key="check-{{ $key }}">
                    <span @class(['mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold', 'bg-emerald-100 text-emerald-700' => $check['ok'], 'bg-red-100 text-red-700' => ! $check['ok']]) aria-hidden="true">{{ $check['ok'] ? '✓' : '✗' }}</span>
                    <div>
                        <p class="font-medium">{{ $check['label'] }} <span class="sr-only">{{ $check['ok'] ? '(sudah)' : '(belum)' }}</span></p>
                        @unless ($check['ok'])<p class="text-xs text-slate-500">{{ $check['hint'] }}</p>@endunless
                    </div>
                </li>
            @endforeach
            <li class="flex items-start gap-3 py-2">
                <span @class(['mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold', 'bg-emerald-100 text-emerald-700' => $linked, 'bg-slate-100 text-slate-500' => ! $linked]) aria-hidden="true">{{ $linked ? '✓' : '–' }}</span>
                <div>
                    <p class="font-medium">Akun Anda terhubung ke Telegram <span class="sr-only">{{ $linked ? '(sudah)' : '(belum)' }}</span></p>
                    @unless ($linked)<p class="text-xs text-slate-500">Lakukan di langkah 7 setelah bot aktif.</p>@endunless
                </div>
            </li>
        </ul>

        <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-4 text-sm">
            <p class="font-medium">Alamat webhook aplikasi ini</p>
            <p class="mt-1 break-all font-mono text-xs">{{ $webhookUrl }}</p>
            <p class="mt-1 text-xs text-slate-500">Diambil dari APP_URL. Alamat ini yang didaftarkan ke Telegram pada langkah 6.</p>
        </div>

        @if ($isAdmin)
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" wire:click="checkTelegram" wire:loading.attr="disabled" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100 disabled:opacity-50">Cek koneksi Telegram</button>
                <button type="button" wire:click="registerWebhook" wire:loading.attr="disabled" wire:confirm="Daftarkan webhook ke {{ $webhookUrl }}? Update lama yang tertunda akan dibuang." @disabled(! ($checks['token']['ok'] && $checks['secret']['ok'] && $checks['https']['ok'])) class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50">Daftarkan webhook sekarang</button>
            </div>
        @else
            <p class="mt-4 text-xs text-slate-500">Tombol cek koneksi dan daftar webhook hanya tersedia untuk admin dashboard (akun DASHBOARD_ADMIN_EMAIL).</p>
        @endif

        @if ($flash)
            <div class="mt-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ $flash }}</div>
        @endif
        @if ($warning)
            <div class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800" role="status">{{ $warning }}</div>
        @endif
        @error('telegram') <p class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror

        @if ($remote)
            <dl class="mt-4 grid gap-x-6 gap-y-2 rounded-md border border-slate-200 p-4 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-slate-500">Token</dt><dd>{{ $remote['bot'] ? '✓ Valid, bot @'.$remote['bot'] : '✗ Ditolak Telegram / tidak terhubung' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Webhook terdaftar</dt><dd class="break-all">{{ $remote['url'] !== '' ? $remote['url'] : '✗ Belum ada' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Cocok dengan APP_URL</dt><dd>{{ $remote['matches'] ? '✓ Ya' : '✗ Tidak, jalankan langkah 6' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Pesan tertunda</dt><dd>{{ $remote['pending'] }}</dd></div>
                @if ($remote['last_error'])
                    <div class="sm:col-span-2"><dt class="text-xs text-slate-500">Error terakhir dari Telegram</dt><dd class="text-red-700">{{ $remote['last_error'] }} @if ($remote['last_error_at'])<span class="text-xs text-slate-500">({{ $remote['last_error_at'] }})</span>@endif</dd></div>
                @endif
            </dl>
            @if ($remote['bot'] && $botUsername !== '' && strcasecmp($remote['bot'], $botUsername) !== 0)
                <p class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">TELEGRAM_BOT_USERNAME ({{ $botUsername }}) berbeda dari username bot sebenarnya ({{ $remote['bot'] }}). Samakan agar tombol "Buka di Telegram" benar.</p>
            @endif
        @endif
    </section>

    {{-- CARA KERJA --}}
    <section id="cara-kerja" class="rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed">
        <h2 class="mb-2 text-lg font-semibold">Cara kerja</h2>
        <ol class="grid gap-3 sm:grid-cols-4">
            <li class="rounded-md bg-slate-50 p-3"><p class="font-medium">1. Anda chat</p><p class="text-slate-600">Kirim kalimat ke bot di aplikasi Telegram.</p></li>
            <li class="rounded-md bg-slate-50 p-3"><p class="font-medium">2. Telegram meneruskan</p><p class="text-slate-600">Server Telegram mengirim pesan ke alamat webhook (HTTPS) dengan header secret.</p></li>
            <li class="rounded-md bg-slate-50 p-3"><p class="font-medium">3. Aplikasi memeriksa</p><p class="text-slate-600">Secret harus cocok, ID Telegram harus diizinkan, dan chat harus terhubung ke akun.</p></li>
            <li class="rounded-md bg-slate-50 p-3"><p class="font-medium">4. Konfirmasi</p><p class="text-slate-600">Bot menampilkan ringkasan. Transaksi baru dicatat setelah Anda menekan ✅ Simpan.</p></li>
        </ol>
        <p class="mt-3 text-slate-600">Yang Anda butuhkan: akun Telegram, akses ke Dokploy (tab <em>Environment</em> dan tombol <em>Deploy</em>), dan domain HTTPS yang sudah mengarah ke aplikasi ini ({{ $appUrl }}).</p>
    </section>

    {{-- LANGKAH 1 --}}
    <section id="langkah-1" class="rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed">
        <h2 class="mb-2 text-lg font-semibold">Langkah 1 · Buat bot di BotFather</h2>
        <ol class="list-decimal space-y-2 pl-5">
            <li>Di Telegram, cari <strong>&#64;BotFather</strong>. Pastikan bertanda centang biru (akun resmi), lalu tekan <em>Start</em>.</li>
            <li>Kirim <code class="rounded bg-slate-100 px-1">/newbot</code>.</li>
            <li>Isi <strong>nama tampilan</strong> bot, misalnya <em>Catatan Keuangan Keluarga</em>.</li>
            <li>Isi <strong>username</strong> yang unik dan berakhiran <code class="rounded bg-slate-100 px-1">bot</code>, misalnya <em>catatuang_keluarga_bot</em>. Simpan username ini untuk <code class="rounded bg-slate-100 px-1">TELEGRAM_BOT_USERNAME</code>.</li>
            <li>BotFather membalas dengan <strong>token</strong> berbentuk <code class="rounded bg-slate-100 px-1">123456789:AAH…</code>. Ini untuk <code class="rounded bg-slate-100 px-1">TELEGRAM_BOT_TOKEN</code>.</li>
            <li>Disarankan: kirim <code class="rounded bg-slate-100 px-1">/setjoingroups</code> → pilih bot → <em>Disable</em>, supaya bot tidak bisa dimasukkan ke grup. Aplikasi memang hanya melayani chat pribadi.</li>
            <li>Opsional: <code class="rounded bg-slate-100 px-1">/setdescription</code> dan <code class="rounded bg-slate-100 px-1">/setuserpic</code> untuk deskripsi dan foto bot.</li>
        </ol>
        <div class="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-red-800">
            <p><strong>Token = kunci penuh bot.</strong> Perlakukan seperti kata sandi: jangan dikirim lewat chat, email, tiket, atau di-commit ke Git. Jika bocor, kirim <code>/revoke</code> ke &#64;BotFather, ganti nilainya di Dokploy, deploy ulang, lalu ulangi langkah 6.</p>
        </div>
    </section>

    {{-- LANGKAH 2 --}}
    <section id="langkah-2" class="rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed">
        <h2 class="mb-2 text-lg font-semibold">Langkah 2 · Cari ID Telegram setiap pengguna</h2>
        <p>Bot hanya melayani ID Telegram yang Anda izinkan. ID berupa <strong>angka</strong> (misalnya <code class="rounded bg-slate-100 px-1">123456789</code>), bukan username.</p>
        <ol class="mt-2 list-decimal space-y-2 pl-5">
            <li>Setiap pengguna membuka bot info ID, misalnya <strong>&#64;userinfobot</strong>, lalu menekan <em>Start</em>.</li>
            <li>Bot itu membalas dengan <em>Id: 123456789</em>. Catat angkanya.</li>
            <li>Kumpulkan semua ID yang boleh memakai bot, misalnya Anda dan pasangan, lalu pisahkan dengan koma: <code class="rounded bg-slate-100 px-1">123456789,987654321</code>.</li>
        </ol>
        <p class="mt-2 text-slate-600">ID Telegram termasuk data pribadi. Simpan hanya di environment server, jangan di dokumen bersama.</p>
    </section>

    {{-- LANGKAH 3 --}}
    <section id="langkah-3" class="rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed">
        <h2 class="mb-2 text-lg font-semibold">Langkah 3 · Buat secret webhook</h2>
        <p>Secret membuktikan bahwa pesan yang masuk benar-benar dari Telegram. Tanpa secret yang cocok, aplikasi menolak (403). Syarat: minimal 32 karakter, hanya huruf, angka, <code>_</code> atau <code>-</code>.</p>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <button type="button" wire:click="generateSecret" class="rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100">Buat secret acak</button>
            <span class="text-xs text-slate-500">Dibuat di server, hanya ditampilkan di layar Anda, tidak disimpan.</span>
        </div>
        @if ($generatedSecret)
            <div class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3" x-data="{ copied: false }">
                <p class="text-xs text-slate-500">Salin ke <code>TELEGRAM_WEBHOOK_SECRET</code>:</p>
                <p class="my-1 break-all font-mono text-sm" x-ref="secret" data-testid="generated-secret">{{ $generatedSecret }}</p>
                <button type="button" class="text-xs underline" x-on:click="navigator.clipboard?.writeText($refs.secret.textContent.trim()); copied = true; setTimeout(() => copied = false, 2000)">
                    <span x-show="!copied">Salin</span><span x-show="copied" x-cloak>Tersalin ✓</span>
                </button>
            </div>
        @endif
        <p class="mt-3 text-slate-600">Alternatif lewat terminal: <code class="rounded bg-slate-100 px-1">php -r "echo bin2hex(random_bytes(32));"</code></p>
    </section>

    {{-- LANGKAH 4 --}}
    <section id="langkah-4" class="rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed">
        <h2 class="mb-2 text-lg font-semibold">Langkah 4 · Isi environment di Dokploy</h2>
        <ol class="list-decimal space-y-2 pl-5">
            <li>Buka Dokploy → project aplikasi ini → service <strong>Compose</strong> → tab <strong>Environment</strong>.</li>
            <li>Tambahkan atau ubah baris berikut (ganti contoh dengan nilai Anda):</li>
        </ol>
        <pre class="mt-2 overflow-x-auto rounded-md bg-slate-900 p-4 text-xs leading-relaxed text-slate-100"><code>APP_URL={{ $appUrl }}
TELEGRAM_BOT_TOKEN=123456789:AAH...           # dari BotFather (langkah 1)
TELEGRAM_BOT_USERNAME={{ $botUsername !== '' ? $botUsername : 'catatuang_keluarga_bot' }}   # tanpa @
TELEGRAM_WEBHOOK_SECRET=hasil-langkah-3       # min. 32 karakter
TELEGRAM_ALLOWED_USER_IDS=123456789,987654321 # dari langkah 2
TELEGRAM_LINK_TTL_MINUTES={{ $linkTtl }}                   # masa berlaku kode tautan (menit)</code></pre>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500"><tr><th class="py-2 pr-4">Variabel</th><th class="py-2 pr-4">Wajib</th><th class="py-2">Keterangan</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr><td class="py-2 pr-4 font-mono text-xs">APP_URL</td><td class="pr-4">Ya</td><td>Alamat publik HTTPS aplikasi, tanpa garis miring di akhir. Dipakai untuk alamat webhook.</td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-xs">TELEGRAM_BOT_TOKEN</td><td class="pr-4">Ya</td><td>Token dari BotFather. Rahasia.</td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-xs">TELEGRAM_BOT_USERNAME</td><td class="pr-4">Disarankan</td><td>Untuk tombol "Buka di Telegram" di Profil.</td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-xs">TELEGRAM_WEBHOOK_SECRET</td><td class="pr-4">Ya</td><td>Secret dari langkah 3. Rahasia.</td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-xs">TELEGRAM_ALLOWED_USER_IDS</td><td class="pr-4">Ya</td><td>Daftar ID yang diizinkan. Kosong = bot menolak semua orang.</td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-xs">TELEGRAM_LINK_TTL_MINUTES</td><td class="pr-4">Tidak</td><td>Default 10 menit.</td></tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-slate-600">Jangan menulis nilai-nilai ini di <code>.env.example</code>, README, atau repository Git.</p>
    </section>

    {{-- LANGKAH 5 --}}
    <section id="langkah-5" class="rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed">
        <h2 class="mb-2 text-lg font-semibold">Langkah 5 · Deploy ulang</h2>
        <ol class="list-decimal space-y-2 pl-5">
            <li>Klik <strong>Save</strong> di tab Environment, lalu klik <strong>Deploy</strong>.</li>
            <li>Saat start, container web otomatis menjalankan migrasi (termasuk tabel <code>telegram_links</code>) dan menyimpan ulang cache konfigurasi. Tidak perlu perintah manual.</li>
            <li>Muat ulang halaman ini. Semua baris di <a href="#status" class="underline">Status konfigurasi</a> harus bertanda ✓ (kecuali "Akun Anda terhubung", yang dilakukan di langkah 7).</li>
        </ol>
        <p class="mt-2 text-slate-600">Perubahan environment <strong>tidak</strong> berlaku sebelum deploy ulang, karena konfigurasi di-cache saat container start.</p>
    </section>

    {{-- LANGKAH 6 --}}
    <section id="langkah-6" class="rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed">
        <h2 class="mb-2 text-lg font-semibold">Langkah 6 · Daftarkan webhook</h2>
        <p>Langkah ini memberi tahu Telegram ke mana pesan harus dikirim: <span class="break-all font-mono text-xs">{{ $webhookUrl }}</span>. Cukup sekali, dan diulang hanya jika domain, token, atau secret berubah.</p>
        <h3 class="mt-3 font-medium">Cara A · Dari halaman ini (admin)</h3>
        <p>Di bagian <a href="#status" class="underline">Status</a>, klik <strong>Daftarkan webhook sekarang</strong>, lalu <strong>Cek koneksi Telegram</strong>. Hasilnya harus: token ✓ valid, webhook cocok dengan APP_URL ✓, tanpa error terakhir.</p>
        <h3 class="mt-3 font-medium">Cara B · Lewat terminal Dokploy</h3>
        <p>Dokploy → service Compose → tab <strong>Terminal</strong> (atau <em>Docker → container web → Terminal</em>), lalu jalankan:</p>
        <pre class="mt-2 overflow-x-auto rounded-md bg-slate-900 p-4 text-xs leading-relaxed text-slate-100"><code>php artisan telegram:webhook set      # daftarkan (memakai APP_URL)
php artisan telegram:webhook info     # cek status &amp; error terakhir
php artisan telegram:webhook delete   # matikan webhook</code></pre>
        <p class="mt-2 text-slate-600">Pendaftaran juga mengatur menu perintah bot (/saldo, /ringkasan, dan seterusnya), hanya menerima pesan dan tombol, serta membuang pesan lama yang tertunda.</p>
    </section>

    {{-- LANGKAH 7 --}}
    <section id="langkah-7" class="rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed">
        <h2 class="mb-2 text-lg font-semibold">Langkah 7 · Hubungkan akun Anda</h2>
        <p>Dilakukan oleh <strong>setiap pengguna</strong> dari akunnya sendiri.</p>
        <ol class="mt-2 list-decimal space-y-2 pl-5">
            <li>Buka <a href="{{ route('profile') }}" wire:navigate class="underline">Profil</a> (klik nama Anda di kanan atas) → bagian <strong>Telegram</strong> → klik <strong>Buat kode</strong>.</li>
            <li>Muncul kode 8 karakter, berlaku {{ $linkTtl }} menit dan sekali pakai.</li>
            <li>Klik <strong>Buka di Telegram</strong>, atau kirim <code class="rounded bg-slate-100 px-1">/start KODE</code> ke bot{{ $botUsername !== '' ? ' @'.$botUsername : '' }}.</li>
            <li>Bot membalas <em>✅ Terhubung dengan akun …</em>, dan Profil berubah menjadi <em>✓ Terhubung</em>.</li>
        </ol>
        <p class="mt-2 text-slate-600">Jangan bagikan atau screenshot kode. Siapa pun yang memakainya lebih dulu akan terhubung ke akun Anda. Jika bocor, klik <em>Buat kode baru</em>: kode lama langsung tidak berlaku.</p>
    </section>

    {{-- LANGKAH 8 --}}
    <section id="langkah-8" class="rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed">
        <h2 class="mb-2 text-lg font-semibold">Langkah 8 · Uji coba</h2>
        <p>Pastikan sudah ada minimal satu akun di menu <a href="{{ route('accounts') }}" wire:navigate class="underline">Akun</a>. Lalu kirim ke bot:</p>
        <div class="mt-2 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500"><tr><th class="py-2 pr-4">Kirim</th><th class="py-2">Dicatat sebagai</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr><td class="py-2 pr-4 font-mono text-xs">makan siang 45rb</td><td>Pengeluaran · Makanan &amp; Minuman</td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-xs">gaji November 10jt</td><td>Pemasukan gaji</td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-xs">beli token listrik 200rb</td><td>Pengeluaran · Listrik</td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-xs">transfer BCA ke BNI 2jt</td><td>Transfer antar akun sendiri</td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-xs">pinjam dari Pak Budi 1jt</td><td>Pinjaman diterima (hutang, bukan pendapatan)</td></tr>
                    <tr><td class="py-2 pr-4 font-mono text-xs">bayar hutang ke Budi 500rb</td><td>Pembayaran hutang</td></tr>
                </tbody>
            </table>
        </div>
        <p class="mt-2">Bot menanyakan akun jika perlu, menampilkan ringkasan, lalu mencatat setelah <strong>✅ Simpan</strong>. Hasilnya langsung muncul di <a href="{{ route('dashboard') }}" wire:navigate class="underline">Dashboard</a> dan <a href="{{ route('transactions') }}" wire:navigate class="underline">Transaksi</a>. Tanggal transaksi dari bot selalu hari ini. Untuk tanggal lain, catat lewat web.</p>
    </section>

    {{-- PERINTAH --}}
    <section id="perintah" class="rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed">
        <h2 class="mb-2 text-lg font-semibold">Perintah bot</h2>
        <ul class="grid gap-2 sm:grid-cols-2">
            @foreach ($commands as $command)
                <li class="rounded-md bg-slate-50 px-3 py-2"><code class="font-medium">/{{ $command['command'] }}</code> <span class="text-slate-600">— {{ $command['description'] }}</span></li>
            @endforeach
        </ul>
    </section>

    {{-- PENGGUNA --}}
    <section id="pengguna" class="rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed">
        <h2 class="mb-2 text-lg font-semibold">Menambah atau mencabut pengguna</h2>
        <ul class="list-disc space-y-2 pl-5">
            <li><strong>Menambah:</strong> tambahkan ID Telegram-nya ke <code>TELEGRAM_ALLOWED_USER_IDS</code> di Dokploy → Deploy. Lalu orang itu melakukan langkah 7 dari akun dashboard-nya sendiri.</li>
            <li><strong>Mencabut:</strong> hapus ID-nya dari daftar → Deploy. Bot langsung mengabaikan pesannya walaupun chat sudah terhubung.</li>
            <li><strong>Memutus dari sisi pengguna:</strong> kirim <code>/putuskan</code> ke bot, atau Profil → Telegram → <em>Putuskan Telegram</em>. Mengganti kata sandi juga otomatis memutus Telegram.</li>
            <li><strong>Ponsel hilang:</strong> segera ganti kata sandi dari web. Telegram, semua sesi lain, dan token API langsung dicabut.</li>
            <li>Satu akun dashboard hanya terhubung ke satu chat Telegram. Menautkan chat baru memindahkan tautannya.</li>
        </ul>
    </section>

    {{-- MASALAH --}}
    <section id="masalah" class="rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed">
        <h2 class="mb-2 text-lg font-semibold">Mengatasi masalah</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500"><tr><th class="py-2 pr-4">Gejala</th><th class="py-2">Penyebab &amp; solusi</th></tr></thead>
                <tbody class="divide-y divide-slate-100 align-top">
                    <tr><td class="py-2 pr-4 font-medium">Profil menampilkan "Bot Telegram belum diaktifkan"</td><td>Token, secret, atau daftar ID masih kosong. Lihat <a href="#status" class="underline">Status</a>, lengkapi environment, lalu deploy ulang.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Bot sama sekali tidak membalas</td><td>(1) ID Telegram Anda belum ada di <code>TELEGRAM_ALLOWED_USER_IDS</code>: pesan dari ID lain diabaikan tanpa balasan. (2) Webhook belum didaftarkan atau tidak cocok dengan APP_URL: jalankan langkah 6. (3) Lihat "Error terakhir" setelah <em>Cek koneksi Telegram</em>.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Error "Wrong response from the webhook: 403"</td><td>Secret di Telegram berbeda dengan <code>TELEGRAM_WEBHOOK_SECRET</code>, biasanya karena secret diganti tanpa mendaftar ulang. Ulangi langkah 6.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Error "404 Not Found"</td><td>Bot nonaktif di server (konfigurasi belum lengkap) atau APP_URL salah. Periksa Status, deploy ulang, lalu langkah 6.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Error SSL / "certificate verify failed"</td><td>Domain belum punya sertifikat HTTPS valid. Aktifkan HTTPS (Let's Encrypt) di pengaturan domain Dokploy.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Error "Connection timed out" / 502</td><td>Container web belum sehat atau domain belum mengarah ke server. Cek log deploy dan pengaturan domain (port 8080).</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Token ditolak (✗ di Cek koneksi)</td><td>Token salah ketik atau sudah di-revoke. Ambil token terbaru dari &#64;BotFather, perbarui environment, deploy ulang, lalu langkah 6.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Bot bilang "Chat ini belum terhubung"</td><td>Lakukan langkah 7. Kode berlaku {{ $linkTtl }} menit dan hanya bisa dipakai sekali.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">"Draf ini sudah kedaluwarsa"</td><td>Draf berlaku 15 menit. Kirim ulang kalimatnya.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Bot menolak di grup</td><td>Disengaja demi privasi. Gunakan chat pribadi dengan bot.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Kategori atau jenis salah</td><td>Tekan 🔁 Ganti jenis atau ❌ Batal. Jika sudah tersimpan, batalkan di menu Transaksi lalu catat ulang.</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    {{-- KEAMANAN --}}
    <section id="keamanan" class="rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed">
        <h2 class="mb-2 text-lg font-semibold">Keamanan &amp; privasi (ISO/IEC 27001:2022, UU PDP)</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500"><tr><th class="py-2 pr-4">Kontrol</th><th class="py-2">Yang diterapkan aplikasi</th></tr></thead>
                <tbody class="divide-y divide-slate-100 align-top">
                    <tr><td class="py-2 pr-4 font-medium">Daftar izin (A.5.15)</td><td>Hanya ID di <code>TELEGRAM_ALLOWED_USER_IDS</code>. Daftar kosong = tidak ada yang dilayani (fail-closed).</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Keaslian webhook (A.8.5)</td><td>Header secret wajib cocok (dibandingkan constant-time). Tanpa itu: 403.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Penautan akun</td><td>Kode sekali pakai, berlaku {{ $linkTtl }} menit, hanya hash yang disimpan, dibatasi jumlah percobaan.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Minimisasi data</td><td>Hanya <code>chat_id</code> yang disimpan. Teks pesan tidak dicatat di log. Draf di cache 15 menit.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Konfirmasi</td><td>Tidak ada transaksi tanpa ✅ Simpan. Kunci idempotensi mencegah pencatatan ganda.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Rahasia (A.5.17, A.8.24)</td><td>Token dan secret tidak pernah ditampilkan di halaman ini atau dicatat di log.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Audit (A.8.15)</td><td><code>telegram.linked</code>, <code>telegram.unlinked</code>, <code>telegram.webhook_registered</code>, dan setiap transaksi dengan channel TELEGRAM.</td></tr>
                    <tr><td class="py-2 pr-4 font-medium">Hak akses admin</td><td>Cek koneksi dan daftar webhook dari web hanya untuk akun <code>DASHBOARD_ADMIN_EMAIL</code>, dibatasi jumlah percobaan.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-900">
            <p class="font-medium">Perlu tindakan pengelola (belum sesuai bila diabaikan):</p>
            <ol class="mt-1 list-decimal space-y-1 pl-5">
                <li>Telegram adalah pihak ketiga dengan server di luar Indonesia. Isi pesan (nominal, nama pihak, saldo) melewati server Telegram. Cantumkan di kebijakan privasi, minta persetujuan pengguna, dan catat sebagai transfer data pribadi ke luar negeri (UU PDP).</li>
                <li>Simpan token dan secret hanya di environment Dokploy atau secret manager.</li>
                <li>Produksi wajib HTTPS dan <code>APP_DEBUG=false</code>.</li>
                <li>Pengguna: jangan kirim PIN, OTP, kata sandi, atau nomor kartu lengkap ke bot. Aktifkan Passcode Lock di Telegram karena balasan /saldo tersimpan di riwayat chat.</li>
            </ol>
        </div>
    </section>
</div>
