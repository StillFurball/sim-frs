@php
    $dayShort = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => "Jum"];
    $allClasses = collect($trialCourses)->flatMap(fn ($course) => collect($course['classes'])->map(fn ($class) => $class + ['course' => $course]));
    $selected = $allClasses->whereIn('id', $preselectedClassIds);
    $selectedSks = $selected->sum(fn ($class) => $class['course']['sks']);
    $startAction = auth()->check() ? route('dashboard') : route('login');
    $startLabel = auth()->check() ? 'Buka dashboard' : 'Mulai simulasi';
    $featured = $trialCourses[0] ?? null;
    $trialPayload = ['courses' => $trialCourses, 'selected' => $preselectedClassIds, 'startTimes' => $startTimes, 'max' => $maxSks];
    $slips = [
        ['title' => 'Rencana 1', 'meta' => '24 SKS · 4 hari', 'tilt' => -1.5, 'height' => 40, 'lines' => [
            ['Sen 07:00', 'Animasi 3D T'], ['Sen 10:00', 'PPB A'], ['Sen 13:30', 'Komputasi Bergerak T'], ['Sen 15:30', 'IMK C'],
            ['Sel 10:00', 'Keamanan Jaringan T'], ['Sel 13:30', 'Keamanan Aplikasi T'], ['Rab 10:00', 'Konstruksi PL T'], ['Jum 13:30', 'Game Engine T'],
        ]],
        ['title' => 'Rencana 2', 'meta' => '24 SKS · 3 hari', 'tilt' => 0, 'height' => 33, 'lines' => [
            ['Sen 07:00', 'Animasi 3D T'], ['Sen 10:00', 'Deep Learning T'], ['Sen 13:30', 'Komputasi Bergerak T'], ['Sen 15:30', 'IMK C'],
            ['Sel 07:00', 'Microservice M'], ['Sel 10:00', 'PPB E'], ['Sel 13:30', 'Kualitas PL T'], ['Rab 13:30', 'Audit Sistem T'],
        ]],
        ['title' => 'Tanpa kelas pagi', 'meta' => '24 SKS · mulai 10:00', 'tilt' => 1.2, 'height' => 44, 'lines' => [
            ['Sen 10:00', 'Text Mining T'], ['Sen 13:30', 'Komputasi Bergerak T'], ['Sen 15:30', 'IMK C'], ['Sel 10:00', 'Keamanan Jaringan T'],
            ['Sel 13:30', 'Keamanan Aplikasi T'], ['Rab 10:00', 'PPB C'], ['Rab 13:30', 'Game Edukasi T'], ['Jum 13:30', 'Desain UX T'],
        ]],
        ['title' => "Jum'at kosong", 'meta' => '21 SKS · 4 hari', 'tilt' => 0, 'height' => 29, 'lines' => [
            ['Sen 07:00', 'Animasi 3D T'], ['Sen 10:00', 'PPB A'], ['Sen 15:30', 'IMK C'], ['Sel 10:00', 'Keamanan Jaringan T'],
            ['Sel 13:30', 'Keamanan Aplikasi T'], ['Rab 07:00', 'Simulasi Agen T'], ['Kam 07:00', 'Sistem Dinamis T'],
        ]],
        ['title' => 'Batas 18 SKS', 'meta' => '18 SKS · 4 hari', 'tilt' => -1, 'height' => 37, 'lines' => [
            ['Sen 10:00', 'PPB A'], ['Sen 15:30', 'IMK C'], ['Sel 13:30', 'Kualitas PL T'], ['Rab 10:00', 'Deep Learning U'],
            ['Rab 13:30', 'Audit Sistem T'], ['Jum 13:30', 'Game Engine T'],
        ]],
        ['title' => 'Tanpa Senin', 'meta' => '24 SKS · 4 hari', 'tilt' => 0, 'height' => 31, 'lines' => [
            ['Sel 07:00', 'Microservice M'], ['Sel 10:00', 'PPB E'], ['Sel 13:30', 'IMK A'], ['Rab 07:00', 'Simulasi Agen T'],
            ['Rab 10:00', 'Konstruksi PL T'], ['Rab 13:30', 'Game Edukasi T'], ['Kam 07:00', 'Sistem Dinamis T'], ['Jum 13:30', 'Desain UX T'],
        ]],
    ];
@endphp
<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>KRS tanpa bentrok · {{ config('app.name', 'KRS') }}</title>
        <meta name="description" content="Simulator Kartu Rencana Studi untuk mahasiswa Informatika: susun kelas dari jadwal resmi dan lihat bentrok sebelum menyimpan." />
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Big+Shoulders:wght@800&family=Public+Sans:wght@400;600&display=swap" rel="stylesheet">
        @vite(['resources/css/landing.css', 'resources/js/landing.js'])
    </head>
    <body class="landing">
        <main class="sheet-stack">
            {{-- The card: the page opens as the Kartu Rencana Studi itself. --}}
            <section class="hero" aria-labelledby="hero-title">
                <header class="topbar">
                    <a class="wordmark" href="{{ route('home') }}">KRS Informatika</a>
                    <nav class="topbar__links" aria-label="Halaman">
                        <a href="#cara-kerja">Cara kerja</a>
                        <a href="#rencana">Rencana otomatis</a>
                        <a href="#pertanyaan">Pertanyaan</a>
                    </nav>
                    <a class="button button--paper" href="{{ $startAction }}">{{ auth()->check() ? 'Dashboard' : 'Masuk' }}</a>
                </header>

                <article class="card" data-reveal>
                    <span class="card__scallop card__scallop--tl" aria-hidden="true"></span>
                    <span class="card__scallop card__scallop--tr" aria-hidden="true"></span>
                    <span class="card__scallop card__scallop--bl" aria-hidden="true"></span>
                    <span class="card__scallop card__scallop--br" aria-hidden="true"></span>

                    <h1 id="hero-title" class="card__title">
                        <span class="card__title-line card__title-line--left">KRS tanpa</span>
                        <span class="card__title-line card__title-line--right">bentrok</span>
                    </h1>
                    <p class="card__lead">
                        Simulator Kartu Rencana Studi untuk mahasiswa Informatika. Susun kelas dari jadwal resmi
                        semester ini dan lihat bentrok sebelum kamu menyimpannya.
                    </p>
                    <p class="card__details">
                        @if ($period)
                            <span>Semester {{ $period->term->label() }} {{ $period->academicYear() }}</span>
                            <span class="diamond" aria-hidden="true"></span>
                            <span>{{ $stats['classes'] }} kelas paralel</span>
                            <span class="diamond" aria-hidden="true"></span>
                            <span>{{ $stats['lecturers'] }} dosen</span>
                        @else
                            <span>Jadwal semester ini belum diunggah</span>
                        @endif
                    </p>
                    <a class="button button--ink card__action" href="{{ $startAction }}">{{ $startLabel }}</a>

                    <div class="trial" id="coba" aria-labelledby="trial-title">
                        <div class="trial__head">
                            <h2 id="trial-title" class="trial__title">Coba isi kartumu</h2>
                            <div class="trial__limit" role="radiogroup" aria-label="Batas SKS">
                                <span class="trial__limit-label" aria-hidden="true">Batas</span>
                                @foreach ($sksLimits as $limit)
                                    <button type="button" class="chip" role="radio" data-limit="{{ $limit }}" aria-checked="{{ $limit === $maxSks ? 'true' : 'false' }}" aria-label="Batas {{ $limit }} SKS">{{ $limit }}</button>
                                @endforeach
                            </div>
                        </div>

                        @if (count($trialCourses) > 0)
                            <div class="trial__body">
                                <ul class="trial__courses" aria-label="Mata kuliah">
                                    @foreach ($trialCourses as $course)
                                        <li class="course-row">
                                            <span class="course-row__name">{{ $course['name'] }}</span>
                                            <span class="course-row__meta">{{ $course['short'] }} · {{ $course['sks'] }} SKS · {{ $course['type'] }}</span>
                                            <span class="course-row__classes">
                                                @foreach ($course['classes'] as $class)
                                                    <button type="button" class="chip chip--class" data-class="{{ $class['id'] }}"
                                                        aria-pressed="{{ in_array($class['id'], $preselectedClassIds, true) ? 'true' : 'false' }}"
                                                        aria-label="{{ $course['short'] }} kelas {{ $class['label'] }}, {{ $days[$class['day'] - 1]->label() }} {{ $class['start'] }}–{{ $class['end'] }}">{{ $class['label'] }}</button>
                                                @endforeach
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>

                                <div class="trial__sheet">
                                    <table class="week" data-ui>
                                        <caption class="sr-only">Jadwal mingguan kartumu</caption>
                                        <thead>
                                            <tr>
                                                <th scope="col"><span class="sr-only">Jam</span></th>
                                                @foreach ($days as $day)
                                                    <th scope="col"><abbr title="{{ $day->label() }}">{{ $dayShort[$day->value] }}</abbr></th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody data-week>
                                            @foreach ($startTimes as $start)
                                                <tr>
                                                    <th scope="row">{{ $start }}</th>
                                                    @foreach ($days as $day)
                                                        @php($here = $selected->where('day', $day->value)->where('start', $start))
                                                        <td>
                                                            @foreach ($here as $class)
                                                                <span class="slot"><b>{{ $class['course']['short'] }} {{ $class['label'] }}</b><span>{{ $class['room'] }}</span></span>
                                                            @endforeach
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>

                                    <p class="tally" aria-live="polite">
                                        <span class="tally__figure"><span data-sks>{{ $selectedSks }}</span> / <span data-max>{{ $maxSks }}</span> SKS</span>
                                        <span class="tally__note" data-note>Tidak ada bentrok</span>
                                    </p>
                                </div>
                            </div>
                            <script type="application/json" id="trial-data">{!! json_encode($trialPayload, JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
                        @else
                            <p class="trial__empty">Jadwal semester ini belum diunggah. Begitu admin mengimpor file jadwal, kartu ini terisi dengan kelas sungguhan.</p>
                        @endif
                    </div>
                </article>
            </section>

            {{-- The process, printed as a slip. --}}
            <section class="steps" id="cara-kerja" aria-labelledby="steps-title">
                <div class="steps__intro" data-reveal>
                    <h2 id="steps-title" class="heading">Dari jadwal ke kartu</h2>
                    <p>
                        Jadwal Informatika datang sebagai satu lembar kerja berisi puluhan kelas paralel. Kartu ini
                        membacanya untukmu, menjaga batas SKS, dan menandai setiap jam yang beririsan.
                    </p>
                    <figure class="steps__figure">
                        <img src="{{ asset('images/landing/punch-card-1.jpg') }}" width="1920" height="991" alt="Tumpukan kartu berlubang dengan kolom angka 0 sampai 9 tercetak merah muda" loading="lazy" decoding="async">
                        <figcaption>Kartu berlubang: cara lama mencatat pilihan, satu kolom satu angka.</figcaption>
                    </figure>
                </div>

                <div class="slip" data-reveal>
                    <h3 class="slip__group">Sebelum periode KRS</h3>
                    <p class="slip__row"><span>Admin mengunggah file jadwal .xlsx</span><i aria-hidden="true"></i><span>1 file</span></p>
                    <p class="slip__row"><span>Mata kuliah, kelas, ruang dan dosen terbaca</span><i aria-hidden="true"></i><span>{{ $stats['classes'] ?: 45 }} kelas</span></p>

                    <h3 class="slip__group">Saat periode KRS</h3>
                    <p class="slip__row"><span>Pilih satu kelas paralel per mata kuliah</span><i aria-hidden="true"></i><span>A sampai F</span></p>
                    <p class="slip__row"><span>Bentrok dan kelas penuh ditandai</span><i aria-hidden="true"></i><span>langsung</span></p>
                    <p class="slip__row"><span>Atau minta rencana otomatis</span><i aria-hidden="true"></i><span>hingga 5 rencana</span></p>

                    <h3 class="slip__group">Sebelum bimbingan</h3>
                    <p class="slip__row"><span>Simpan kartu, bawa ke dosen wali</span><i aria-hidden="true"></i><span>1 klik</span></p>

                    <p class="slip__total"><span>KRS siap ditandatangani</span><span>{{ $maxSks }} SKS, 0 bentrok</span></p>
                </div>
            </section>

            {{-- Planner output, as slips along the edge. --}}
            <section class="showcase" id="rencana" aria-labelledby="showcase-title">
                <img class="showcase__photo" data-drift src="{{ asset('images/landing/its-library.jpg') }}" width="1920" height="960" alt="Gedung Perpustakaan ITS di malam hari, jendela-jendelanya menyala kuning" loading="lazy" decoding="async">
                <div class="showcase__text" data-reveal>
                    <h2 id="showcase-title" class="heading heading--paper">Rencana otomatis</h2>
                    <p>Tandai mata kuliah prioritas, dosen yang kamu cari, hari yang ingin kosong dan jam paling pagi yang masih sanggup kamu hadiri.</p>
                    <p>Sistem menyusun semua kombinasi yang muat tanpa bentrok, memberi skor, dan mencetak yang terbaik.</p>
                </div>
                <ol class="slips" aria-label="Contoh hasil rencana otomatis">
                    @foreach ($slips as $slip)
                        <li class="output-slip" style="--tilt: {{ $slip['tilt'] }}deg; --h: {{ $slip['height'] }}svh">
                            <b>{{ $slip['title'] }}</b>
                            <span class="output-slip__meta">{{ $slip['meta'] }}</span>
                            <span class="output-slip__cut" aria-hidden="true">- - - - - - - - - - - - - -</span>
                            @foreach ($slip['lines'] as [$when, $what])
                                <span class="output-slip__line"><span>{{ $when }}</span><span>{{ $what }}</span></span>
                            @endforeach
                        </li>
                    @endforeach
                </ol>
            </section>

            {{-- What the card does, as stacked colour sheets. --}}
            <section class="features" id="fitur" aria-labelledby="features-title">
                <h2 id="features-title" class="heading features__title" data-reveal>Yang dikerjakan kartu ini</h2>

                <article class="color-sheet color-sheet--paper" style="--i: 0" data-reveal>
                    <h3 class="color-sheet__name">Isi KRS</h3>
                    <p class="color-sheet__text">Setiap mata kuliah menampilkan kelas paralelnya lengkap dengan jadwal, ruang, dosen dan kuota. Ambil satu, ganti kapan saja; total SKS dan bentrok dihitung ulang seketika.</p>
                    <div class="color-sheet__media color-sheet__media--ui">
                        @if ($featured)
                            <table class="classes-table" data-ui>
                                <caption>{{ $featured['name'] }} · {{ $featured['sks'] }} SKS</caption>
                                <thead>
                                    <tr><th scope="col">Kelas</th><th scope="col">Jadwal</th><th scope="col">Ruang</th><th scope="col">Dosen</th><th scope="col">Kuota</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($featured['classes'] as $class)
                                        <tr>
                                            <td><b>{{ $class['label'] }}</b></td>
                                            <td>{{ $days[$class['day'] - 1]->label() }} {{ $class['start'] }}–{{ $class['end'] }}</td>
                                            <td>{{ $class['room'] }}</td>
                                            <td>{{ $class['lecturers'] ?: '–' }}</td>
                                            <td>{{ $class['capacity'] ?? '–' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </article>

                <article class="color-sheet color-sheet--moss" style="--i: 1" data-reveal>
                    <h3 class="color-sheet__name">Rencana otomatis</h3>
                    <p class="color-sheet__text">Jadikan mata kuliah wajib, prioritas atau dikecualikan. Pilih dosen yang dicari atau dihindari, padatkan hari, kurangi jeda antar kelas. Hasilnya diurutkan dari skor tertinggi.</p>
                    <div class="color-sheet__media">
                        <img src="{{ asset('images/landing/mathematics-lecture-at-the-helsinki-university-of.jpg') }}" width="1200" height="900" alt="Kuliah aljabar linear: papan tulis penuh perhitungan matriks di depan mahasiswa" loading="lazy" decoding="async">
                    </div>
                </article>

                <article class="color-sheet color-sheet--ink" style="--i: 2" data-reveal>
                    <h3 class="color-sheet__name">Batas SKS</h3>
                    <p class="color-sheet__text">Setiap mahasiswa punya batasnya sendiri: 24, 22, 20 atau 18 SKS. Kartu menolak kelas yang melewati batas, jadi rencana yang kamu bawa ke dosen wali memang bisa disetujui.</p>
                    <div class="color-sheet__media color-sheet__media--figures" aria-hidden="true">
                        @foreach ($sksLimits as $limit)
                            <span>{{ $limit }}</span>
                        @endforeach
                    </div>
                </article>

                <article class="color-sheet color-sheet--sand" style="--i: 3" data-reveal>
                    <h3 class="color-sheet__name">Import jadwal</h3>
                    <p class="color-sheet__text">Admin cukup mengunggah file .xlsx dari bagian akademik. Mata kuliah, kelas paralel, ruang, kode dosen dan jadwal Ramadhan langsung masuk ke basis data, per periode akademik.</p>
                    <figure class="color-sheet__media">
                        <img src="{{ asset('images/landing/ibm-card-storage-nara.jpg') }}" width="1920" height="1516" alt="Gudang arsip berisi ribuan kotak kartu IBM yang ditumpuk dan diikat" loading="lazy" decoding="async">
                        <figcaption>Arsip kartu IBM di Alexandria, Virginia, 1959. Sekarang cukup satu file.</figcaption>
                    </figure>
                </article>
            </section>

            {{-- Questions beside a tall photograph. --}}
            <section class="faq" id="pertanyaan" aria-labelledby="faq-title">
                <div class="faq__text" data-reveal>
                    <h2 id="faq-title" class="heading">Pertanyaan</h2>
                    <dl class="faq__facts">
                        <div><dt>Periode aktif</dt><dd>{{ $period ? 'Semester '.$period->term->label().' '.$period->academicYear() : 'Belum ada' }}</dd></div>
                        <div><dt>Jadwal</dt><dd>{{ $stats['courses'] }} mata kuliah, {{ $stats['classes'] }} kelas, {{ $stats['lecturers'] }} dosen</dd></div>
                        <div><dt>Batas SKS</dt><dd>{{ implode(', ', $sksLimits) }}, sesuai ketetapan per mahasiswa</dd></div>
                    </dl>

                    <div class="faq__list">
                        <details>
                            <summary>Apakah ini KRS resmi?</summary>
                            <p>Bukan. Ini simulasi: susun dan uji rencanamu di sini, lalu isi KRS resmi di sistem akademik kampus.</p>
                        </details>
                        <details>
                            <summary>Kapan dua kelas dianggap bentrok?</summary>
                            <p>Saat harinya sama dan jamnya beririsan. Senin 07:00–09:20 dan Senin 09:00–10:50 bentrok, walau hanya dua puluh menit.</p>
                        </details>
                        <details>
                            <summary>Bagaimana kalau kelasnya penuh?</summary>
                            <p>Kelas yang mencapai kuota tidak bisa diambil. Rencana otomatis melewatinya, kecuali kamu mematikan pilihan itu.</p>
                        </details>
                        <details>
                            <summary>Bisakah saya menghindari hari atau dosen tertentu?</summary>
                            <p>Bisa. Di rencana otomatis, tandai hari yang ingin kosong, jam mulai paling awal dan selesai paling akhir, serta dosen yang dicari atau dihindari.</p>
                        </details>
                        <details>
                            <summary>Apakah kelas yang sudah saya ambil ikut dihitung?</summary>
                            <p>Bisa ikut. Aktifkan "pertahankan kelas saat ini" dan rencana otomatis hanya mengisi sisa SKS di sekitarnya.</p>
                        </details>
                        <details>
                            <summary>Kapan KRS bisa diubah?</summary>
                            <p>Hanya pada periode akademik yang sedang aktif. Periode sebelumnya tetap bisa dilihat, tidak bisa diubah.</p>
                        </details>
                    </div>

                    <div class="faq__actions">
                        <a class="button button--accent" href="{{ $startAction }}">{{ $startLabel }}</a>
                        @guest
                            @if (Route::has('register'))
                                <a class="text-link" href="{{ route('register') }}">Buat akun mahasiswa</a>
                            @endif
                        @endguest
                    </div>
                </div>

                <div class="faq__photos" data-reveal>
                    <img class="faq__tall" src="{{ asset('images/landing/its-robotics-center.jpg') }}" width="1920" height="1343" alt="Gedung ITS Robotics Center dengan menara rangka baja, dilihat dari padang rumput" loading="lazy" decoding="async">
                    <img class="faq__square" src="{{ asset('images/landing/punch-card-1.jpg') }}" width="1920" height="991" alt="Detail kolom angka pada kartu berlubang" loading="lazy" decoding="async">
                </div>
            </section>

            {{-- The reply card. --}}
            <section class="reply" aria-labelledby="reply-title">
                <p class="reply__note">Kelas paralel terisi siapa cepat dia dapat. Datang ke hari pengisian KRS dengan rencana yang sudah teruji, bukan dengan tebakan.</p>
                <div class="reply__card">
                    <h2 id="reply-title" class="reply__title">Isi kartumu</h2>
                    <p>Masuk dengan akun mahasiswa, pilih kelas, simpan.<br>Bentrok ketahuan sebelum kamu menyimpannya.</p>
                    <a class="button button--ink button--bar" href="{{ $startAction }}">{{ $startLabel }}</a>
                </div>
            </section>
        </main>

        {{-- The back of the card. --}}
        <footer class="back">
            <div class="back__panel">
                <span class="back__scallops" aria-hidden="true">@for ($i = 0; $i < 12; $i++)<i></i>@endfor</span>
                <div class="back__message" data-reveal>
                    <h2 class="back__title">Sampai di kelas</h2>
                    <p>Kartu ini dibuat untuk satu minggu kuliah yang tidak saling bertabrakan: Senin sampai Jum'at, pukul 07:00 sampai 17:20.</p>
                </div>
                <div class="back__address">
                    <img class="back__stamp" src="{{ asset('images/landing/punch-card-1.jpg') }}" width="1920" height="991" alt="" loading="lazy" decoding="async">
                    <ul>
                        @auth
                            <li><a href="{{ route('dashboard') }}">Dashboard</a></li>
                        @else
                            <li><a href="{{ route('login') }}">Masuk</a></li>
                            @if (Route::has('register'))
                                <li><a href="{{ route('register') }}">Buat akun</a></li>
                            @endif
                            @if (Route::has('password.request'))
                                <li><a href="{{ route('password.request') }}">Lupa kata sandi</a></li>
                            @endif
                        @endauth
                        <li><a href="#coba">Coba kartu</a></li>
                    </ul>
                </div>
                <p class="back__imprint">
                    KRS Informatika, {{ $period?->academicYear() ?? now()->year }}. Foto via Wikimedia Commons:
                    <a href="https://commons.wikimedia.org/wiki/File:ITS_Library.jpg">ITS Library</a>, Institut Teknologi Sepuluh Nopember, CC BY-SA 4.0;
                    <a href="https://commons.wikimedia.org/wiki/File:ITS_Robotics_Center.jpg">ITS Robotics Center</a>, Azaimfuad215, CC BY-SA 4.0;
                    <a href="https://commons.wikimedia.org/wiki/File:Punch_card_1.jpg">Punch card 1</a>, Maksym Kozlenko, CC BY-SA 4.0;
                    <a href="https://commons.wikimedia.org/wiki/File:IBM_card_storage.NARA.jpg">IBM card storage</a>, NARA, domain publik;
                    <a href="https://commons.wikimedia.org/wiki/File:Mathematics_lecture_at_the_Helsinki_University_of_Technology.jpg">Mathematics lecture</a>, Tungsten, domain publik.
                </p>
            </div>
        </footer>
    </body>
</html>
