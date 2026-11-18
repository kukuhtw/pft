<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} · Catatan keuangan pribadi yang tidak salah hitung</title>
    <meta name="description" content="Aplikasi keuangan pribadi dengan pembukuan berpasangan: transfer antar rekening sendiri bukan pengeluaran, pinjaman bukan pemasukan, uang yang dipinjamkan tetap milik Anda. Catat lewat web atau Telegram. Self-hosting mulai sekitar Rp30 ribu per bulan.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white text-slate-900 antialiased">
    @php
        $registrationOpen = \App\Modules\Identity\Services\AccessPolicy::registrationEnabled() && ! auth()->check();
        $primaryUrl = auth()->check() ? route('dashboard') : ($registrationOpen ? route('register') : route('login'));
        $primaryLabel = auth()->check() ? 'Buka dashboard' : ($registrationOpen ? 'Coba gratis sekarang' : 'Masuk');
        $contactName = (string) config('landing.contact.name');
        $contactWhatsapp = (string) config('landing.contact.whatsapp');
        $contactUrl = $contactWhatsapp !== ''
            ? 'https://wa.me/'.$contactWhatsapp.'?text='.rawurlencode((string) config('landing.contact.message'))
            : null;
    @endphp

    {{-- NAV --}}
    <header class="sticky top-0 z-10 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
            <a href="{{ route('home') }}" class="font-semibold tracking-tight">💰 {{ config('app.name') }}</a>
            <nav class="hidden items-center gap-5 text-sm text-slate-600 md:flex" aria-label="Navigasi">
                <a href="#aturan" class="hover:text-slate-900">Cara menghitung</a>
                <a href="#fitur" class="hover:text-slate-900">Fitur</a>
                <a href="#keamanan" class="hover:text-slate-900">Keamanan</a>
                <a href="#self-hosting" class="hover:text-slate-900">Self-hosting</a>
                <a href="#tanya-jawab" class="hover:text-slate-900">Tanya jawab</a>
                <a href="{{ route('docs') }}" class="hover:text-slate-900">Panduan</a>
                @if ($contactUrl)
                    <a href="#kontak" class="hover:text-slate-900">Kontak</a>
                @endif
            </nav>
            <div class="flex items-center gap-2 text-sm">
                @guest
                    <a href="{{ route('login') }}" class="rounded-md px-3 py-1.5 text-slate-700 hover:bg-slate-100">Masuk</a>
                @endguest
                <a href="{{ $primaryUrl }}" class="rounded-md bg-slate-900 px-3 py-1.5 font-medium text-white hover:bg-slate-700">{{ auth()->check() ? 'Dashboard' : ($registrationOpen ? 'Daftar' : 'Masuk') }}</a>
            </div>
        </div>
    </header>

    <main>
        {{-- HERO --}}
        <section class="border-b border-slate-100 bg-gradient-to-b from-slate-50 to-white">
            <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 md:grid-cols-2 md:py-24">
                <div>
                    <p class="mb-3 inline-block rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-800">Pembukuan pribadi · data di server Anda sendiri</p>
                    <h1 class="text-4xl font-semibold leading-tight tracking-tight md:text-5xl">Tahu persis ke mana uang Anda pergi. <span class="text-emerald-700">Tanpa salah hitung.</span></h1>
                    <p class="mt-5 text-lg leading-relaxed text-slate-600">Kebanyakan aplikasi pencatat menghitung top-up e-wallet sebagai pengeluaran dan pinjaman sebagai pemasukan. Hasilnya, laporan terlihat rapi tapi menyesatkan. {{ config('app.name') }} memakai prinsip pembukuan yang dipakai akuntan, dikemas sesederhana mengetik chat.</p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="{{ $primaryUrl }}" class="rounded-md bg-slate-900 px-5 py-3 text-sm font-medium text-white hover:bg-slate-700">{{ $primaryLabel }}</a>
                        <a href="#self-hosting" class="rounded-md border border-slate-300 px-5 py-3 text-sm font-medium hover:bg-slate-100">Self-hosting mulai ±Rp30 ribu/bulan</a>
                    </div>
                    <p class="mt-4 text-xs text-slate-500">Tanpa langganan aplikasi. Tanpa iklan. Data keuangan Anda tidak dijual ke siapa pun.</p>
                </div>

                {{-- Illustration: a chat entry turning into a correct record --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-label="Contoh pencatatan">
                    <div class="space-y-3 text-sm">
                        <div class="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-sm bg-sky-600 px-4 py-2 text-white">makan siang 45rb</div>
                        <div class="w-fit max-w-[90%] rounded-2xl rounded-bl-sm bg-slate-100 px-4 py-3">
                            <p class="font-medium">Periksa sebelum disimpan</p>
                            <dl class="mt-2 grid grid-cols-[auto,1fr] gap-x-3 gap-y-1 text-slate-700">
                                <dt class="text-slate-500">Jenis</dt><dd>Pengeluaran</dd>
                                <dt class="text-slate-500">Nominal</dt><dd class="tabular-nums">Rp45.000</dd>
                                <dt class="text-slate-500">Dari akun</dt><dd>GoPay</dd>
                                <dt class="text-slate-500">Kategori</dt><dd>Makanan &amp; Minuman</dd>
                            </dl>
                            <p class="mt-2 text-xs text-slate-500">✅ Simpan · 🔁 Ganti jenis · ❌ Batal</p>
                        </div>
                        <div class="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-sm bg-sky-600 px-4 py-2 text-white">top up GoPay dari BCA 200rb</div>
                        <div class="w-fit max-w-[90%] rounded-2xl rounded-bl-sm bg-slate-100 px-4 py-3">
                            <p><span class="font-medium">Transfer antar akun sendiri</span>: BCA → GoPay Rp200.000.</p>
                            <p class="mt-1 text-xs text-emerald-700">Bukan pengeluaran. Total uang Anda tetap.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- PROBLEM --}}
        <section class="mx-auto max-w-6xl px-4 py-16">
            <h2 class="text-2xl font-semibold tracking-tight md:text-3xl">Mengapa catatan keuangan sering tidak cocok dengan saldo?</h2>
            <p class="mt-3 max-w-3xl text-slate-600">Karena semua uang masuk dianggap "pemasukan" dan semua uang keluar dianggap "pengeluaran". Padahal tidak semua perpindahan uang mengubah kekayaan Anda.</p>
            <div class="mt-8 grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-red-100 bg-red-50/60 p-5">
                    <p class="text-sm font-medium text-red-800">Pencatat biasa</p>
                    <p class="mt-2 text-slate-700">Tarik tunai Rp1 juta tercatat sebagai <strong>pengeluaran Rp1 juta</strong>, padahal uangnya hanya pindah ke dompet.</p>
                </div>
                <div class="rounded-xl border border-red-100 bg-red-50/60 p-5">
                    <p class="text-sm font-medium text-red-800">Pencatat biasa</p>
                    <p class="mt-2 text-slate-700">Pinjaman Rp5 juta dari saudara tercatat sebagai <strong>pemasukan</strong>, sehingga bulan itu terlihat "untung besar".</p>
                </div>
                <div class="rounded-xl border border-red-100 bg-red-50/60 p-5">
                    <p class="text-sm font-medium text-red-800">Pencatat biasa</p>
                    <p class="mt-2 text-slate-700">Uang yang Anda pinjamkan ke teman tercatat <strong>hilang sebagai pengeluaran</strong>. Saat dikembalikan, malah jadi "pemasukan".</p>
                </div>
            </div>
            <p class="mt-6 font-medium text-emerald-800">{{ config('app.name') }} mencatat ketiganya dengan benar, otomatis, tanpa Anda perlu paham akuntansi.</p>
        </section>

        {{-- RULES --}}
        <section id="aturan" class="border-y border-slate-100 bg-slate-50">
            <div class="mx-auto max-w-6xl px-4 py-16">
                <h2 class="text-2xl font-semibold tracking-tight md:text-3xl">Enam aturan yang membuat laporan Anda jujur</h2>
                <p class="mt-3 max-w-3xl text-slate-600">Setiap transaksi dicatat dengan pembukuan berpasangan (<em>double-entry</em>): uang selalu punya asal dan tujuan, dan jumlahnya selalu seimbang. Inilah yang membedakan laporan yang sekadar rapi dari laporan yang benar.</p>

                <div class="mt-10 grid gap-5 md:grid-cols-2">
                    <article class="rounded-xl border border-slate-200 bg-white p-6">
                        <p class="text-2xl" aria-hidden="true">⬇️</p>
                        <h3 class="mt-2 text-lg font-semibold">1. Pemasukan adalah uang yang benar-benar menambah kekayaan</h3>
                        <p class="mt-2 text-slate-600">Gaji, hasil usaha atau kegiatan, bonus, hadiah. Setiap pemasukan masuk ke akun yang jelas (BCA, tunai, GoPay) dan dikelompokkan menurut sumbernya, sehingga Anda tahu berapa dari gaji dan berapa dari usaha sampingan.</p>
                        <p class="mt-3 rounded-md bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700">gaji November 10jt → Pemasukan gaji · masuk ke BCA</p>
                    </article>

                    <article class="rounded-xl border border-slate-200 bg-white p-6">
                        <p class="text-2xl" aria-hidden="true">⬆️</p>
                        <h3 class="mt-2 text-lg font-semibold">2. Setiap pengeluaran tercatat dari pos asalnya</h3>
                        <p class="mt-2 text-slate-600">Pengeluaran selalu menyebut <strong>dari akun mana uangnya keluar</strong>: rekening bank, saldo e-wallet, uang tunai, atau kartu kredit, lengkap dengan kategorinya. Anda bisa menjawab pertanyaan seperti "berapa jajan saya dari GoPay bulan ini?" dan saldo setiap akun selalu cocok dengan kenyataan.</p>
                        <p class="mt-3 rounded-md bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700">bayar listrik 350rb → Pengeluaran · Listrik · dari BNI</p>
                    </article>

                    <article class="rounded-xl border border-emerald-200 bg-white p-6 ring-1 ring-emerald-100">
                        <p class="text-2xl" aria-hidden="true">🔁</p>
                        <h3 class="mt-2 text-lg font-semibold">3. Transfer antar rekening sendiri bukan pengeluaran</h3>
                        <p class="mt-2 text-slate-600">Pindah dana dari BCA ke BNI, top-up e-wallet, tarik tunai di ATM, atau setor tunai: uang hanya berpindah kantong. Saldo kedua akun berubah, tetapi total kekayaan dan laporan pengeluaran Anda <strong>tidak berubah sedikit pun</strong>.</p>
                        <p class="mt-3 rounded-md bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700">transfer BCA ke BNI 2jt → Transfer · pengeluaran Rp0</p>
                    </article>

                    <article class="rounded-xl border border-emerald-200 bg-white p-6 ring-1 ring-emerald-100">
                        <p class="text-2xl" aria-hidden="true">🤝</p>
                        <h3 class="mt-2 text-lg font-semibold">4. Pinjaman yang Anda terima bukan pemasukan</h3>
                        <p class="mt-2 text-slate-600">Uang pinjaman memang menambah saldo, tetapi juga menambah hutang dengan jumlah yang sama. Aplikasi mencatat keduanya, lengkap dengan nama pihak pemberi pinjaman. Saat Anda mencicil, hutang berkurang, dan cicilan pokok itu tidak dihitung sebagai belanja.</p>
                        <p class="mt-3 rounded-md bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700">pinjam dari Pak Budi 2jt → Kas +2jt · Hutang ke Pak Budi +2jt</p>
                    </article>

                    <article class="rounded-xl border border-emerald-200 bg-white p-6 ring-1 ring-emerald-100">
                        <p class="text-2xl" aria-hidden="true">📒</p>
                        <h3 class="mt-2 text-lg font-semibold">5. Meminjamkan uang ke orang lain bukan pengeluaran</h3>
                        <p class="mt-2 text-slate-600">Uang yang Anda pinjamkan tetap milik Anda, hanya sedang dipegang orang lain. Buat akun <strong>Piutang</strong> (misalnya "Piutang Andi"), lalu catat sebagai transfer dari rekening Anda. Saat dikembalikan, catat transfer balik. Sisa piutang selalu terlihat dan tetap dihitung dalam kekayaan bersih Anda.</p>
                        <p class="mt-3 rounded-md bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700">BCA → Piutang Andi 1jt · pengeluaran Rp0 · kekayaan tetap</p>
                    </article>

                    <article class="rounded-xl border border-slate-200 bg-white p-6">
                        <p class="text-2xl" aria-hidden="true">🧾</p>
                        <h3 class="mt-2 text-lg font-semibold">6. Riwayat tidak pernah dihapus diam-diam</h3>
                        <p class="mt-2 text-slate-600">Salah catat? Batalkan, dan aplikasi membuat catatan pembalik, bukan menghapus. Saldo awal yang keliru dikoreksi sebagai "penyesuaian" yang tidak mengganggu laporan pemasukan dan pengeluaran. Jejaknya selalu bisa ditelusuri.</p>
                        <p class="mt-3 rounded-md bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700">Sesuaikan saldo dompet → Penyesuaian · bukan belanja</p>
                    </article>
                </div>
            </div>
        </section>

        {{-- RESULT --}}
        <section class="mx-auto max-w-6xl px-4 py-16">
            <div class="grid items-center gap-10 md:grid-cols-2">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight md:text-3xl">Hasilnya: angka yang bisa Anda percaya</h2>
                    <ul class="mt-5 space-y-3 text-slate-700">
                        <li class="flex gap-3"><span class="text-emerald-600" aria-hidden="true">✓</span><span><strong>Pengeluaran asli</strong>, tanpa tercampur transfer, top-up, atau cicilan pokok.</span></li>
                        <li class="flex gap-3"><span class="text-emerald-600" aria-hidden="true">✓</span><span><strong>Pemasukan asli</strong>, tanpa tercampur pinjaman atau piutang yang kembali.</span></li>
                        <li class="flex gap-3"><span class="text-emerald-600" aria-hidden="true">✓</span><span><strong>Saldo setiap akun</strong> cocok dengan mutasi bank dan isi dompet.</span></li>
                        <li class="flex gap-3"><span class="text-emerald-600" aria-hidden="true">✓</span><span><strong>Hutang per orang</strong>: tahu persis sisa hutang ke Pak Budi, ke bank, ke kartu kredit.</span></li>
                        <li class="flex gap-3"><span class="text-emerald-600" aria-hidden="true">✓</span><span><strong>Kekayaan bersih</strong> yang sebenarnya: semua aset dikurangi semua hutang.</span></li>
                    </ul>
                </div>
                <div class="rounded-2xl border border-slate-200 p-5" aria-label="Contoh ringkasan bulanan">
                    <p class="text-sm font-medium text-slate-500">Contoh ringkasan bulan ini</p>
                    <dl class="mt-3 divide-y divide-slate-100 text-sm">
                        <div class="flex justify-between py-2"><dt>Pemasukan</dt><dd class="tabular-nums">Rp10.500.000</dd></div>
                        <div class="flex justify-between py-2"><dt>Pengeluaran</dt><dd class="tabular-nums">Rp6.240.000</dd></div>
                        <div class="flex justify-between py-2 font-medium"><dt>Selisih (bisa ditabung)</dt><dd class="tabular-nums text-emerald-700">Rp4.260.000</dd></div>
                        <div class="flex justify-between py-2 text-slate-500"><dt>Transfer antar akun sendiri</dt><dd class="tabular-nums">Rp3.200.000 · tidak dihitung</dd></div>
                        <div class="flex justify-between py-2 text-slate-500"><dt>Pinjaman diterima</dt><dd class="tabular-nums">Rp2.000.000 · bukan pemasukan</dd></div>
                        <div class="flex justify-between py-2 text-slate-500"><dt>Dipinjamkan ke teman</dt><dd class="tabular-nums">Rp1.000.000 · bukan pengeluaran</dd></div>
                    </dl>
                    <p class="mt-3 text-xs text-slate-400">Angka ilustrasi.</p>
                </div>
            </div>
        </section>

        {{-- FEATURES --}}
        <section id="fitur" class="border-y border-slate-100 bg-slate-50">
            <div class="mx-auto max-w-6xl px-4 py-16">
                <h2 class="text-2xl font-semibold tracking-tight md:text-3xl">Semua yang Anda butuhkan, tanpa yang tidak perlu</h2>
                <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        ['💬', 'Catat dengan bahasa sehari-hari', 'Ketik "parkir 5rb" atau "gaji 10jt". Aplikasi memahami nominal (rb, jt), jenis transaksi, dan kategori, lalu meminta konfirmasi sebelum menyimpan.'],
                        ['📱', 'Bot Telegram pribadi', 'Catat dari ponsel dalam hitungan detik, cek /saldo dan /ringkasan kapan saja. Hanya ID Telegram yang Anda izinkan yang dilayani.'],
                        ['🏦', 'Semua kantong uang', 'Rekening bank, uang tunai/kas, e-wallet, kartu kredit, pinjaman, dan piutang, masing-masing dengan saldo sendiri.'],
                        ['🎯', 'Budget bulanan per kategori', 'Tetapkan batas makan, transportasi, atau belanja. Peringatan muncul di 80%, dan transfer serta cicilan pokok tidak pernah memakan budget.'],
                        ['📊', 'Laporan & ekspor', 'Tren 12 bulan, rincian per kategori, uang masuk menurut maknanya, dan ekspor CSV untuk Excel atau Google Sheets.'],
                        ['👥', 'Hutang per pihak', 'Lihat sisa hutang ke setiap orang atau lembaga, dan bayar dengan sekali ketik: "bayar hutang ke Budi 500rb".'],
                        ['✏️', 'Koreksi tanpa kacau', 'Ubah nama akun, perbaiki saldo awal, atau sesuaikan saldo hari ini. Koreksi tidak merusak laporan.'],
                        ['🔒', 'Anti catat ganda', 'Tombol simpan yang tertekan dua kali tetap hanya mencatat satu transaksi.'],
                        ['📦', 'Data milik Anda', 'Unduh seluruh data Anda kapan saja dalam format JSON. Tidak ada penguncian vendor.'],
                    ] as [$icon, $title, $body])
                        <div class="rounded-xl border border-slate-200 bg-white p-5">
                            <p class="text-2xl" aria-hidden="true">{{ $icon }}</p>
                            <h3 class="mt-2 font-semibold">{{ $title }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-slate-600">{{ $body }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- HOW --}}
        <section class="mx-auto max-w-6xl px-4 py-16">
            <h2 class="text-2xl font-semibold tracking-tight md:text-3xl">Mulai dalam 5 menit</h2>
            <ol class="mt-8 grid gap-5 md:grid-cols-3">
                <li class="rounded-xl border border-slate-200 p-5"><p class="text-sm font-medium text-emerald-700">Langkah 1</p><h3 class="mt-1 font-semibold">Daftarkan kantong uang Anda</h3><p class="mt-1 text-sm text-slate-600">Tambahkan rekening, dompet tunai, e-wallet, kartu kredit, dan pinjaman beserta saldo saat ini.</p></li>
                <li class="rounded-xl border border-slate-200 p-5"><p class="text-sm font-medium text-emerald-700">Langkah 2</p><h3 class="mt-1 font-semibold">Catat seperti mengirim chat</h3><p class="mt-1 text-sm text-slate-600">Dari web atau Telegram. Periksa ringkasan yang ditampilkan, lalu tekan Simpan.</p></li>
                <li class="rounded-xl border border-slate-200 p-5"><p class="text-sm font-medium text-emerald-700">Langkah 3</p><h3 class="mt-1 font-semibold">Lihat ke mana uang pergi</h3><p class="mt-1 text-sm text-slate-600">Dashboard, budget, dan laporan langsung terbarui, dengan angka yang bisa dipertanggungjawabkan.</p></li>
            </ol>
            <p class="mt-6 text-sm text-slate-600">
                Butuh langkah lengkap? Baca <a href="{{ route('docs') }}" class="font-medium text-emerald-700 underline">panduan pengguna</a>
                (akun, mencatat transaksi, catatan hutang, budget, laporan) dan
                <a href="{{ route('docs.file', ['path' => 'telegram-bot.html']) }}" class="font-medium text-emerald-700 underline">panduan bot Telegram</a>.
            </p>
        </section>

        {{-- SECURITY --}}
        <section id="keamanan" class="border-y border-slate-100 bg-slate-900 text-slate-100">
            <div class="mx-auto max-w-6xl px-4 py-16">
                <h2 class="text-2xl font-semibold tracking-tight md:text-3xl">Data keuangan adalah data paling pribadi Anda</h2>
                <p class="mt-3 max-w-3xl text-slate-300">Karena itu {{ config('app.name') }} dirancang untuk dijalankan di server Anda sendiri, dengan kontrol keamanan yang mengacu pada ISO/IEC 27001:2022 dan prinsip UU Pelindungan Data Pribadi.</p>
                <ul class="mt-8 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <li class="rounded-lg bg-slate-800 p-4"><strong class="block text-white">Server milik Anda</strong>Tidak ada pihak ketiga yang menyimpan atau menganalisis transaksi Anda.</li>
                    <li class="rounded-lg bg-slate-800 p-4"><strong class="block text-white">Akses terbatas</strong>Login hanya untuk email yang Anda izinkan. Pendaftaran publik bisa ditutup.</li>
                    <li class="rounded-lg bg-slate-800 p-4"><strong class="block text-white">Data seperlunya</strong>Hanya 4 digit terakhir nomor rekening atau kartu. Nomor lengkap, PIN, dan CVV tidak pernah diminta.</li>
                    <li class="rounded-lg bg-slate-800 p-4"><strong class="block text-white">Kata sandi aman</strong>Disimpan sebagai hash bcrypt, percobaan login dibatasi, dan ganti kata sandi mencabut semua sesi lain.</li>
                    <li class="rounded-lg bg-slate-800 p-4"><strong class="block text-white">Jejak audit</strong>Login, pencatatan, pembatalan, dan perubahan akun tercatat dan bisa ditelusuri.</li>
                    <li class="rounded-lg bg-slate-800 p-4"><strong class="block text-white">Integritas pembukuan</strong>Setiap jurnal wajib seimbang dan diperiksa otomatis setiap hari.</li>
                </ul>
            </div>
        </section>

        {{-- SELF HOSTING --}}
        <section id="self-hosting" class="mx-auto max-w-6xl px-4 py-16">
            <div class="grid gap-10 md:grid-cols-2">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight md:text-3xl">Self-hosting, mulai sekitar <span class="text-emerald-700">Rp30 ribu per bulan</span></h2>
                    <p class="mt-3 text-slate-600">Tidak ada biaya langganan aplikasi. Anda cukup menyewa satu VPS kecil dari penyedia lokal maupun global, lalu menjalankan aplikasi dengan Docker. Satu server cukup untuk Anda dan keluarga.</p>
                    <ul class="mt-5 space-y-2 text-slate-700">
                        <li class="flex gap-3"><span class="text-emerald-600" aria-hidden="true">✓</span>VPS 1 vCPU, RAM 2 GB direkomendasikan, mulai ±Rp30 ribu/bulan tergantung penyedia dan promo.</li>
                        <li class="flex gap-3"><span class="text-emerald-600" aria-hidden="true">✓</span><a href="https://dokploy.com" target="_blank" rel="noopener noreferrer" class="underline">Dokploy</a> (gratis, open-source) untuk deploy sekali klik dan HTTPS otomatis.</li>
                        <li class="flex gap-3"><span class="text-emerald-600" aria-hidden="true">✓</span>Database MySQL, antrean, dan penjadwal sudah termasuk dalam Docker Compose.</li>
                        <li class="flex gap-3"><span class="text-emerald-600" aria-hidden="true">✓</span>Migrasi database berjalan otomatis setiap deploy. Tidak perlu perintah manual.</li>
                    </ul>
                    <p class="mt-4 text-xs text-slate-500">Biaya domain dibayar terpisah (opsional, umumnya tahunan). Harga VPS ditentukan penyedia dan bisa berubah.</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
                    <h3 class="font-semibold">Langkah singkat</h3>
                    <ol class="mt-4 space-y-4 text-sm">
                        <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs text-white">1</span><span>Sewa VPS (Ubuntu) dan arahkan domain atau subdomain Anda ke IP server.</span></li>
                        <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs text-white">2</span><span>Pasang Dokploy dengan satu perintah dari dokumentasinya.</span></li>
                        <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs text-white">3</span><span>Buat service Docker Compose dari repository aplikasi ini.</span></li>
                        <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs text-white">4</span><span>Isi environment: APP_KEY, kata sandi database, dan akun admin dashboard.</span></li>
                        <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs text-white">5</span><span>Pasang domain dengan HTTPS (port 8080), lalu klik <strong>Deploy</strong>. Selesai.</span></li>
                    </ol>
                    <p class="mt-5 text-xs text-slate-500">Panduan lengkap tersedia di <code>docs/deployment/dokploy.md</code> pada repository.</p>
                </div>
            </div>

            @if ($contactUrl)
                <div id="kontak" class="mt-10 flex flex-col items-start justify-between gap-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-6 sm:flex-row sm:items-center">
                    <div>
                        <h3 class="font-semibold">Ingin dibantu memasang versi self-hosted?</h3>
                        <p class="mt-1 text-sm text-slate-600">Untuk informasi lebih lanjut atau minat pemasangan aplikasi open source ini di server Anda sendiri, hubungi <strong>{{ $contactName }}</strong>.</p>
                    </div>
                    <a href="{{ $contactUrl }}" target="_blank" rel="noopener noreferrer"
                       class="inline-flex shrink-0 items-center gap-2 rounded-md bg-emerald-700 px-5 py-3 text-sm font-medium text-white hover:bg-emerald-600">
                        <span aria-hidden="true">💬</span> Chat WhatsApp {{ $contactName }}
                    </a>
                </div>
            @endif
        </section>

        {{-- FAQ --}}
        <section id="tanya-jawab" class="border-t border-slate-100 bg-slate-50">
            <div class="mx-auto max-w-3xl px-4 py-16">
                <h2 class="text-2xl font-semibold tracking-tight md:text-3xl">Tanya jawab</h2>
                <div class="mt-6 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
                    @foreach ([
                        ['Apakah saya perlu paham akuntansi?', 'Tidak. Anda cukup menulis kalimat seperti biasa. Aplikasi yang menentukan apakah itu pemasukan, pengeluaran, transfer, atau pinjaman, dan Anda tinggal memeriksa sebelum menyimpan.'],
                        ['Mengapa top-up e-wallet tidak dihitung sebagai pengeluaran?', 'Karena uangnya masih milik Anda, hanya pindah dari rekening ke e-wallet. Pengeluaran baru tercatat saat saldo e-wallet itu dipakai berbelanja, dan dicatat dari akun e-wallet tersebut.'],
                        ['Bagaimana mencatat cicilan pinjaman?', 'Ketik misalnya "bayar hutang ke Budi 500rb". Saldo rekening berkurang dan hutang ke Budi berkurang dengan jumlah yang sama. Pengeluaran Anda tidak membengkak oleh pembayaran pokok.'],
                        ['Bagaimana jika saya meminjamkan uang ke teman?', 'Buat akun jenis Piutang (misalnya "Piutang Andi"), lalu catat transfer dari rekening Anda ke akun itu. Saat dibayar kembali, catat transfer sebaliknya. Tidak ada pengeluaran atau pemasukan palsu.'],
                        ['Bisakah dipakai bersama pasangan atau keluarga?', 'Bisa. Setiap orang punya login sendiri dengan data yang terpisah, dan Anda menentukan email siapa saja yang boleh masuk.'],
                        ['Apakah data saya aman jika server bermasalah?', 'Data tersimpan di database MySQL di server Anda. Lakukan backup rutin (Dokploy mendukung backup terjadwal), dan Anda juga bisa mengunduh semua data kapan saja.'],
                        ['Berapa total biaya bulanannya?', 'Hanya biaya VPS, mulai sekitar Rp30 ribu per bulan tergantung penyedia, ditambah domain jika Anda ingin alamat sendiri. Aplikasinya tidak berbayar langganan.'],
                    ] as [$q, $a])
                        <details class="group p-5">
                            <summary class="cursor-pointer list-none font-medium marker:hidden">
                                <span class="flex items-center justify-between gap-3">{{ $q }} <span class="text-slate-400 transition group-open:rotate-45" aria-hidden="true">+</span></span>
                            </summary>
                            <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $a }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- CTA --}}
        <section class="mx-auto max-w-6xl px-4 py-16 text-center">
            <h2 class="text-2xl font-semibold tracking-tight md:text-3xl">Berhenti menebak. Mulai tahu.</h2>
            <p class="mx-auto mt-3 max-w-2xl text-slate-600">Satu bulan mencatat dengan benar sudah cukup untuk melihat pola yang selama ini tersembunyi: ke mana gaji habis, berapa yang sebenarnya bisa ditabung, dan berapa sisa hutang Anda hari ini.</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ $primaryUrl }}" class="rounded-md bg-slate-900 px-6 py-3 text-sm font-medium text-white hover:bg-slate-700">{{ $primaryLabel }}</a>
                <a href="#self-hosting" class="rounded-md border border-slate-300 px-6 py-3 text-sm font-medium hover:bg-slate-100">Pasang di server sendiri</a>
            </div>
            @if ($contactUrl)
                <p class="mt-6 text-sm text-slate-600">Minat pasang self-hosted? Hubungi {{ $contactName }} via <a href="{{ $contactUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-emerald-700 underline">WhatsApp</a>.</p>
            @endif
        </section>
    </main>

    <footer class="border-t border-slate-200">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-6 text-xs text-slate-500">
            <p>💰 {{ config('app.name') }} · pembukuan pribadi yang jujur</p>
            <p class="flex gap-4">
                <a href="{{ route('docs') }}" class="hover:text-slate-900 hover:underline">Panduan pengguna</a>
                <a href="{{ route('docs.file', ['path' => 'telegram-bot.html']) }}" class="hover:text-slate-900 hover:underline">Panduan bot Telegram</a>
            </p>
            <p>Bukan nasihat keuangan atau investasi. Aplikasi ini membantu mencatat, keputusan tetap di tangan Anda.</p>
        </div>
    </footer>
</body>
</html>
