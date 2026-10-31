<?php

use App\Actions\Krs\EnrollStudentInClass;
use App\Exceptions\EnrollmentException;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Isi KRS')] class extends Component {
    #[Url(as: 'periode')]
    public ?int $periodId = null;

    public string $search = '';

    public string $type = '';

    public string $semester = '';

    public string $day = '';

    public bool $hideUnavailable = false;

    public int $maxSks = 24;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->maxSks = $this->student->max_sks;

        if ($this->period === null) {
            $this->periodId = ($this->activePeriod ?? $this->periods->first())?->id;
            unset($this->period);
        }
    }

    /**
     * Reset the cached period data when another period is selected.
     */
    public function updatedPeriodId(): void
    {
        $this->refreshKrs();
    }

    /**
     * Persist a new SKS limit chosen by the student, as long as it still covers the SKS already taken in the active period.
     */
    public function updatedMaxSks(): void
    {
        Gate::authorize('take-courses');

        $student = $this->student;
        $takenSks = $this->activePeriod !== null ? $student->takenSks($this->activePeriod) : 0;

        $this->validate([
            'maxSks' => ['required', 'integer', Rule::in(User::SKS_LIMITS)],
        ], [
            'maxSks.in' => 'Batas SKS harus salah satu dari '.implode('/', User::SKS_LIMITS).'.',
        ]);

        if ($this->maxSks < $takenSks) {
            $requested = $this->maxSks;
            $this->maxSks = $student->max_sks;
            $this->addError('maxSks', "Batas {$requested} SKS lebih kecil dari SKS yang sudah diambil ({$takenSks}). Lepas beberapa kelas terlebih dahulu.");
            Flux::toast(variant: 'danger', text: "Batas SKS tidak bisa diturunkan ke {$requested}.");

            return;
        }

        $student->update(['max_sks' => $this->maxSks]);
        $this->refreshKrs();

        Flux::toast(variant: 'success', text: "Batas SKS diubah menjadi {$this->maxSks}.");
    }

    /**
     * Take a class, or move to it when another class of the same course is already taken.
     */
    public function take(int $classId, EnrollStudentInClass $enrollStudent): void
    {
        Gate::authorize('take-courses');

        $class = CourseClass::query()->with(['course', 'academicPeriod'])->findOrFail($classId);

        try {
            $replaced = $enrollStudent->handle($this->student, $class);
        } catch (EnrollmentException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->refreshKrs();

        Flux::toast(
            variant: 'success',
            text: $replaced !== null
                ? "Pindah {$class->course->name} dari kelas {$replaced->label()} ke kelas {$class->label()}."
                : "Berhasil mengambil {$class->course->name} kelas {$class->label()}.",
        );
    }

    /**
     * Remove a class of the active period from the student's KRS.
     */
    public function drop(int $classId): void
    {
        Gate::authorize('take-courses');

        $period = $this->editablePeriod();

        if ($period === null) {
            return;
        }

        $class = $this->student->classesInPeriod($period)->with('course')->find($classId);

        if ($class === null) {
            return;
        }

        $this->student->courseClasses()->detach($class->id);
        $this->refreshKrs();

        Flux::toast(text: "{$class->course->name} kelas {$class->label()} dilepas.");
    }

    /**
     * Remove every class of the active period from the student's KRS, leaving other periods untouched.
     */
    public function clearAll(): void
    {
        Gate::authorize('take-courses');

        Flux::modal('confirm-clear-krs')->close();

        $period = $this->editablePeriod();

        if ($period === null) {
            return;
        }

        $classIds = $this->student->classesInPeriod($period)->pluck('course_classes.id')->all();
        $this->student->courseClasses()->detach($classIds);
        $this->refreshKrs();

        Flux::toast(text: "Semua kelas di KRS periode {$period->label()} sudah dilepas.");
    }

    /**
     * Get the viewed period when its KRS may be changed (it is the active one), telling the student otherwise.
     */
    private function editablePeriod(): ?AcademicPeriod
    {
        $period = $this->period;

        if ($period?->is_active === true) {
            return $period;
        }

        Flux::toast(
            variant: 'danger',
            text: $period !== null
                ? "KRS periode {$period->label()} hanya bisa dilihat (bukan periode aktif)."
                : 'Belum ada periode akademik.',
        );

        return null;
    }

    /**
     * Forget cached KRS data after a change.
     */
    private function refreshKrs(): void
    {
        unset($this->period, $this->isEditable, $this->myClasses, $this->takenSks, $this->rows, $this->semesters);
    }

    /**
     * The authenticated student.
     */
    #[Computed]
    public function student(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * Every academic period, newest first.
     *
     * @return Collection<int, AcademicPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        return AcademicPeriod::query()->latestFirst()->get();
    }

    /**
     * The currently active academic period, if any.
     */
    #[Computed]
    public function activePeriod(): ?AcademicPeriod
    {
        return $this->periods->firstWhere('is_active', true);
    }

    /**
     * The academic period being viewed.
     */
    #[Computed]
    public function period(): ?AcademicPeriod
    {
        return $this->periodId === null ? null : $this->periods->firstWhere('id', $this->periodId);
    }

    /**
     * Whether the KRS of the viewed period can still be changed.
     */
    #[Computed]
    public function isEditable(): bool
    {
        return $this->period?->is_active === true;
    }

    /**
     * Classes in the student's KRS for the viewed period, in weekly order.
     *
     * @return Collection<int, CourseClass>
     */
    #[Computed]
    public function myClasses(): Collection
    {
        if ($this->period === null) {
            return new Collection;
        }

        return $this->student->classesInPeriod($this->period)
            ->with(['course', 'lecturers'])
            ->withCount('students')
            ->orderBy('day')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Total SKS taken in the viewed period.
     */
    #[Computed]
    public function takenSks(): int
    {
        return $this->myClasses->sum('sks');
    }

    /**
     * Semesters of the courses offered in the viewed period, for the filter.
     *
     * @return list<int>
     */
    #[Computed]
    public function semesters(): array
    {
        $period = $this->period;

        if ($period === null) {
            return [];
        }

        return Course::query()
            ->whereNotNull('semester')
            ->whereHas('classes', fn (Builder $class) => $class->inPeriod($period))
            ->distinct()
            ->orderBy('semester')
            ->pluck('semester')
            ->map(fn (mixed $semester): int => (int) $semester)
            ->values()
            ->all();
    }

    /**
     * Filtered classes of the viewed period, each paired with what the student can do with it.
     *
     * @return list<array{class: CourseClass, state: string, reason: string|null, tone: string|null, detail: string|null}>
     */
    #[Computed]
    public function rows(): array
    {
        if ($this->period === null) {
            return [];
        }

        $search = trim($this->search);

        $classes = CourseClass::query()
            ->inPeriod($this->period)
            ->with(['course', 'lecturers'])
            ->withCount('students')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereHas('course', fn (Builder $course) => $course->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                ->orWhereHas('lecturers', fn (Builder $lecturer) => $lecturer->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))))
            ->when($this->type !== '', fn (Builder $query) => $query->whereHas('course', fn (Builder $course) => $course->where('type', $this->type)))
            ->when($this->semester !== '', fn (Builder $query) => $query->whereHas('course', fn (Builder $course) => $course->where('semester', (int) $this->semester)))
            ->when($this->day !== '', fn (Builder $query) => $query->where('day', (int) $this->day))
            ->get()
            ->sortBy(fn (CourseClass $class): string => $class->course->name.'|'.$class->label())
            ->values();

        $rows = [];

        foreach ($classes as $class) {
            [$state, $reason, $tone, $detail] = $this->availability($class);

            if ($this->hideUnavailable && in_array($state, ['blocked', 'readonly'], true)) {
                continue;
            }

            $rows[] = ['class' => $class, 'state' => $state, 'reason' => $reason, 'tone' => $tone, 'detail' => $detail];
        }

        return $rows;
    }

    /**
     * Determine whether the student can take a class and, if not, why: [state, reason, badge color, extra detail].
     *
     * @return array{0: 'taken'|'take'|'switch'|'blocked'|'readonly', 1: string|null, 2: string|null, 3: string|null}
     */
    private function availability(CourseClass $class): array
    {
        if ($this->myClasses->contains('id', $class->id)) {
            return ['taken', null, null, null];
        }

        if (! $this->isEditable) {
            return ['readonly', null, null, null];
        }

        $sameCourse = $this->myClasses->firstWhere('course_id', $class->course_id);
        $clash = $this->myClasses
            ->reject(fn (CourseClass $taken): bool => $taken->course_id === $class->course_id)
            ->first(fn (CourseClass $taken): bool => $taken->overlaps($class));

        if ($clash !== null) {
            return ['blocked', 'Bentrok', 'amber', "dengan {$clash->course->name} ({$clash->label()})"];
        }

        if ($class->isFull()) {
            return ['blocked', 'Penuh', 'red', null];
        }

        $sksAfter = $this->takenSks - ($sameCourse?->sks ?? 0) + $class->sks;

        if ($sksAfter > $this->student->max_sks) {
            return ['blocked', 'Melebihi batas SKS', 'orange', null];
        }

        return [$sameCourse !== null ? 'switch' : 'take', null, null, null];
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Isi KRS</flux:heading>
            <flux:text class="mt-1">
                @if ($this->period)
                    Periode {{ $this->period->label() }}.
                @endif
                Pilih kelas mata kuliah sesuai batas SKS kamu. Jadwal yang bentrok otomatis ditandai.
            </flux:text>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($this->periods->isNotEmpty())
                <flux:select wire:model.live="periodId" class="min-w-56" data-test="period-select" aria-label="Periode akademik">
                    @foreach ($this->periods as $periodOption)
                        <flux:select.option wire:key="period-{{ $periodOption->id }}" :value="$periodOption->id">
                            {{ $periodOption->label() }}{{ $periodOption->is_active ? ' (aktif)' : '' }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:button variant="primary" icon="sparkles" :href="route('krs.planner')" wire:navigate>
                Rencana Otomatis
            </flux:button>
        </div>
    </div>

    @if ($this->periods->isEmpty())
        <flux:callout variant="warning" icon="exclamation-triangle" heading="Belum ada periode akademik. Hubungi admin." />
    @else
    @if (! $this->isEditable && $this->period)
        <flux:callout icon="lock-closed" heading="KRS periode {{ $this->period->label() }} hanya bisa dilihat (bukan periode aktif)" />
    @endif

    @php
        $percentage = $this->student->max_sks > 0 ? min(100, ($this->takenSks / $this->student->max_sks) * 100) : 0;
    @endphp

    <div class="grid gap-4 md:grid-cols-3">
        <flux:card class="space-y-3 md:col-span-2">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <flux:text>SKS diambil</flux:text>
                    <flux:heading size="xl" data-test="sks-meter">{{ $this->takenSks }} / {{ $this->student->max_sks }} SKS</flux:heading>
                </div>
                <flux:text>{{ $this->myClasses->count() }} mata kuliah</flux:text>
            </div>
            <div
                class="h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10"
                role="progressbar"
                aria-label="SKS diambil"
                aria-valuemin="0"
                aria-valuemax="{{ $this->student->max_sks }}"
                aria-valuenow="{{ $this->takenSks }}"
                aria-valuetext="{{ $this->takenSks }} dari {{ $this->student->max_sks }} SKS"
            >
                <div
                    class="h-full rounded-full transition-[width] duration-300 {{ $this->takenSks >= $this->student->max_sks ? 'bg-emerald-500' : 'bg-sky-500' }}"
                    style="width: {{ $percentage }}%"
                ></div>
            </div>
            <flux:text class="text-sm">Sisa {{ max(0, $this->student->max_sks - $this->takenSks) }} SKS lagi.</flux:text>
        </flux:card>

        <flux:card class="space-y-2">
            <flux:select wire:model.live="maxSks" label="Batas SKS (simulasi)" data-test="max-sks-select">
                @foreach (\App\Models\User::SKS_LIMITS as $limit)
                    <flux:select.option wire:key="limit-{{ $limit }}" :value="$limit">{{ $limit }} SKS</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text class="text-xs">Batas SKS biasanya ditentukan dari IPS semester sebelumnya.</flux:text>
        </flux:card>
    </div>

    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg">KRS Saya</flux:heading>

            @if ($this->isEditable && $this->myClasses->isNotEmpty())
                <flux:modal.trigger name="confirm-clear-krs">
                    <flux:button size="sm" variant="ghost" icon="trash">Lepas semua</flux:button>
                </flux:modal.trigger>
            @endif
        </div>

        <x-schedule-grid :classes="$this->myClasses" :text-alternative="false" empty-text="Belum ada kelas yang diambil. Pilih kelas dari daftar di bawah." />

        @if ($this->myClasses->isNotEmpty())
            <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->myClasses as $taken)
                    <div wire:key="my-class-{{ $taken->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-zinc-800 dark:text-white">{{ $taken->course->name }}</span>
                                <flux:badge size="sm" :color="$taken->course->type->color()">{{ $taken->course->type->label() }}</flux:badge>
                            </div>
                            <flux:text class="text-sm">
                                Kelas {{ $taken->label() }} · {{ $taken->scheduleLabel() }} · {{ $taken->sks }} SKS
                                @if ($taken->room)
                                    · {{ $taken->room }}
                                @endif
                            </flux:text>
                        </div>
                        @if ($this->isEditable)
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="drop({{ $taken->id }})">Lepas</flux:button>
                        @endif
                    </div>
                @endforeach
                <div class="flex justify-between px-4 py-2 text-sm font-semibold text-zinc-800 dark:text-white">
                    <span>Total</span>
                    <span>{{ $this->takenSks }} SKS</span>
                </div>
            </div>
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">Daftar Kelas</flux:heading>

        <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" aria-label="Cari kelas" placeholder="Cari mata kuliah, kode, atau dosen…" />
            </div>
            <flux:select wire:model.live="type" aria-label="Filter jenis mata kuliah">
                <flux:select.option value="">Semua jenis</flux:select.option>
                @foreach (\App\Enums\CourseType::cases() as $courseType)
                    <flux:select.option wire:key="type-{{ $courseType->value }}" :value="$courseType->value">{{ $courseType->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="semester" aria-label="Filter semester">
                <flux:select.option value="">Semua semester</flux:select.option>
                @foreach ($this->semesters as $semesterOption)
                    <flux:select.option wire:key="semester-{{ $semesterOption }}" :value="$semesterOption">Semester {{ $semesterOption }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="day" aria-label="Filter hari">
                <flux:select.option value="">Semua hari</flux:select.option>
                @foreach (\App\Enums\Day::cases() as $dayOption)
                    <flux:select.option wire:key="day-{{ $dayOption->value }}" :value="$dayOption->value">{{ $dayOption->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <flux:switch wire:model.live="hideUnavailable" label="Sembunyikan kelas yang bentrok/tidak bisa diambil" align="left" />

        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Mata Kuliah</flux:table.column>
                    <flux:table.column>Kelas</flux:table.column>
                    <flux:table.column>SKS</flux:table.column>
                    <flux:table.column>Dosen</flux:table.column>
                    <flux:table.column>Jadwal &amp; Ruang</flux:table.column>
                    <flux:table.column>Terisi</flux:table.column>
                    <flux:table.column align="end" class="hidden xl:table-cell">Aksi</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->rows as $row)
                        @php($class = $row['class'])
                        <flux:table.row wire:key="class-row-{{ $class->id }}" :class="$row['state'] === 'taken' ? 'bg-emerald-50 dark:bg-emerald-900/20' : ''">
                            <flux:table.cell>
                                <div class="font-medium text-zinc-800 dark:text-white">{{ $class->course->name }}</div>
                                <div class="mt-0.5 flex items-center gap-1.5">
                                    @if ($class->course->code)
                                        <span class="text-xs text-zinc-500">{{ $class->course->code }}</span>
                                    @endif
                                    <flux:badge size="sm" :color="$class->course->type->color()">{{ $class->course->type->label() }}</flux:badge>
                                </div>
                                <x-krs.class-action :row="$row" :editable="$this->isEditable" class="mt-2 xl:hidden" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <div>{{ $class->label() }}</div>
                                @if ($class->prodi !== '' && $class->prodi !== $class->label())
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $class->prodi }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $class->sks }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal">{{ $class->lecturerCodes() ?: '—' }}</flux:table.cell>
                            <flux:table.cell class="min-w-44 whitespace-normal">
                                <div>{{ $class->scheduleLabel() }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $class->room ?: 'Ruang belum ditentukan' }} · {{ $class->language }}</div>
                            </flux:table.cell>
                            <flux:table.cell>{{ $class->students_count }} / {{ $class->capacity ?? '∞' }}</flux:table.cell>
                            <flux:table.cell align="end" class="hidden xl:table-cell">
                                <x-krs.class-action :row="$row" :editable="$this->isEditable" class="justify-end text-end" />
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="7" class="text-center text-zinc-500">Tidak ada kelas pada periode ini yang cocok dengan filter.</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>

    <flux:modal name="confirm-clear-krs" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Lepas semua kelas?</flux:heading>
                <flux:text class="mt-2">Semua kelas di KRS periode {{ $this->period?->label() }} akan dilepas. Kamu bisa mengambilnya lagi selama kursi masih tersedia.</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="clearAll">Lepas semua</flux:button>
            </div>
        </div>
    </flux:modal>
    @endif
</section>
