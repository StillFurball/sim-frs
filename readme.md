# Simulasi KRS

Aplikasi web untuk mensimulasikan pengisian Kartu Rencana Studi (KRS). Mahasiswa mengambil kelas sesuai batas SKS (24/22/20/18), bentrok jadwal terdeteksi otomatis, dan sistem bisa mencarikan kombinasi mata kuliah wajib + pilihan terbaik. Admin mengelola jadwal per tahun ajaran dan semester, termasuk impor langsung dari file `.xlsx`.

Dibangun dengan Laravel 13, Livewire 4, Flux UI, Tailwind CSS 4, dan SQLite.

## Fitur

**Mahasiswa**
- **Isi KRS** – ambil, lepas, atau pindah kelas. Kelas yang bentrok, penuh, atau melebihi batas SKS langsung ditandai beserta alasannya. Jadwal mingguan tampil sebagai grid.
- **Rencana Otomatis** – mencari kombinasi kelas tanpa bentrok: semua mata kuliah wajib semester + minimal N mata kuliah pilihan. Bisa diatur:
  - per mata kuliah: wajib ambil / prioritaskan / hindari
  - per dosen: suka / hindari
  - hari yang dihindari, jam paling awal/akhir, bahasa, prodi
  - minimalkan jumlah hari kuliah atau jeda antar kelas
  - pertahankan kelas yang sudah ada di KRS

  Hasil terbaik bisa langsung diterapkan ke KRS.
- **Riwayat per periode** – KRS periode lain bisa dilihat (hanya baca).

**Admin**
- **Periode Akademik** – buat tahun ajaran (sekaligus semester Ganjil & Genap), aktifkan periode yang dibuka untuk KRS.
- **Import Jadwal** – unggah `.xlsx` atau `.csv` ke periode tertentu.
- CRUD **Mata Kuliah**, **Kelas & Jadwal**, **Dosen**, dan **Mahasiswa** (termasuk melihat dan melepas kelas di KRS mahasiswa).

## Instalasi

Kebutuhan: PHP 8.3+ (dengan ekstensi `zip`, `xmlreader`, `pdo_sqlite`), Composer, dan Node.js.

```bash
composer setup            # install dependency, buat .env, migrate, build aset
php artisan db:seed       # akun demo + periode + jadwal contoh
composer run dev          # jalankan server di http://localhost:8000
```

### Akun demo

Dibuat oleh `php artisan db:seed`, semua dengan kata sandi `password`:

| Peran | Email | Batas SKS |
|---|---|---|
| Admin | `admin@krs.test` | – |
| Mahasiswa | `mahasiswa@krs.test` | 24 |
| Mahasiswa | `mahasiswa20@krs.test` | 20 |

Seeder juga membuat periode 2024/2025 dan 2025/2026 (Ganjil & Genap), mengaktifkan **2025/2026 Genap**, lalu mengimpor jadwal contoh IF semester 6 & 8 (44 kelas, 24 mata kuliah) dari `database/seeders/data/jadwal-if-genap-2025-2026.xlsx`.

Untuk mengulang database dari awal: `php artisan migrate:fresh --seed`.

## Aturan KRS

- Hanya **periode aktif** yang bisa diubah; periode lain hanya bisa dilihat.
- Satu mata kuliah hanya boleh diambil di **satu kelas** per periode. Mengambil kelas lain dari mata kuliah yang sama berarti pindah kelas.
- Tidak boleh ada **jadwal yang tumpang tindih** (kelas yang berakhir 08:50 dan kelas yang mulai 08:50 tidak dianggap bentrok).
- Total SKS tidak boleh melebihi **batas SKS** mahasiswa. Batas dihitung per periode.
- Kelas yang **penuh** tidak bisa diambil (kapasitas kosong berarti tanpa batas).

Aturan ini ditegakkan di satu tempat (`app/Actions/Krs/SyncStudentClasses.php`), sehingga berlaku sama untuk pengisian manual, rencana otomatis, dan pemindahan kelas.

## Format file jadwal

Baris header dideteksi otomatis, jadi baris judul di atas tabel dan baris ringkasan di bawahnya diabaikan. Nama kolom tidak peka huruf besar/kecil, dan teks dalam kurung diabaikan (mis. "Dosen (kode)").

| Kolom | Wajib | Keterangan |
|---|---|---|
| Mata Kuliah | ya | Nama mata kuliah |
| Hari | ya | Senin–Sabtu (juga `Jum'at`, `jumat`, atau nama hari bahasa Inggris) |
| SKS | ya | 1–24 |
| Mulai, Selesai | ya | `07.00`, `7:00`, atau format waktu Excel. Bisa juga satu kolom **Jam** berisi `07.00 - 08.50` |
| Kode MK | | Dipakai untuk mencocokkan mata kuliah yang sudah ada |
| Kelas | | Default `-` |
| Prodi | | Mis. `IF`, `IUP`, atau `IF, RKA` |
| Jenis | | Mengandung "Wajib", "Pilihan", atau "Umum"/"SKPB"; default Pilihan |
| Semester | | Semester kurikulum, mis. `6` |
| Dosen | | Kode dosen, dipisah `,` `;` `/` atau `&` |
| Ruang, Kapasitas, Bahasa, Jadwal Ramadhan | | Opsional |

Impor ulang ke periode yang sama **memperbarui** kelas yang sudah ada (dicocokkan dari periode + mata kuliah + kelas + prodi), bukan menggandakannya. Opsi **"Ganti semua data lama"** hanya menghapus kelas dan KRS di periode yang dipilih. Baris yang tidak valid dilewati dan dilaporkan beserta nomor barisnya. Contoh file bisa diunduh dari halaman Import Jadwal.

## Cara kerja rencana otomatis

Pencarian ada di `app/Actions/Krs/GenerateScheduleCombinations.php`. Kelas kandidat diurutkan berdasarkan hari dan jam, lalu ditelusuri secara *depth-first branch-and-bound*: untuk tiap kelas diputuskan diambil atau dilewati.

- **Skor** – SKS terbanyak, ditambah bonus untuk mata kuliah prioritas dan dosen favorit, dikurangi penalti jumlah hari dan jeda antar kelas.
- **Pruning** – karena kelas ditelusuri berurutan waktu, penalti hari dan jeda hanya bisa bertambah. Batas atas skor jadi ketat, sehingga cabang yang tidak mungkin mengalahkan hasil terbaik langsung dipangkas.
- **Hasil** – satu kombinasi terbaik untuk setiap himpunan mata kuliah yang berbeda, sehingga tiap hasil benar-benar rencana yang berbeda.

Pada data contoh, seluruh ruang pencarian ditelusuri dalam kurang dari satu detik. Ada test yang membandingkan hasilnya dengan pencarian *brute-force*.

## Struktur kode

| Lokasi | Isi |
|---|---|
| `app/Actions/Krs/` | Logika inti: aturan KRS, pindah kelas, import jadwal, rencana otomatis |
| `app/Support/` | Pembaca `.xlsx` tanpa dependency tambahan, serta objek data untuk planner dan hasil import |
| `app/Models/`, `app/Enums/` | `AcademicPeriod`, `Course`, `CourseClass`, `Lecturer`, `User`; enum hari, semester, jenis MK, peran |
| `resources/views/pages/` | Halaman Livewire (*single-file component*, ditandai awalan ⚡) |
| `resources/views/components/schedule-grid.blade.php` | Grid jadwal mingguan |
| `lang/id*` | Terjemahan bahasa Indonesia |

Hak akses memakai dua gate di `AppServiceProvider`: `manage-academics` (admin) dan `take-courses` (mahasiswa). Pengguna yang mendaftar sendiri menjadi mahasiswa.

## Pengujian

```bash
php artisan test --compact     # seluruh test (Pest)
vendor/bin/pint --dirty        # format kode
vendor/bin/phpstan analyse     # analisis statis (level 7)
```
