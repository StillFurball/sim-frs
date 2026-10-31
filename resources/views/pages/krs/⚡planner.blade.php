<?php

use App\Actions\Krs\GenerateScheduleCombinations;
use App\Actions\Krs\SyncStudentClasses;
use App\Enums\CourseType;
use App\Enums\Day;
use App\Exceptions\EnrollmentException;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Lecturer;
use App\Models\User;
use App\Support\PlannerPreferences;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rencana Otomatis')] class extends Component {
    public int|string $maxSks = 24;

    public int|string $minSks = 0;

    public string $semester = '';

    public int|string $minElectives = 2;

    public string $maxElectives = '';

    public int|string $limit = 5;

    /** @var array<int|string, string> Course id => neutral|required|priority|excluded */
    public array $coursePreferences = [];

    /** @var array<int|string, string> Lecturer id => neutral|preferred|avoided */
    public array $lecturerPreferences = [];

    /** @var list<int|string> */
    public array $avoidedDays = [];

    public string $earliestStart = '';

    public string $latestEnd = '';

    public string $language = '';

    /** @var list<string> */
    public array $prodi = [];

    public bool $includeGeneral = false;

    public bool $includeOtherMandatory = false;

    public bool $skipFullClasses = true;

    public bool $keepCurrentClasses = false;

    public bool $compactDays = false;

    public bool $minimizeGaps = false;

    /** @var list<array{class_ids: list<int>, score: float, total_sks: int, elective_count: int, priority_count: int, preferred_lecturer_count: int, day_count: int, gap_minutes: int}> */
    public array $results = [];

    /** @var list<string> */
    public array $warnings = [];

    public bool $hasGenerated = false;

    /**
     * Fill the form with defaults based on the student and the available schedule.
     */
    public function mount(): void
    {
        $this->maxSks = $this->student()->max_sks;

        $lowestSemester = $this->mandatorySemesters()[0] ?? null;
        $this->semester = $lowestSemester !== null ? (string) $lowestSemester : '';

        $this->coursePreferences = $this->courses()->mapWithKeys(fn (Course $course): array => [$course->id => 'neutral'])->all();
        $this->lecturerPreferences = $this->lecturers()->mapWithKeys(fn (Lecturer $lecturer): array => [$lecturer->id => 'neutral'])->all();
        $this->prodi = array_values(array_filter($this->prodiOptions(), fn (string $prodi): bool => $prodi !== 'IUP'));
    }

    /**
     * Get the authenticated student.
     */
    protected function student(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * Get the active academic period the planner works on.
     */
    #[Computed]
    public function period(): ?AcademicPeriod
    {
        return AcademicPeriod::current();
    }

    /**
     * Limit a course or lecturer query to records with a class in the active period.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function offeredInPeriod(Builder $query): Builder
    {
        $period = $this->period;

        if ($period === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('classes', fn (Builder $class) => $class->where('course_classes.academic_period_id', $period->id));
    }

    /**
     * Get the SKS limits the student may plan for.
     *
     * @return list<int>
     */
    #[Computed]
    public function sksOptions(): array
    {
        return array_values(array_filter(User::SKS_LIMITS, fn (int $limit): bool => $limit <= $this->student()->max_sks));
    }

    /**
     * Get the courses offered in the active period, ordered by type and name.
     *
     * @return Collection<int, Course>
     */
    #[Computed]
    public function courses(): Collection
    {
        return $this->offeredInPeriod(Course::query())->orderBy('type')->orderBy('semester')->orderBy('name')->get();
    }

    /**
     * Get the lecturers teaching in the active period, ordered by code.
     *
     * @return Collection<int, Lecturer>
     */
    #[Computed]
    public function lecturers(): Collection
    {
        return $this->offeredInPeriod(Lecturer::query())->orderBy('code')->get();
    }

    /**
     * Get the semesters that have mandatory courses offered in the active period.
     *
     * @return list<int>
     */
    #[Computed]
    public function mandatorySemesters(): array
    {
        return $this->offeredInPeriod(Course::query())
            ->where('type', CourseType::Mandatory)
            ->whereNotNull('semester')
            ->distinct()
            ->orderBy('semester')
            ->pluck('semester')
            ->map(fn (mixed $semester): int => (int) $semester)
            ->all();
    }

    /**
     * Get the distinct study programs the active period's classes are opened for.
     *
     * @return list<string>
     */
    #[Computed]
    public function prodiOptions(): array
    {
        if ($this->period === null) {
            return [];
        }

        return CourseClass::query()
            ->inPeriod($this->period)
            ->distinct()
            ->pluck('prodi')
            ->flatMap(fn (string $prodi): array => array_filter(array_map('trim', explode(',', $prodi))))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Get the classes used by the results, keyed by id.
     *
     * @return Collection<int, CourseClass>
     */
    #[Computed]
    public function resultClasses(): Collection
    {
        $classIds = collect($this->results)->pluck('class_ids')->flatten()->unique()->values()->all();

        return CourseClass::query()
            ->with(['course', 'lecturers'])
            ->whereKey($classIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * Get the classes of one result in the order the planner returned them.
     *
     * @return Collection<int, CourseClass>
     */
    public function classesFor(int $index): Collection
    {
        return new Collection(collect($this->results[$index]['class_ids'] ?? [])
            ->map(fn (int $id): ?CourseClass => $this->resultClasses->get($id))
            ->filter()
            ->sortBy(fn (CourseClass $class): string => $class->day->value.' '.$class->start_time)
            ->values()
            ->all());
    }

    /**
     * Run the planner with the current form values.
     */
    public function generate(GenerateScheduleCombinations $generateScheduleCombinations): void
    {
        Gate::authorize('take-courses');

        $period = $this->activePeriodOrWarn();

        if ($period === null) {
            return;
        }

        $this->validate($this->rules());

        $courseIds = $this->courses->modelKeys();
        $lecturerIds = $this->lecturers->modelKeys();

        $preferences = new PlannerPreferences(
            maxSks: (int) $this->maxSks,
            minSks: (int) $this->minSks,
            semester: $this->semester !== '' ? (int) $this->semester : null,
            minElectives: (int) $this->minElectives,
            maxElectives: $this->maxElectives !== '' ? (int) $this->maxElectives : null,
            requiredCourseIds: $this->idsWithPreference($this->coursePreferences, 'required', $courseIds),
            priorityCourseIds: $this->idsWithPreference($this->coursePreferences, 'priority', $courseIds),
            excludedCourseIds: $this->idsWithPreference($this->coursePreferences, 'excluded', $courseIds),
            avoidedLecturerIds: $this->idsWithPreference($this->lecturerPreferences, 'avoided', $lecturerIds),
            preferredLecturerIds: $this->idsWithPreference($this->lecturerPreferences, 'preferred', $lecturerIds),
            avoidedDays: array_values(array_map('intval', $this->avoidedDays)),
            earliestStart: $this->earliestStart !== '' ? $this->earliestStart : null,
            latestEnd: $this->latestEnd !== '' ? $this->latestEnd : null,
            language: $this->language !== '' ? $this->language : null,
            prodi: array_values($this->prodi),
            includeGeneral: $this->includeGeneral,
            includeOtherMandatory: $this->includeOtherMandatory,
            skipFullClasses: $this->skipFullClasses,
            keepCurrentClasses: $this->keepCurrentClasses,
            compactDays: $this->compactDays,
            minimizeGaps: $this->minimizeGaps,
            limit: (int) $this->limit,
        );

        $result = $generateScheduleCombinations->handle($this->student(), $period, $preferences);

        $this->results = array_map(fn ($combination): array => $combination->toArray(), $result->combinations);
        $this->warnings = $result->warnings;
        $this->hasGenerated = true;
        unset($this->resultClasses);

        if ($this->results !== []) {
            Flux::toast(variant: 'success', text: count($this->results).' kombinasi ditemukan.');
        }
    }

    /**
     * Replace the student's KRS with one of the generated combinations.
     */
    public function apply(int $index, SyncStudentClasses $syncStudentClasses): void
    {
        Gate::authorize('take-courses');

        $period = $this->activePeriodOrWarn();

        if ($period === null) {
            return;
        }

        if (! isset($this->results[$index])) {
            Flux::toast(variant: 'danger', text: 'Kombinasi tidak ditemukan. Jalankan pencarian lagi.');

            return;
        }

        try {
            $syncStudentClasses->handle($this->student(), $period, $this->results[$index]['class_ids']);
        } catch (EnrollmentException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: 'KRS berhasil diganti dengan kombinasi #'.($index + 1).'.');

        $this->redirectRoute('krs.index', navigate: true);
    }

    /**
     * Get the active period, or tell the student the planner cannot run without one.
     */
    protected function activePeriodOrWarn(): ?AcademicPeriod
    {
        if ($this->period === null) {
            Flux::toast(variant: 'danger', text: 'Belum ada periode aktif. Rencana otomatis tidak bisa dijalankan.');
        }

        return $this->period;
    }

    /**
     * Get the validation rules for the planner form.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'maxSks' => ['required', 'integer', Rule::in($this->sksOptions)],
            'minSks' => ['required', 'integer', 'min:0', 'lte:maxSks'],
            'semester' => ['nullable', 'integer', 'min:1', 'max:14'],
            'minElectives' => ['required', 'integer', 'min:0', 'max:12'],
            'maxElectives' => ['nullable', 'integer', 'min:0', 'max:12', 'gte:minElectives'],
            'limit' => ['required', 'integer', Rule::in([3, 5, 10])],
            'coursePreferences' => ['array'],
            'coursePreferences.*' => [Rule::in(['neutral', 'required', 'priority', 'excluded'])],
            'lecturerPreferences' => ['array'],
            'lecturerPreferences.*' => [Rule::in(['neutral', 'preferred', 'avoided'])],
            'avoidedDays' => ['array'],
            'avoidedDays.*' => ['integer', Rule::in(array_map(fn (Day $day): int => $day->value, Day::cases()))],
            'earliestStart' => ['nullable', 'date_format:H:i'],
            'latestEnd' => ['nullable', 'date_format:H:i'],
            'language' => ['nullable', Rule::in(['Indonesia', 'English'])],
            'prodi' => ['array'],
            'prodi.*' => ['string', Rule::in($this->prodiOptions)],
            'includeGeneral' => ['boolean'],
            'includeOtherMandatory' => ['boolean'],
            'skipFullClasses' => ['boolean'],
            'keepCurrentClasses' => ['boolean'],
            'compactDays' => ['boolean'],
            'minimizeGaps' => ['boolean'],
        ];

        if ($this->earliestStart !== '' && $this->latestEnd !== '') {
            $rules['latestEnd'][] = 'after:earliestStart';
        }

        return $rules;
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'maxSks.in' => 'Batas SKS harus salah satu dari '.implode(', ', $this->sksOptions).' (maksimal batas SKS kamu).',
            'minSks.lte' => 'SKS minimal tidak boleh melebihi batas SKS.',
            'maxElectives.gte' => 'Maksimal pilihan tidak boleh kurang dari minimal pilihan.',
            'latestEnd.after' => 'Jam selesai paling lambat harus setelah jam mulai paling awal.',
        ];
    }

    /**
     * Get the ids (of existing records only) that have the given preference.
     *
     * @param  array<int|string, string>  $preferences
     * @param  array<int, mixed>  $validIds
     * @return list<int>
     */
    protected function idsWithPreference(array $preferences, string $preference, array $validIds): array
    {
        $validIds = array_map('intval', $validIds);

        return array_values(array_filter(
            array_map('intval', array_keys(array_filter($preferences, fn (string $value): bool => $value === $preference))),
            fn (int $id): bool => in_array($id, $validIds, true),
        ));
    }

    /**
     * Format idle minutes as "x jam y menit".
     */
    public function formatGap(int $minutes): string
    {
        if ($minutes === 0) {
            return 'tanpa jeda';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours > 0 ? "{$hours} jam " : '').($rest > 0 ? "{$rest} menit" : ''));
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Rencana Otomatis</flux:heading>
            <flux:text class="mt-1">
                @if ($this->period)
                    Periode {{ $this->period->label() }}.
                @endif
                Cari kombinasi kelas tanpa bentrok sesuai batas SKS dan preferensimu.
            </flux:text>
        </div>
        <flux:button :href="route('krs.index')" icon="clipboard-document-list" wire:navigate>Ke KRS</flux:button>
    </div>

    @if (! $this->period)
        <flux:callout variant="warning" icon="exclamation-triangle" heading="Belum ada periode aktif. Rencana otomatis tidak bisa dijalankan." />
    @else
    <form wire:submit="generate" class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-2">
            <flux:card class="space-y-4">
                <flux:heading size="lg">Target</flux:heading>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="maxSks" label="Batas SKS">
                        @foreach ($this->sksOptions as $option)
                            <flux:select.option wire:key="sks-{{ $option }}" :value="$option">{{ $option }} SKS</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="minSks" type="number" min="0" max="24" label="SKS minimal" />
                    <flux:select wire:model="semester" label="Wajibkan MK wajib semester">
                        <flux:select.option value="">Tidak ada</flux:select.option>
                        @foreach ($this->mandatorySemesters as $mandatorySemester)
                            <flux:select.option wire:key="semester-{{ $mandatorySemester }}" :value="$mandatorySemester">Semester {{ $mandatorySemester }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="limit" label="Jumlah hasil">
                        <flux:select.option value="3">3 kombinasi</flux:select.option>
                        <flux:select.option value="5">5 kombinasi</flux:select.option>
                        <flux:select.option value="10">10 kombinasi</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="minElectives" type="number" min="0" max="12" label="Minimal MK pilihan" />
                    <flux:input wire:model="maxElectives" type="number" min="0" max="12" label="Maksimal MK pilihan" placeholder="Tanpa batas" />
                </div>
            </flux:card>

            <flux:card class="space-y-4">
                <flux:heading size="lg">Jadwal</flux:heading>
                <flux:checkbox.group wire:model="avoidedDays" label="Hindari hari">
                    <div class="flex flex-wrap gap-x-4 gap-y-2">
                        @foreach (\App\Enums\Day::cases() as $day)
                            <flux:checkbox wire:key="day-{{ $day->value }}" :value="$day->value" :label="$day->label()" />
                        @endforeach
                    </div>
                </flux:checkbox.group>
                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:input wire:model="earliestStart" type="time" label="Mulai paling awal" />
                    <flux:input wire:model="latestEnd" type="time" label="Selesai paling lambat" />
                    <flux:select wire:model="language" label="Bahasa">
                        <flux:select.option value="">Semua</flux:select.option>
                        <flux:select.option value="Indonesia">Indonesia</flux:select.option>
                        <flux:select.option value="English">English</flux:select.option>
                    </flux:select>
                </div>
                @if ($this->prodiOptions !== [])
                    <flux:checkbox.group wire:model="prodi" label="Kelas untuk prodi" description="Kelas yang dibuka untuk salah satu prodi terpilih.">
                        <div class="flex flex-wrap gap-x-4 gap-y-2">
                            @foreach ($this->prodiOptions as $prodiOption)
                                <flux:checkbox wire:key="prodi-{{ $prodiOption }}" :value="$prodiOption" :label="$prodiOption" />
                            @endforeach
                        </div>
                    </flux:checkbox.group>
                @endif
            </flux:card>
        </div>

        <flux:card class="space-y-4">
            <flux:heading size="lg">Opsi</flux:heading>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <flux:checkbox wire:model="includeGeneral" label="Sertakan MK Umum (SKPB)" />
                <flux:checkbox wire:model="includeOtherMandatory" label="Sertakan MK wajib semester lain" />
                <flux:checkbox wire:model="skipFullClasses" label="Lewati kelas yang penuh" />
                <flux:checkbox wire:model="keepCurrentClasses" label="Pertahankan kelas yang sudah ada di KRS" />
                <flux:checkbox wire:model="compactDays" label="Minimalkan hari kuliah" />
                <flux:checkbox wire:model="minimizeGaps" label="Minimalkan jeda antar kelas" />
            </div>
        </flux:card>

        <div class="grid gap-6 lg:grid-cols-2">
            <flux:card class="space-y-4" x-data="{ open: false }">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <flux:heading size="lg">Mata kuliah</flux:heading>
                        <flux:text>Wajibkan, prioritaskan, atau hindari mata kuliah tertentu.</flux:text>
                    </div>
                    <flux:button size="sm" variant="ghost" x-on:click="open = ! open" x-text="open ? 'Sembunyikan' : 'Tampilkan'">Tampilkan</flux:button>
                </div>
                <div x-show="open" x-cloak class="max-h-[28rem] overflow-y-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Mata kuliah</flux:table.column>
                            <flux:table.column>SKS</flux:table.column>
                            <flux:table.column>Preferensi</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->courses as $course)
                                <flux:table.row wire:key="course-pref-{{ $course->id }}">
                                    <flux:table.cell class="whitespace-normal">
                                        <div class="font-medium">{{ $course->name }}</div>
                                        <div class="mt-1 flex items-center gap-1">
                                            <flux:badge size="sm" :color="$course->type->color()">{{ $course->type->label() }}</flux:badge>
                                            @if ($course->semester)
                                                <span class="text-xs text-zinc-500">Sem {{ $course->semester }}</span>
                                            @endif
                                        </div>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $course->sks }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:select size="sm" wire:model="coursePreferences.{{ $course->id }}" :aria-label="'Preferensi '.$course->name">
                                            <flux:select.option value="neutral">Netral</flux:select.option>
                                            <flux:select.option value="required">Wajib ambil</flux:select.option>
                                            <flux:select.option value="priority">Prioritaskan</flux:select.option>
                                            <flux:select.option value="excluded">Hindari</flux:select.option>
                                        </flux:select>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </flux:card>

            <flux:card class="space-y-4" x-data="{ open: false }">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <flux:heading size="lg">Dosen</flux:heading>
                        <flux:text>Pilih dosen favorit atau dosen yang ingin dihindari.</flux:text>
                    </div>
                    <flux:button size="sm" variant="ghost" x-on:click="open = ! open" x-text="open ? 'Sembunyikan' : 'Tampilkan'">Tampilkan</flux:button>
                </div>
                <div x-show="open" x-cloak class="grid max-h-[28rem] gap-3 overflow-y-auto sm:grid-cols-2">
                    @forelse ($this->lecturers as $lecturer)
                        <div wire:key="lecturer-pref-{{ $lecturer->id }}" class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                            <div class="min-w-0">
                                <div class="font-medium">{{ $lecturer->code }}</div>
                                @if ($lecturer->name)
                                    <div class="truncate text-xs text-zinc-500">{{ $lecturer->name }}</div>
                                @endif
                            </div>
                            <div class="w-28 shrink-0">
                                <flux:select size="sm" wire:model="lecturerPreferences.{{ $lecturer->id }}" :aria-label="'Preferensi dosen '.$lecturer->displayName()">
                                    <flux:select.option value="neutral">Netral</flux:select.option>
                                    <flux:select.option value="preferred">Suka</flux:select.option>
                                    <flux:select.option value="avoided">Hindari</flux:select.option>
                                </flux:select>
                            </div>
                        </div>
                    @empty
                        <flux:text>Belum ada data dosen.</flux:text>
                    @endforelse
                </div>
            </flux:card>
        </div>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary" icon="sparkles" data-test="generate-button">
                <span wire:loading.remove wire:target="generate">Cari kombinasi</span>
                <span wire:loading wire:target="generate">Mencari…</span>
            </flux:button>
        </div>
    </form>

    @foreach ($warnings as $warningIndex => $warning)
        <flux:callout wire:key="warning-{{ $warningIndex }}" variant="warning" icon="exclamation-triangle" :heading="$warning" />
    @endforeach

    @if (! $hasGenerated)
        <flux:card class="text-center">
            <flux:heading>Belum ada hasil</flux:heading>
            <flux:text class="mt-1">Atur preferensi di atas lalu tekan "Cari kombinasi".</flux:text>
        </flux:card>
    @endif

    <div class="space-y-6">
        @foreach ($results as $index => $result)
            @php($classes = $this->classesFor($index))
            <flux:card wire:key="result-{{ $index }}-{{ implode('-', $result['class_ids']) }}" class="space-y-4" x-data="{ showGrid: {{ $index === 0 ? 'true' : 'false' }} }">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-2">
                        <flux:heading size="lg">Kombinasi #{{ $index + 1 }}</flux:heading>
                        <div class="flex flex-wrap gap-2">
                            <flux:badge color="blue">{{ $result['total_sks'] }} SKS</flux:badge>
                            <flux:badge>{{ $result['elective_count'] }} MK pilihan</flux:badge>
                            <flux:badge>{{ $result['day_count'] }} hari kuliah</flux:badge>
                            <flux:badge>Jeda {{ $this->formatGap($result['gap_minutes']) }}</flux:badge>
                            @if ($result['priority_count'] > 0)
                                <flux:badge color="green">{{ $result['priority_count'] }} prioritas terpenuhi</flux:badge>
                            @endif
                            @if ($result['preferred_lecturer_count'] > 0)
                                <flux:badge color="violet">{{ $result['preferred_lecturer_count'] }} kelas dosen favorit</flux:badge>
                            @endif
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <flux:button size="sm" variant="ghost" icon="calendar-days" x-on:click="showGrid = ! showGrid">Jadwal</flux:button>
                        <flux:modal.trigger name="apply-{{ $index }}">
                            <flux:button size="sm" variant="primary" icon="check" data-test="apply-button-{{ $index }}">Terapkan ke KRS</flux:button>
                        </flux:modal.trigger>
                    </div>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Mata kuliah</flux:table.column>
                        <flux:table.column>Kelas</flux:table.column>
                        <flux:table.column>Jadwal</flux:table.column>
                        <flux:table.column>Ruang</flux:table.column>
                        <flux:table.column>Dosen</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($classes as $class)
                            <flux:table.row wire:key="result-{{ $index }}-class-{{ $class->id }}">
                                <flux:table.cell class="whitespace-normal">
                                    <span class="font-medium">{{ $class->course->name }}</span>
                                    <flux:badge size="sm" :color="$class->course->type->color()" class="ms-1">{{ $class->course->type->label() }}</flux:badge>
                                    <span class="text-xs text-zinc-500">{{ $class->sks }} SKS</span>
                                </flux:table.cell>
                                <flux:table.cell>{{ $class->label() }}</flux:table.cell>
                                <flux:table.cell>{{ $class->scheduleLabel() }}</flux:table.cell>
                                <flux:table.cell class="whitespace-normal">{{ $class->room ?? '-' }}</flux:table.cell>
                                <flux:table.cell>{{ $class->lecturerCodes() ?: '-' }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                <div x-show="showGrid" x-cloak>
                    <x-schedule-grid :classes="$classes" :text-alternative="false" />
                </div>

                <flux:modal name="apply-{{ $index }}" class="md:w-96">
                    <div class="space-y-6">
                        <div>
                            <flux:heading size="lg">Terapkan kombinasi #{{ $index + 1 }}?</flux:heading>
                            <flux:text class="mt-2">Seluruh kelas di KRS kamu untuk periode {{ $this->period->label() }} akan diganti dengan {{ count($result['class_ids']) }} kelas ({{ $result['total_sks'] }} SKS) dari kombinasi ini.</flux:text>
                        </div>
                        <div class="flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="ghost">Batal</flux:button>
                            </flux:modal.close>
                            <flux:button variant="primary" wire:click="apply({{ $index }})">Ya, terapkan</flux:button>
                        </div>
                    </div>
                </flux:modal>
            </flux:card>
        @endforeach
    </div>
    @endif
</section>
