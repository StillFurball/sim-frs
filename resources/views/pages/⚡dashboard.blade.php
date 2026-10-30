<?php

use App\Enums\UserRole;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Lecturer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * Get the authenticated user.
     */
    #[Computed]
    public function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * Get the active academic period, if any.
     */
    #[Computed]
    public function period(): ?AcademicPeriod
    {
        return AcademicPeriod::current();
    }

    /**
     * Get the classes in the student's KRS for the active period.
     *
     * @return Collection<int, CourseClass>
     */
    #[Computed]
    public function takenClasses(): Collection
    {
        if ($this->period === null) {
            return new Collection;
        }

        return $this->user->classesInPeriod($this->period)->with(['course', 'lecturers'])->get();
    }

    /**
     * Get the student's KRS summary for the active period.
     *
     * @return array{sks: int, courses: int, days: int}
     */
    #[Computed]
    public function studentSummary(): array
    {
        return [
            'sks' => $this->takenClasses->sum(fn (CourseClass $class): int => $class->course->sks),
            'courses' => $this->takenClasses->unique('course_id')->count(),
            'days' => $this->takenClasses->map(fn (CourseClass $class): int => $class->day->value)->unique()->count(),
        ];
    }

    /**
     * Get the academic data counters shown to admins; classes and enrollments are for the active period.
     *
     * @return array{courses: int, classes: int, lecturers: int, students: int, enrollments: int}
     */
    #[Computed]
    public function adminStats(): array
    {
        $periodId = $this->period?->id;

        return [
            'courses' => Course::query()->count(),
            'classes' => $periodId === null ? 0 : CourseClass::query()->inPeriod($periodId)->count(),
            'lecturers' => Lecturer::query()->count(),
            'students' => User::query()->where('role', UserRole::Student)->count(),
            'enrollments' => $periodId === null ? 0 : DB::table('enrollments')
                ->join('course_classes', 'course_classes.id', '=', 'enrollments.course_class_id')
                ->where('course_classes.academic_period_id', $periodId)
                ->count(),
        ];
    }

    /**
     * Get the five classes of the active period with the most enrolled students.
     *
     * @return Collection<int, CourseClass>
     */
    #[Computed]
    public function popularClasses(): Collection
    {
        if ($this->period === null) {
            return new Collection;
        }

        return CourseClass::query()
            ->inPeriod($this->period)
            ->with('course')
            ->withCount('students')
            ->orderByDesc('students_count')
            ->orderBy('id')
            ->limit(5)
            ->get();
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Dashboard</flux:heading>
            <flux:text class="mt-1">Selamat datang, {{ $this->user->name }}.</flux:text>
        </div>

        @can('take-courses')
            <div class="flex gap-2">
                <flux:button :href="route('krs.index')" icon="clipboard-document-list" wire:navigate>Isi KRS</flux:button>
                <flux:button :href="route('krs.planner')" variant="primary" icon="sparkles" wire:navigate>Rencana Otomatis</flux:button>
            </div>
        @endcan

        @can('manage-academics')
            <div class="flex flex-wrap gap-2">
                <flux:button :href="route('admin.periods')" icon="calendar" wire:navigate>Periode Akademik</flux:button>
                <flux:button :href="route('admin.import')" icon="arrow-up-tray" wire:navigate>Import Jadwal</flux:button>
                <flux:button :href="route('admin.classes')" icon="calendar-days" wire:navigate>Kelas & Jadwal</flux:button>
                <flux:button :href="route('admin.students')" icon="users" wire:navigate>Mahasiswa</flux:button>
            </div>
        @endcan
    </div>

    @if ($this->period)
        <flux:callout icon="calendar" color="blue" data-test="active-period">
            <flux:callout.heading>Periode aktif: {{ $this->period->label() }}</flux:callout.heading>
        </flux:callout>
    @else
        <flux:callout icon="exclamation-triangle" variant="warning" data-test="active-period">
            <flux:callout.heading>Belum ada periode aktif</flux:callout.heading>
            @can('manage-academics')
                <flux:callout.text>Aktifkan salah satu periode di menu Periode Akademik agar mahasiswa bisa mengisi KRS.</flux:callout.text>
            @endcan
        </flux:callout>
    @endif

    @can('take-courses')
        @php($summary = $this->studentSummary)
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:card>
                <flux:text>SKS diambil</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $summary['sks'] }} / {{ $this->user->max_sks }} SKS</flux:heading>
                <flux:text class="mt-1 text-xs">Sisa {{ max($this->user->max_sks - $summary['sks'], 0) }} SKS</flux:text>
            </flux:card>
            <flux:card>
                <flux:text>Mata kuliah</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $summary['courses'] }}</flux:heading>
            </flux:card>
            <flux:card>
                <flux:text>Hari kuliah</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $summary['days'] }} hari</flux:heading>
            </flux:card>
        </div>

        <div class="space-y-3">
            <flux:heading size="lg">Jadwal mingguan</flux:heading>
            <x-schedule-grid :classes="$this->takenClasses" empty-text="Belum ada kelas yang diambil. Mulai isi KRS atau gunakan Rencana Otomatis." />
        </div>
    @endcan

    @can('manage-academics')
        @php($stats = $this->adminStats)
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                'courses' => 'Mata kuliah',
                'classes' => 'Kelas (periode aktif)',
                'lecturers' => 'Dosen',
                'students' => 'Mahasiswa',
                'enrollments' => 'Pengambilan KRS (periode aktif)',
            ] as $key => $label)
                <flux:card wire:key="stat-{{ $key }}">
                    <flux:text>{{ $label }}</flux:text>
                    <flux:heading size="xl" class="mt-1"><span data-test="stat-{{ $key }}">{{ $stats[$key] }}</span></flux:heading>
                </flux:card>
            @endforeach
        </div>

        <flux:card class="space-y-3">
            <flux:heading size="lg">Kelas terpopuler</flux:heading>
            @if ($this->popularClasses->isEmpty())
                <flux:text>Belum ada kelas pada periode aktif. Import jadwal terlebih dahulu.</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Mata kuliah</flux:table.column>
                        <flux:table.column>Kelas</flux:table.column>
                        <flux:table.column>Jadwal</flux:table.column>
                        <flux:table.column align="end">Peserta</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->popularClasses as $class)
                            <flux:table.row wire:key="popular-{{ $class->id }}">
                                <flux:table.cell class="whitespace-normal font-medium">{{ $class->course->name }}</flux:table.cell>
                                <flux:table.cell>{{ $class->label() }}</flux:table.cell>
                                <flux:table.cell>{{ $class->scheduleLabel() }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $class->students_count }} / {{ $class->capacity ?? '∞' }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>
    @endcan
</section>
