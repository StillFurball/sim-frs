@php
    $portalUrl = auth()->check() ? route('dashboard') : route('login');
    $features = [
        ['icon' => 'bolt', 'title' => 'Deteksi Bentrok Real-Time', 'text' => 'Validasi otomatis jadwal hari dan jam kuliah setiap kali kelas ditambahkan.'],
        ['icon' => 'users', 'title' => 'Transparansi Kuota Kelas', 'text' => 'Pantau sisa kursi kelas secara langsung tanpa perlu refresh halaman berulang kali.'],
        ['icon' => 'shield-check', 'title' => 'Validasi Prasyarat & SKS', 'text' => 'Sistem otomatis menyesuaikan maksimal SKS berdasarkan IPK dan mengecek kelulusan mata kuliah prasyarat.'],
    ];
    $steps = [
        ['title' => 'Login Akun Mahasiswa', 'text' => 'Masuk dengan email dan kata sandi akun KRSKu. Tidak perlu kredensial kampus.'],
        ['title' => 'Pilih Kelas & Cek Jadwal', 'text' => 'Susun mata kuliah tanpa bentrok, ikuti rekomendasi urutan ambilnya.'],
        ['title' => 'Validasi Dosen Wali', 'text' => 'Kirim draf KRS untuk disetujui secara digital.'],
    ];
    $primaryButton = 'inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 font-medium text-white shadow-sm transition hover:bg-indigo-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600';
    $input = 'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-slate-900 focus:border-indigo-600 focus:outline-2 focus:outline-indigo-600/30';
@endphp
<!DOCTYPE html>
<html lang="id" class="motion-safe:scroll-smooth">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>KRSKu · Susun rencana studi tanpa bentrok</title>
        <meta name="description" content="Cek kuota kelas secara real-time, hitung otomatis batas SKS, dan susun KRS tanpa bentrok jadwal." />
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        @fonts
        @vite(['resources/css/app.css'])
    </head>
    {{-- Alpine comes with the Livewire scripts the simulator injects. --}}
    <body class="bg-slate-50 font-sans text-slate-900 antialiased" x-data @if ($errors->any()) x-init="$refs.login?.showModal()" @endif>
        <header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/70 backdrop-blur-md">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-6 px-4 sm:px-6">
                <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold">
                    <span class="grid size-8 place-items-center rounded-lg bg-indigo-600 text-white"><flux:icon.academic-cap variant="mini" /></span>
                    KRSKu
                </a>
                <nav class="hidden items-center gap-8 text-sm text-slate-600 md:flex" aria-label="Halaman">
                    <a href="#simulasi" class="transition hover:text-slate-900">Simulasi KRS</a>
                    <a href="#keunggulan" class="transition hover:text-slate-900">Keunggulan</a>
                    <a href="#alur" class="transition hover:text-slate-900">Alur Pengisian</a>
                </nav>
                <a href="{{ $portalUrl }}" @guest x-on:click.prevent="$refs.login.showModal()" @endguest class="{{ $primaryButton }} px-4 py-2 text-sm">
                    {{ auth()->check() ? 'Dashboard' : 'Masuk Portal' }}
                </a>
            </div>
        </header>

        <main>
            <section class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24">
                <div>
                    @if ($period)
                        <p class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-700">
                            <span class="size-2 rounded-full bg-emerald-500 motion-safe:animate-pulse" aria-hidden="true"></span>
                            Periode Pengisian KRS {{ $period->term->label() }} {{ $period->academicYear() }} Aktif
                        </p>
                    @else
                        <p class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1 text-sm font-medium text-slate-600">
                            <span class="size-2 rounded-full bg-slate-400" aria-hidden="true"></span>
                            Periode pengisian KRS belum dibuka
                        </p>
                    @endif

                    <h1 class="mt-6 text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                        Susun Rencana Studi Lebih Cepat, <span class="text-indigo-600">Tanpa Bentrok Jadwal.</span>
                    </h1>
                    <p class="mt-5 max-w-xl text-lg text-pretty text-slate-600">
                        Cek kuota kelas secara real-time, hitung otomatis batas SKS, dan ajukan persetujuan dosen wali dalam satu antarmuka yang bersih.
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <a href="{{ $portalUrl }}" @guest x-on:click.prevent="$refs.login.showModal()" @endguest class="{{ $primaryButton }} px-5 py-2.5">
                            Mulai Isi KRS <flux:icon.arrow-right variant="mini" />
                        </a>
                        <a href="#simulasi" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-5 py-2.5 font-medium text-slate-700 shadow-sm transition hover:border-slate-300 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                            Coba Simulasi di Samping
                        </a>
                    </div>
                </div>

                <div id="simulasi" class="scroll-mt-24">
                    <livewire:krs-simulator />
                </div>
            </section>

            <section id="keunggulan" class="mx-auto max-w-6xl scroll-mt-16 px-4 py-16 sm:px-6" aria-labelledby="keunggulan-title">
                <h2 id="keunggulan-title" class="text-2xl font-semibold tracking-tight sm:text-3xl">Tiga hal yang membuat isi KRS tenang</h2>
                <div class="mt-8 grid gap-6 md:grid-cols-3">
                    @foreach ($features as $feature)
                        <article class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                            <span class="grid size-10 place-items-center rounded-lg bg-indigo-50 text-indigo-600"><flux:icon :name="$feature['icon']" class="size-5" /></span>
                            <h3 class="mt-4 font-semibold">{{ $feature['title'] }}</h3>
                            <p class="mt-2 text-sm text-slate-600">{{ $feature['text'] }}</p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section id="alur" class="mx-auto max-w-6xl scroll-mt-16 px-4 py-16 sm:px-6" aria-labelledby="alur-title">
                <h2 id="alur-title" class="text-2xl font-semibold tracking-tight sm:text-3xl">Alur pengisian KRS</h2>
                <ol class="mt-8 grid gap-8 md:grid-cols-3">
                    @foreach ($steps as $step)
                        <li class="border-t-2 border-indigo-600/20 pt-5">
                            <span class="text-sm font-semibold text-indigo-600 tabular-nums">{{ sprintf('%02d', $loop->iteration) }}</span>
                            <h3 class="mt-1 font-semibold">{{ $step['title'] }}</h3>
                            <p class="mt-2 text-sm text-slate-600">{{ $step['text'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>
        </main>

        <footer class="border-t border-slate-200/80">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-6 text-sm text-slate-500 sm:px-6">
                <p>© {{ now()->year }} KRSKu. Simulasi rencana studi mahasiswa.</p>
                <a href="#simulasi" class="transition hover:text-slate-900">Coba simulasi</a>
            </div>
        </footer>

        @guest
            {{-- Native dialog: focus trap, Esc and the backdrop come from the platform. --}}
            <dialog x-ref="login" x-on:click.self="$el.close()" aria-labelledby="login-title"
                class="m-auto w-[calc(100%-2rem)] max-w-sm rounded-2xl border border-slate-200/80 bg-white p-0 shadow-xl backdrop:bg-slate-900/40 backdrop:backdrop-blur-sm">
                <form method="POST" action="{{ route('login.store') }}" class="p-6">
                    @csrf
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 id="login-title" class="text-lg font-semibold">Masuk Portal</h2>
                            <p class="mt-1 text-sm text-slate-500">Pakai akun KRSKu, tanpa kredensial kampus.</p>
                        </div>
                        <button type="button" x-on:click="$refs.login.close()" aria-label="Tutup" class="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
                            <flux:icon.x-mark variant="mini" />
                        </button>
                    </div>

                    @error('email')
                        <p role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p>
                    @enderror

                    <label class="mt-4 block text-sm font-medium text-slate-700">
                        Email
                        <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email" class="{{ $input }}">
                    </label>
                    <label class="mt-4 block text-sm font-medium text-slate-700">
                        Kata sandi
                        <input type="password" name="password" required autocomplete="current-password" class="{{ $input }}">
                    </label>

                    <button type="submit" class="{{ $primaryButton }} mt-6 w-full px-4 py-2.5">Masuk</button>

                    @if (Route::has('register'))
                        <p class="mt-4 text-center text-sm text-slate-500">
                            Belum punya akun? <a href="{{ route('register') }}" class="font-medium text-indigo-600 hover:underline">Buat akun</a>
                        </p>
                    @endif
                </form>
            </dialog>
        @endguest
    </body>
</html>
