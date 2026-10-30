<?php

use App\Enums\Day;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Lecturer;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Kelas & Jadwal')] class extends Component {
    use WithPagination;

    #[Url(as: 'periode')]
    public string $periodFilter = '';

    public string $search = '';

    public string $courseFilter = '';

    public string $dayFilter = '';

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $periodId = '';

    public string $courseId = '';

    public string $name = '';

    public string $prodi = '';

    public string $day = '1';

    public string $startTime = '07:00';

    public string $endTime = '08:50';

    public string $room = '';

    public string $capacity = '';

    public string $language = 'Indonesia';

    public string $ramadhanSchedule = '';

    public string $lecturerCodes = '';

    /**
     * Default the period filter to the active period, or the newest one when none is active.
     */
    public function mount(): void
    {
        if ($this->periods->firstWhere('id', (int) $this->periodFilter) === null) {
            $period = $this->periods->firstWhere('is_active', true) ?? $this->periods->first();
            $this->periodFilter = $period !== null ? (string) $period->id : '';
        }
    }

    /**
     * Reset pagination when a filter changes.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['periodFilter', 'search', 'courseFilter', 'dayFilter'], true)) {
            $this->resetPage();
        }
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
     * Get the period whose classes are listed.
     */
    #[Computed]
    public function selectedPeriod(): ?AcademicPeriod
    {
        return $this->periodFilter !== '' ? $this->periods->firstWhere('id', (int) $this->periodFilter) : null;
    }

    /**
     * Get the paginated, filtered classes.
     *
     * @return LengthAwarePaginator<int, CourseClass>
     */
    #[Computed]
    public function classes(): LengthAwarePaginator
    {
        return CourseClass::query()
            ->select('course_classes.*')
            ->join('courses', 'courses.id', '=', 'course_classes.course_id')
            ->where('course_classes.academic_period_id', $this->selectedPeriod?->id ?? 0)
            ->with(['course', 'lecturers'])
            ->withCount('students')
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('courses.name', 'like', "%{$this->search}%")
                ->orWhere('courses.code', 'like', "%{$this->search}%")
                ->orWhereHas('lecturers', fn ($query) => $query->where('code', 'like', "%{$this->search}%"))))
            ->when($this->courseFilter !== '', fn ($query) => $query->where('course_classes.course_id', $this->courseFilter))
            ->when($this->dayFilter !== '', fn ($query) => $query->where('course_classes.day', $this->dayFilter))
            ->orderBy('courses.name')
            ->orderBy('course_classes.name')
            ->orderBy('course_classes.prodi')
            ->paginate(15);
    }

    /**
     * Get every course for the select inputs.
     *
     * @return Collection<int, Course>
     */
    #[Computed]
    public function courses(): Collection
    {
        return Course::query()->orderBy('name')->get(['id', 'code', 'name']);
    }

    /**
     * Get the class being edited, with its enrollment count.
     */
    #[Computed]
    public function editingClass(): ?CourseClass
    {
        return $this->editingId !== null ? CourseClass::query()->withCount('students')->find($this->editingId) : null;
    }

    /**
     * Get the class pending deletion.
     */
    #[Computed]
    public function deletingClass(): ?CourseClass
    {
        return $this->deletingId !== null ? CourseClass::query()->with('course')->withCount('students')->find($this->deletingId) : null;
    }

    /**
     * Open the form for a new class.
     */
    public function create(): void
    {
        if ($this->periods->isEmpty()) {
            return;
        }

        $this->resetForm();
        $this->periodId = $this->periodFilter;

        if ($this->courseFilter !== '') {
            $this->courseId = $this->courseFilter;
        }

        Flux::modal('class-form')->show();
    }

    /**
     * Open the form for an existing class.
     */
    public function edit(int $classId): void
    {
        $class = CourseClass::query()->with('lecturers')->findOrFail($classId);

        $this->resetValidation();
        $this->editingId = $class->id;
        $this->periodId = (string) $class->academic_period_id;
        $this->courseId = (string) $class->course_id;
        $this->name = $class->name;
        $this->prodi = $class->prodi;
        $this->day = (string) $class->day->value;
        $this->startTime = $class->start_time;
        $this->endTime = $class->end_time;
        $this->room = (string) $class->room;
        $this->capacity = (string) $class->capacity;
        $this->language = $class->language;
        $this->ramadhanSchedule = (string) $class->ramadhan_schedule;
        $this->lecturerCodes = $class->lecturerCodes();
        unset($this->editingClass);

        Flux::modal('class-form')->show();
    }

    /**
     * Create or update the class and sync its lecturers.
     */
    public function save(): void
    {
        Gate::authorize('manage-academics');

        $this->name = trim($this->name);
        $this->prodi = trim($this->prodi);

        $validated = $this->validate([
            'periodId' => ['required', 'integer', Rule::exists('academic_periods', 'id')],
            'courseId' => ['required', 'integer', Rule::exists('courses', 'id')],
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('course_classes', 'name')
                    ->where('academic_period_id', (int) $this->periodId)
                    ->where('course_id', (int) $this->courseId)
                    ->where('prodi', $this->prodi)
                    ->ignore($this->editingId),
            ],
            'prodi' => ['nullable', 'string', 'max:100'],
            'day' => ['required', Rule::enum(Day::class)],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i', 'after:startTime'],
            'room' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'language' => ['required', Rule::in(['Indonesia', 'English'])],
            'ramadhanSchedule' => ['nullable', 'string', 'max:255'],
            'lecturerCodes' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => 'Kelas dengan nama dan prodi ini sudah ada untuk mata kuliah tersebut pada periode ini.',
            'endTime.after' => 'Jam selesai harus setelah jam mulai.',
        ], [
            'periodId' => 'periode akademik',
            'courseId' => 'mata kuliah',
            'name' => 'nama kelas',
            'day' => 'hari',
            'startTime' => 'jam mulai',
            'endTime' => 'jam selesai',
            'room' => 'ruang',
            'capacity' => 'kapasitas',
            'language' => 'bahasa',
            'lecturerCodes' => 'dosen',
        ]);

        DB::transaction(function () use ($validated): void {
            $attributes = [
                'academic_period_id' => (int) $validated['periodId'],
                'course_id' => (int) $validated['courseId'],
                'name' => $validated['name'],
                'prodi' => $this->prodi,
                'day' => (int) $validated['day'],
                'start_time' => $validated['startTime'],
                'end_time' => $validated['endTime'],
                'room' => filled($validated['room']) ? trim($validated['room']) : null,
                'capacity' => filled($validated['capacity']) ? (int) $validated['capacity'] : null,
                'language' => $validated['language'],
                'ramadhan_schedule' => filled($validated['ramadhanSchedule']) ? trim($validated['ramadhanSchedule']) : null,
            ];

            $class = $this->editingId !== null
                ? tap(CourseClass::query()->findOrFail($this->editingId))->update($attributes)
                : CourseClass::query()->create($attributes);

            $class->lecturers()->sync(Lecturer::idsFromCodes($validated['lecturerCodes']));
        });

        $message = $this->editingId !== null ? 'Kelas diperbarui.' : 'Kelas ditambahkan.';

        Flux::modal('class-form')->close();
        $this->resetForm();
        unset($this->classes);

        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Ask for confirmation before deleting a class.
     */
    public function confirmDelete(int $classId): void
    {
        $this->deletingId = $classId;
        unset($this->deletingClass);

        Flux::modal('class-delete')->show();
    }

    /**
     * Delete the class together with its enrollments.
     */
    public function delete(): void
    {
        Gate::authorize('manage-academics');

        CourseClass::query()->findOrFail($this->deletingId)->delete();

        $this->deletingId = null;
        unset($this->classes, $this->deletingClass);

        Flux::modal('class-delete')->close();
        Flux::toast(variant: 'success', text: 'Kelas dihapus.');
    }

    /**
     * Clear the form fields.
     */
    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'periodId', 'courseId', 'name', 'prodi', 'day', 'startTime', 'endTime', 'room', 'capacity', 'language', 'ramadhanSchedule', 'lecturerCodes');
        unset($this->editingClass);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Kelas & Jadwal</flux:heading>
            <flux:text class="mt-1">Kelola kelas paralel, jadwal, ruang, kapasitas, dan dosen pengampu.</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="create" :disabled="$this->periods->isEmpty()">Tambah Kelas</flux:button>
    </div>

    @if ($this->periods->isEmpty())
        <flux:callout variant="warning" icon="exclamation-triangle" heading="Belum ada periode akademik">
            <flux:callout.text>
                Kelas selalu dijadwalkan dalam satu periode akademik. Buat periode terlebih dahulu di halaman
                <flux:link :href="route('admin.periods')" wire:navigate>Periode Akademik</flux:link>.
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="periodFilter" aria-label="Filter periode" class="sm:max-w-56" data-test="period-filter">
            @foreach ($this->periods as $period)
                <flux:select.option wire:key="period-filter-{{ $period->id }}" value="{{ $period->id }}">{{ $period->label() }}{{ $period->is_active ? ' (aktif)' : '' }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" aria-label="Cari kelas" placeholder="Cari matkul, kode, atau dosen..." class="sm:max-w-xs" />

        <flux:select wire:model.live="courseFilter" aria-label="Filter mata kuliah" class="sm:max-w-64">
            <flux:select.option value="">Semua mata kuliah</flux:select.option>
            @foreach ($this->courses as $course)
                <flux:select.option wire:key="course-filter-{{ $course->id }}" value="{{ $course->id }}">{{ $course->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="dayFilter" aria-label="Filter hari" class="sm:max-w-40">
            <flux:select.option value="">Semua hari</flux:select.option>
            @foreach (\App\Enums\Day::cases() as $dayOption)
                <flux:select.option wire:key="day-filter-{{ $dayOption->value }}" value="{{ $dayOption->value }}">{{ $dayOption->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->classes">
        <flux:table.columns>
            <flux:table.column>Mata Kuliah</flux:table.column>
            <flux:table.column>Kelas</flux:table.column>
            <flux:table.column>Prodi</flux:table.column>
            <flux:table.column>Jadwal</flux:table.column>
            <flux:table.column>Ruang</flux:table.column>
            <flux:table.column>Dosen</flux:table.column>
            <flux:table.column>Terisi</flux:table.column>
            <flux:table.column>Bahasa</flux:table.column>
            <flux:table.column align="end">Aksi</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->classes as $class)
                <flux:table.row wire:key="class-{{ $class->id }}">
                    <flux:table.cell class="whitespace-normal">
                        <div class="font-medium text-zinc-800 dark:text-white">{{ $class->course->name }}</div>
                        <div class="mt-1 flex items-center gap-2">
                            @if ($class->course->code)
                                <span class="text-xs text-zinc-500">{{ $class->course->code }}</span>
                            @endif
                            <flux:badge size="sm" :color="$class->course->type->color()">{{ $class->course->type->label() }}</flux:badge>
                        </div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $class->label() }}</flux:table.cell>
                    <flux:table.cell>{{ $class->prodi ?: '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $class->scheduleLabel() }}</flux:table.cell>
                    <flux:table.cell class="whitespace-normal">{{ $class->room ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $class->lecturerCodes() ?: '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$class->isFull() ? 'red' : 'zinc'">
                            {{ $class->students_count }}/{{ $class->capacity ?? '∞' }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ $class->language }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $class->id }})" aria-label="Ubah" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $class->id }})" aria-label="Hapus" />
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="9" class="text-center text-zinc-500">Belum ada kelas{{ $this->selectedPeriod ? ' pada periode '.$this->selectedPeriod->label() : '' }}.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="class-form" class="w-full max-w-2xl">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Ubah Kelas' : 'Tambah Kelas' }}</flux:heading>

            @if ($this->editingClass && $this->editingClass->students_count > 0)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.text>
                        Kelas ini sudah diambil {{ $this->editingClass->students_count }} mahasiswa. Perubahan jadwal dapat membuat KRS mereka bentrok.
                    </flux:callout.text>
                </flux:callout>
            @endif

            <flux:select wire:model="periodId" label="Periode akademik" required>
                @foreach ($this->periods as $period)
                    <flux:select.option wire:key="period-option-{{ $period->id }}" value="{{ $period->id }}">{{ $period->label() }}{{ $period->is_active ? ' (aktif)' : '' }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="courseId" label="Mata kuliah" required>
                <flux:select.option value="">Pilih mata kuliah</flux:select.option>
                @foreach ($this->courses as $course)
                    <flux:select.option wire:key="course-option-{{ $course->id }}" value="{{ $course->id }}">{{ $course->name }}{{ $course->code ? ' ('.$course->code.')' : '' }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="name" label="Nama kelas" placeholder="A" required />
                <flux:input wire:model="prodi" label="Prodi" placeholder="IF" />
                <flux:select wire:model="language" label="Bahasa">
                    <flux:select.option value="Indonesia">Indonesia</flux:select.option>
                    <flux:select.option value="English">English</flux:select.option>
                </flux:select>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:select wire:model="day" label="Hari">
                    @foreach (\App\Enums\Day::cases() as $dayOption)
                        <flux:select.option wire:key="day-option-{{ $dayOption->value }}" value="{{ $dayOption->value }}">{{ $dayOption->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="startTime" label="Jam mulai" type="time" required />
                <flux:input wire:model="endTime" label="Jam selesai" type="time" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="room" label="Ruang" placeholder="IF-101" />
                <flux:input wire:model="capacity" label="Kapasitas" type="number" min="1" description="Kosongkan jika tidak dibatasi." />
            </div>

            <flux:input wire:model="lecturerCodes" label="Kode dosen" placeholder="BN, MA" description="Pisahkan dengan koma. Kode baru otomatis ditambahkan ke daftar dosen." />

            <flux:input wire:model="ramadhanSchedule" label="Jadwal Ramadhan" placeholder="Senin 07.00-08.50" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Simpan</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="class-delete" class="w-full max-w-md">
        <div class="space-y-5">
            <flux:heading size="lg">Hapus kelas?</flux:heading>
            @if ($this->deletingClass)
                <flux:text>
                    Kelas <strong>{{ $this->deletingClass->course->name }} ({{ $this->deletingClass->label() }})</strong> akan dihapus.
                    @if ($this->deletingClass->students_count > 0)
                        {{ $this->deletingClass->students_count }} mahasiswa yang mengambil kelas ini akan kehilangan kelas tersebut dari KRS-nya.
                    @endif
                </flux:text>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">Hapus</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
