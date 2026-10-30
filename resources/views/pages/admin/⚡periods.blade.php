<?php

use App\Enums\Term;
use App\Models\AcademicPeriod;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Periode Akademik')] class extends Component {
    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $startYear = '';

    public string $term = '1';

    public bool $makeActive = false;

    public string $yearStart = '';

    /** @var list<string> */
    public array $yearTerms = [];

    public string $activateTerm = '';

    /**
     * Get every period, newest first, with its class and enrollment counts.
     *
     * @return Collection<int, AcademicPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        return $this->periodQuery()->latestFirst()->get();
    }

    /**
     * Get the period pending deletion.
     */
    #[Computed]
    public function deletingPeriod(): ?AcademicPeriod
    {
        return $this->deletingId !== null ? $this->periodQuery()->find($this->deletingId) : null;
    }

    /**
     * Open the form for a new period.
     */
    public function create(): void
    {
        $this->resetForm();
        $this->startYear = (string) $this->nextStartYear();

        Flux::modal('period-form')->show();
    }

    /**
     * Open the form for a new academic year (both of its terms at once).
     */
    public function createYear(): void
    {
        $this->resetYearForm();
        $this->yearStart = (string) $this->nextStartYear();

        Flux::modal('year-form')->show();
    }

    /**
     * Create the chosen terms of a new academic year, skipping terms that already exist.
     */
    public function saveYear(): void
    {
        Gate::authorize('manage-academics');

        $this->yearStart = trim($this->yearStart);

        $validated = $this->validate([
            'yearStart' => ['required', 'integer', 'min:2000', 'max:2100'],
            'yearTerms' => ['required', 'array', 'min:1'],
            'yearTerms.*' => ['required', 'distinct', Rule::enum(Term::class)],
            'activateTerm' => ['nullable', Rule::in($this->yearTerms)],
        ], [
            'yearTerms.required' => 'Pilih minimal satu semester.',
            'activateTerm.in' => 'Semester yang diaktifkan harus termasuk semester yang dibuat.',
        ], [
            'yearStart' => 'tahun ajaran',
            'yearTerms' => 'semester',
        ]);

        $startYear = (int) $validated['yearStart'];
        $academicYear = $startYear.'/'.($startYear + 1);
        $terms = collect($validated['yearTerms'])->map(fn (string $term): Term => Term::from((int) $term))->sortBy('value')->values();

        $existingTerms = AcademicPeriod::query()
            ->where('start_year', $startYear)
            ->whereIn('term', $terms->map(fn (Term $term): int => $term->value))
            ->pluck('term')
            ->map(fn (Term $term): int => $term->value)
            ->all();

        $newTerms = $terms->reject(fn (Term $term): bool => in_array($term->value, $existingTerms, true));

        if ($newTerms->isEmpty()) {
            $this->addError('yearStart', "Tahun ajaran {$academicYear} sudah memiliki semester {$terms->map->label()->implode(' & ')}.");

            return;
        }

        DB::transaction(function () use ($startYear, $newTerms, $validated): void {
            foreach ($newTerms as $term) {
                AcademicPeriod::query()->create(['start_year' => $startYear, 'term' => $term]);
            }

            if (filled($validated['activateTerm'])) {
                AcademicPeriod::query()
                    ->where(['start_year' => $startYear, 'term' => (int) $validated['activateTerm']])
                    ->sole()
                    ->activate();
            }
        });

        $message = "Tahun ajaran {$academicYear} ditambahkan: semester {$newTerms->map->label()->implode(' & ')}.";

        if (count($existingTerms) > 0) {
            $message .= ' Semester '.collect($existingTerms)->map(fn (int $term): string => Term::from($term)->label())->implode(' & ').' sudah ada sebelumnya.';
        }

        Flux::modal('year-form')->close();
        $this->resetYearForm();
        unset($this->periods);

        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Open the form for an existing period.
     */
    public function edit(int $periodId): void
    {
        $period = AcademicPeriod::query()->findOrFail($periodId);

        $this->resetValidation();
        $this->editingId = $period->id;
        $this->startYear = (string) $period->start_year;
        $this->term = (string) $period->term->value;
        $this->makeActive = false;

        Flux::modal('period-form')->show();
    }

    /**
     * Create or update the period.
     */
    public function save(): void
    {
        Gate::authorize('manage-academics');

        $this->startYear = trim($this->startYear);

        $termLabel = Term::tryFrom((int) $this->term)?->label() ?? '';
        $yearLabel = is_numeric($this->startYear) ? ((int) $this->startYear).'/'.((int) $this->startYear + 1) : $this->startYear;

        $validated = $this->validate([
            'startYear' => [
                'required', 'integer', 'min:2000', 'max:2100',
                Rule::unique('academic_periods', 'start_year')
                    ->where('term', (int) $this->term)
                    ->ignore($this->editingId),
            ],
            'term' => ['required', Rule::enum(Term::class)],
            'makeActive' => ['boolean'],
        ], [
            'startYear.unique' => "Periode {$yearLabel} {$termLabel} sudah ada.",
        ], [
            'startYear' => 'tahun ajaran',
            'term' => 'semester',
        ]);

        $attributes = [
            'start_year' => (int) $validated['startYear'],
            'term' => (int) $validated['term'],
        ];

        if ($this->editingId !== null) {
            $period = AcademicPeriod::query()->findOrFail($this->editingId);
            $period->update($attributes);
            $message = "Periode {$period->label()} diperbarui.";
        } else {
            $period = AcademicPeriod::query()->create($attributes);

            if ($validated['makeActive']) {
                $period->activate();
            }

            $message = "Periode {$period->label()} ditambahkan.";
        }

        Flux::modal('period-form')->close();
        $this->resetForm();
        unset($this->periods);

        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Make a period the only active one.
     */
    public function activate(int $periodId): void
    {
        Gate::authorize('manage-academics');

        $period = AcademicPeriod::query()->findOrFail($periodId);
        $period->activate();

        unset($this->periods);

        Flux::toast(variant: 'success', text: "Periode {$period->label()} sekarang aktif.");
    }

    /**
     * Ask for confirmation before deleting a period.
     */
    public function confirmDelete(int $periodId): void
    {
        $this->deletingId = $periodId;
        unset($this->deletingPeriod);

        Flux::modal('period-delete')->show();
    }

    /**
     * Delete the period together with its classes and their enrollments.
     */
    public function delete(): void
    {
        Gate::authorize('manage-academics');

        $period = AcademicPeriod::query()->findOrFail($this->deletingId);

        if ($period->is_active) {
            Flux::modal('period-delete')->close();
            Flux::toast(variant: 'danger', text: 'Periode aktif tidak bisa dihapus. Aktifkan periode lain terlebih dahulu.');

            return;
        }

        $label = $period->label();
        $period->delete();

        $this->deletingId = null;
        unset($this->periods, $this->deletingPeriod);

        Flux::modal('period-delete')->close();
        Flux::toast(variant: 'success', text: "Periode {$label} dihapus.");
    }

    /**
     * Base query with the class and KRS enrollment counts of each period.
     *
     * @return \Illuminate\Database\Eloquent\Builder<AcademicPeriod>
     */
    private function periodQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return AcademicPeriod::query()
            ->withCount('classes')
            ->addSelect(['enrollments_count' => DB::table('enrollments')
                ->join('course_classes', 'course_classes.id', '=', 'enrollments.course_class_id')
                ->whereColumn('course_classes.academic_period_id', 'academic_periods.id')
                ->selectRaw('count(*)')]);
    }

    /**
     * Clear the form fields.
     */
    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'startYear', 'term', 'makeActive');
    }

    /**
     * Reset the academic year form: both terms selected, nothing activated.
     */
    private function resetYearForm(): void
    {
        $this->resetValidation();
        $this->reset('yearStart', 'activateTerm');
        $this->yearTerms = array_map(fn (Term $term): string => (string) $term->value, Term::cases());
    }

    /**
     * Suggest the academic year after the newest existing one (or the current year when there is none).
     */
    private function nextStartYear(): int
    {
        $newest = AcademicPeriod::query()->max('start_year');

        return $newest !== null ? (int) $newest + 1 : (int) now()->format('Y');
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Periode Akademik</flux:heading>
            <flux:text class="mt-1">Kelola tahun ajaran dan semester (ganjil/genap). Jadwal kelas dan KRS mahasiswa dikelompokkan per periode.</flux:text>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="plus" wire:click="create">Tambah Periode</flux:button>
            <flux:button variant="primary" icon="calendar" wire:click="createYear" data-test="create-year-button">Tambah Tahun Ajaran</flux:button>
        </div>
    </div>

    @if ($this->periods->isEmpty())
        <flux:card class="space-y-2 text-center">
            <flux:heading>Belum ada periode akademik</flux:heading>
            <flux:text>Tambahkan tahun ajaran (mis. 2025/2026, semester Ganjil &amp; Genap) sebelum mengimpor jadwal atau membuka pengisian KRS.</flux:text>
            <div class="pt-2">
                <flux:button variant="primary" icon="calendar" wire:click="createYear">Tambah Tahun Ajaran</flux:button>
            </div>
        </flux:card>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Tahun Ajaran</flux:table.column>
                <flux:table.column>Semester</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Jumlah Kelas</flux:table.column>
                <flux:table.column>Pengambilan KRS</flux:table.column>
                <flux:table.column align="end">Aksi</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->periods as $period)
                    <flux:table.row wire:key="period-{{ $period->id }}">
                        <flux:table.cell variant="strong">{{ $period->academicYear() }}</flux:table.cell>
                        <flux:table.cell>{{ $period->term->label() }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($period->is_active)
                                <flux:badge size="sm" color="green">Aktif</flux:badge>
                            @else
                                <span class="text-zinc-500 dark:text-zinc-400">Tidak aktif</span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $period->classes_count }}</flux:table.cell>
                        <flux:table.cell>{{ (int) $period->enrollments_count }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-1">
                                @unless ($period->is_active)
                                    <flux:button size="sm" variant="ghost" icon="check-circle" wire:click="activate({{ $period->id }})">Aktifkan</flux:button>
                                @endunless
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $period->id }})" aria-label="Ubah" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $period->id }})" aria-label="Hapus" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="period-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Ubah Periode' : 'Tambah Periode' }}</flux:heading>

            <flux:input
                wire:model.live="startYear"
                label="Tahun mulai"
                type="number"
                min="2000"
                max="2100"
                required
                :description="is_numeric($startYear) ? 'Tahun ajaran '.((int) $startYear).'/'.((int) $startYear + 1) : 'Contoh: 2025 untuk tahun ajaran 2025/2026.'"
            />

            <flux:select wire:model="term" label="Semester">
                @foreach (\App\Enums\Term::cases() as $termOption)
                    <flux:select.option wire:key="term-option-{{ $termOption->value }}" value="{{ $termOption->value }}">{{ $termOption->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @unless ($editingId)
                <flux:checkbox wire:model="makeActive" label="Jadikan periode aktif" description="Periode aktif adalah periode yang dibuka untuk pengisian KRS." />
            @endunless

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Simpan</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="year-form" class="w-full max-w-md">
        <form wire:submit="saveYear" class="space-y-5">
            <div>
                <flux:heading size="lg">Tambah Tahun Ajaran</flux:heading>
                <flux:text class="mt-1">Semester yang dipilih dibuat sekaligus. Semester yang sudah ada akan dilewati.</flux:text>
            </div>

            <flux:input
                wire:model.live="yearStart"
                label="Tahun mulai"
                type="number"
                min="2000"
                max="2100"
                required
                :description="is_numeric($yearStart) ? 'Tahun ajaran '.((int) $yearStart).'/'.((int) $yearStart + 1) : 'Contoh: 2026 untuk tahun ajaran 2026/2027.'"
            />

            <flux:checkbox.group wire:model.live="yearTerms" label="Semester yang dibuat">
                @foreach (\App\Enums\Term::cases() as $termOption)
                    <flux:checkbox wire:key="year-term-{{ $termOption->value }}" value="{{ $termOption->value }}" :label="$termOption->label()" />
                @endforeach
            </flux:checkbox.group>

            <flux:select wire:model="activateTerm" label="Aktifkan semester" description="Periode aktif adalah periode yang dibuka untuk pengisian KRS.">
                <flux:select.option value="">Jangan aktifkan sekarang</flux:select.option>
                @foreach (\App\Enums\Term::cases() as $termOption)
                    @if (in_array((string) $termOption->value, $yearTerms, true))
                        <flux:select.option wire:key="activate-term-{{ $termOption->value }}" value="{{ $termOption->value }}">{{ $termOption->label() }}</flux:select.option>
                    @endif
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Simpan</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="period-delete" class="w-full max-w-md">
        <div class="space-y-5">
            <flux:heading size="lg">Hapus periode?</flux:heading>
            @if ($this->deletingPeriod)
                @if ($this->deletingPeriod->is_active)
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.text>Periode aktif tidak bisa dihapus. Aktifkan periode lain terlebih dahulu.</flux:callout.text>
                    </flux:callout>
                @else
                    <flux:text>
                        Periode <strong>{{ $this->deletingPeriod->label() }}</strong> akan dihapus beserta
                        {{ $this->deletingPeriod->classes_count }} kelas dan {{ (int) $this->deletingPeriod->enrollments_count }} pengambilan KRS mahasiswa di dalamnya.
                        Tindakan ini tidak dapat dibatalkan.
                    </flux:text>
                @endif
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                @if ($this->deletingPeriod && ! $this->deletingPeriod->is_active)
                    <flux:button variant="danger" wire:click="delete">Hapus</flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</section>
