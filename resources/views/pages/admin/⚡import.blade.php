<?php

use App\Actions\Krs\ImportScheduleSpreadsheet;
use App\Exceptions\ScheduleImportException;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Lecturer;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Import Jadwal')] class extends Component {
    use WithFileUploads;

    /** @var UploadedFile|null */
    public $file = null;

    public bool $replaceExisting = false;

    public string $periodId = '';

    /**
     * Summary of the last successful import.
     *
     * @var array{courses_created: int, classes_created: int, classes_updated: int, rows_skipped: int, errors: list<string>}|null
     */
    public ?array $result = null;

    /**
     * Preselect the active period, or the newest one when none is active.
     */
    public function mount(): void
    {
        $period = AcademicPeriod::current() ?? AcademicPeriod::query()->latestFirst()->first();
        $this->periodId = $period !== null ? (string) $period->id : '';
    }

    /**
     * Import the uploaded schedule spreadsheet into the selected academic period.
     */
    public function import(ImportScheduleSpreadsheet $importSchedule): void
    {
        Gate::authorize('manage-academics');

        $this->validate([
            'periodId' => ['required', 'integer', 'exists:academic_periods,id'],
            'file' => ['required', 'file', 'extensions:xlsx,csv', 'max:10240'],
        ], [
            'periodId.required' => 'Pilih periode akademik tujuan.',
            'periodId.exists' => 'Periode akademik tidak ditemukan.',
            'file.required' => 'Pilih file jadwal terlebih dahulu.',
            'file.extensions' => 'File harus berformat .xlsx atau .csv.',
            'file.max' => 'Ukuran file maksimal 10 MB.',
        ]);

        $period = AcademicPeriod::query()->findOrFail((int) $this->periodId);

        try {
            $result = $importSchedule->handle(
                $this->file->getRealPath(),
                $period,
                $this->replaceExisting,
                $this->file->getClientOriginalExtension(),
            );
        } catch (ScheduleImportException $exception) {
            $this->addError('file', $exception->getMessage());

            return;
        }

        $this->result = [
            'courses_created' => $result->coursesCreated,
            'classes_created' => $result->classesCreated,
            'classes_updated' => $result->classesUpdated,
            'rows_skipped' => $result->rowsSkipped,
            'errors' => $result->errors,
        ];

        $this->reset('file', 'replaceExisting');
        unset($this->totals);

        Flux::toast(variant: 'success', text: "Import selesai: {$result->classesImported()} kelas diproses ke periode {$period->label()}.");
    }

    /**
     * Download the sample schedule spreadsheet.
     */
    public function downloadSample(): BinaryFileResponse
    {
        Gate::authorize('manage-academics');

        return response()->download(database_path('seeders/data/jadwal-if-genap-2025-2026.xlsx'), 'contoh-jadwal.xlsx');
    }

    /**
     * Reset the cached totals when another period is selected.
     */
    public function updatedPeriodId(): void
    {
        unset($this->totals, $this->selectedPeriod);
    }

    /**
     * Get every academic period, newest first.
     *
     * @return Collection<int, AcademicPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        return AcademicPeriod::query()->latestFirst()->get();
    }

    /**
     * Get the period the file will be imported into.
     */
    #[Computed]
    public function selectedPeriod(): ?AcademicPeriod
    {
        return $this->periodId !== '' ? $this->periods->firstWhere('id', (int) $this->periodId) : null;
    }

    /**
     * Current number of records: classes of the selected period plus the global course and lecturer catalogues.
     *
     * @return array{courses: int, classes: int, lecturers: int}
     */
    #[Computed]
    public function totals(): array
    {
        return [
            'courses' => Course::query()->count(),
            'classes' => $this->selectedPeriod !== null ? CourseClass::query()->inPeriod($this->selectedPeriod)->count() : 0,
            'lecturers' => Lecturer::query()->count(),
        ];
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Import Jadwal</flux:heading>
            <flux:text class="mt-1">Unggah file jadwal kuliah (.xlsx atau .csv) untuk mengisi mata kuliah, kelas, dan dosen secara otomatis.</flux:text>
        </div>

        <flux:button icon="arrow-down-tray" wire:click="downloadSample">Unduh contoh file</flux:button>
    </div>

    @if ($this->periods->isEmpty())
        <flux:callout variant="warning" icon="exclamation-triangle" heading="Belum ada periode akademik">
            <flux:callout.text>
                Jadwal diimpor ke dalam periode akademik tertentu. Buat periode terlebih dahulu di halaman
                <flux:link :href="route('admin.periods')" wire:navigate>Periode Akademik</flux:link>.
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ([
            'courses' => 'Mata kuliah (semua periode)',
            'classes' => 'Kelas '.($this->selectedPeriod?->label() ?? 'periode terpilih'),
            'lecturers' => 'Dosen',
        ] as $key => $label)
            <flux:card wire:key="total-{{ $key }}" class="space-y-1">
                <flux:text>{{ $label }}</flux:text>
                <flux:heading size="xl">{{ $this->totals[$key] }}</flux:heading>
            </flux:card>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-5">
        <flux:card class="space-y-6 lg:col-span-3">
            <flux:heading size="lg">Unggah file</flux:heading>

            <form wire:submit="import" class="space-y-6">
                <flux:select wire:model.live="periodId" label="Periode akademik tujuan" :disabled="$this->periods->isEmpty()" data-test="period-select">
                    @if ($this->periods->isEmpty())
                        <flux:select.option value="">Belum ada periode</flux:select.option>
                    @endif
                    @foreach ($this->periods as $period)
                        <flux:select.option wire:key="period-option-{{ $period->id }}" value="{{ $period->id }}">{{ $period->label() }}{{ $period->is_active ? ' (aktif)' : '' }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:field>
                    <flux:label>File jadwal</flux:label>
                    <input
                        type="file"
                        wire:model="file"
                        accept=".xlsx,.csv"
                        data-test="schedule-file-input"
                        class="block w-full cursor-pointer rounded-lg border border-zinc-200 bg-white text-sm text-zinc-700 file:me-4 file:cursor-pointer file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium hover:file:bg-zinc-200 dark:border-white/10 dark:bg-white/10 dark:text-zinc-200 dark:file:bg-white/10 dark:file:text-zinc-200"
                    />
                    <div wire:loading wire:target="file" class="text-sm text-zinc-500">Mengunggah file…</div>
                    <flux:error name="file" />
                </flux:field>

                <div class="space-y-2">
                    <flux:checkbox wire:model.live="replaceExisting" label="Ganti jadwal lama pada periode ini" />

                    @if ($replaceExisting)
                        <flux:callout variant="danger" icon="exclamation-triangle" heading="Jadwal lama periode ini akan dihapus">
                            <flux:callout.text>
                                Seluruh kelas dan KRS mahasiswa pada periode <strong>{{ $this->selectedPeriod?->label() ?? 'yang dipilih' }}</strong> akan dihapus sebelum file diimpor.
                                Periode lain dan daftar mata kuliah tidak terpengaruh. Tindakan ini tidak dapat dibatalkan.
                            </flux:callout.text>
                        </flux:callout>
                    @endif
                </div>

                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="file,import" :disabled="$this->periods->isEmpty()" data-test="import-schedule-button">
                        Import
                    </flux:button>
                </div>
            </form>
        </flux:card>

        <flux:card class="space-y-3 lg:col-span-2">
            <flux:heading size="lg">Format file</flux:heading>
            <flux:text>
                Baris header dideteksi otomatis, jadi judul di atas tabel dan ringkasan di bawahnya akan diabaikan. Kolom yang dikenali:
            </flux:text>
            <div class="flex flex-wrap gap-1.5">
                @foreach (['No', 'Semester', 'Kode MK', 'Mata Kuliah', 'Kelas', 'Prodi', 'Jenis', 'SKS', 'Dosen (kode)', 'Hari', 'Mulai', 'Selesai', 'Ruang', 'Kapasitas', 'Bahasa', 'Jadwal Ramadhan'] as $column)
                    <flux:badge wire:key="column-{{ $loop->index }}" size="sm" :color="in_array($column, ['Mata Kuliah', 'Hari', 'SKS', 'Mulai', 'Selesai'], true) ? 'red' : 'zinc'">
                        {{ $column }}
                    </flux:badge>
                @endforeach
            </div>
            <flux:text class="text-sm">
                Kolom merah wajib ada: <strong>Mata Kuliah</strong>, <strong>Hari</strong>, <strong>SKS</strong>, dan <strong>Mulai</strong>/<strong>Selesai</strong>.
                Jam boleh ditulis <code>07.00</code> atau <code>07:00</code>; dosen dipisah koma (mis. <code>BN, MA</code>).
            </flux:text>
            <flux:text class="text-sm">
                Kelas disimpan ke periode yang dipilih. Import ulang ke periode yang sama akan memperbarui kelas yang sudah ada (dicocokkan berdasarkan mata kuliah + kelas + prodi), bukan membuat duplikat.
                Mata kuliah dipakai bersama oleh semua periode.
            </flux:text>
        </flux:card>
    </div>

    @if ($result)
        <flux:card class="space-y-4" data-test="import-result">
            <flux:heading size="lg">Hasil import terakhir</flux:heading>

            <div class="grid gap-4 sm:grid-cols-4">
                @foreach ([
                    'classes_created' => 'Kelas baru',
                    'classes_updated' => 'Kelas diperbarui',
                    'courses_created' => 'Mata kuliah baru',
                    'rows_skipped' => 'Baris dilewati',
                ] as $key => $label)
                    <div wire:key="result-{{ $key }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:text class="text-sm">{{ $label }}</flux:text>
                        <flux:heading size="lg">{{ $result[$key] }}</flux:heading>
                    </div>
                @endforeach
            </div>

            @if ($result['errors'] !== [])
                <flux:callout variant="warning" icon="exclamation-triangle" heading="Beberapa baris tidak diimpor">
                    <flux:callout.text>
                        <ul class="list-disc space-y-1 ps-5">
                            @foreach ($result['errors'] as $error)
                                <li wire:key="import-error-{{ $loop->index }}">{{ $error }}</li>
                            @endforeach
                        </ul>
                    </flux:callout.text>
                </flux:callout>
            @endif
        </flux:card>
    @endif
</section>
