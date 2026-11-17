<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    private const int MAX_SKS = 24;

    /**
     * The dummy classes offered by the simulator. Struktur Data and Sistem Operasi clash on purpose.
     *
     * @var array<string, array{name: string, sks: int, required: bool, day: string, start: string, end: string, seats: int}>
     */
    private const array COURSES = [
        'SD' => ['name' => 'Struktur Data', 'sks' => 3, 'required' => true, 'day' => 'Senin', 'start' => '08.00', 'end' => '10.30', 'seats' => 5],
        'SO' => ['name' => 'Sistem Operasi', 'sks' => 3, 'required' => true, 'day' => 'Senin', 'start' => '08.00', 'end' => '10.30', 'seats' => 12],
        'BD' => ['name' => 'Basis Data', 'sks' => 3, 'required' => true, 'day' => 'Selasa', 'start' => '10.00', 'end' => '12.30', 'seats' => 2],
        'JK' => ['name' => 'Jaringan Komputer', 'sks' => 3, 'required' => false, 'day' => 'Rabu', 'start' => '13.00', 'end' => '15.30', 'seats' => 8],
    ];

    /**
     * The codes of the courses the visitor has taken.
     *
     * @var list<string>
     */
    #[Locked]
    public array $taken = [];

    public ?string $clashWarning = null;

    /**
     * Take a course, unless its schedule clashes with one already taken.
     */
    public function take(string $code): void
    {
        if (! isset(self::COURSES[$code]) || in_array($code, $this->taken, true)) {
            return;
        }

        $clashing = $this->clashingCourse($code, $this->taken);

        if ($clashing !== null) {
            $course = self::COURSES[$code];
            $this->clashWarning = "{$course['name']} bentrok dengan ".self::COURSES[$clashing]['name']." ({$course['day']} {$course['start']}). Lepas salah satunya dulu.";

            return;
        }

        $this->taken[] = $code;
        $this->clashWarning = null;
    }

    /**
     * Drop a taken course and give its seat back.
     */
    public function drop(string $code): void
    {
        $this->taken = array_values(array_diff($this->taken, [$code]));
        $this->clashWarning = null;
    }

    /**
     * Get the offered courses with the visitor's selection and the seats left.
     *
     * @return array<string, array{name: string, sks: int, required: bool, day: string, start: string, end: string, seats: int, taken: bool, seatsLeft: int}>
     */
    #[Computed]
    public function courses(): array
    {
        return collect(self::COURSES)->map(function (array $course, string $code): array {
            $taken = in_array($code, $this->taken, true);

            return $course + ['taken' => $taken, 'seatsLeft' => $course['seats'] - (int) $taken];
        })->all();
    }

    #[Computed]
    public function totalSks(): int
    {
        return array_sum(array_map(fn (string $code): int => self::COURSES[$code]['sks'], $this->taken));
    }

    /**
     * Get the order in which to take the remaining courses: required courses first, then the fewest seats left.
     * A course that clashes with one taken or recommended before it is listed last, as one to skip.
     *
     * @return list<array{code: string, name: string, reason: string, skip: bool}>
     */
    #[Computed]
    public function recommended(): array
    {
        $remaining = collect($this->courses)
            ->reject(fn (array $course): bool => $course['taken'])
            ->sortBy(fn (array $course): array => [! $course['required'], $course['seatsLeft']]);

        $plan = $this->taken;
        $steps = [];
        $skipped = [];

        foreach ($remaining as $code => $course) {
            $clashing = $this->clashingCourse($code, $plan);

            if ($clashing !== null) {
                $skipped[] = ['code' => $code, 'name' => $course['name'], 'reason' => 'Lewati, bentrok dengan '.self::COURSES[$clashing]['name'], 'skip' => true];

                continue;
            }

            $plan[] = $code;
            $steps[] = ['code' => $code, 'name' => $course['name'], 'reason' => ($course['required'] ? 'Wajib' : 'Pilihan').", sisa {$course['seatsLeft']} kursi", 'skip' => false];
        }

        return [...$steps, ...$skipped];
    }

    /**
     * Find the first of the given courses whose schedule overlaps the course.
     *
     * @param  list<string>  $others
     */
    private function clashingCourse(string $code, array $others): ?string
    {
        $course = self::COURSES[$code];

        return array_find($others, fn (string $other): bool => $course['day'] === self::COURSES[$other]['day']
            && $course['start'] < self::COURSES[$other]['end']
            && self::COURSES[$other]['start'] < $course['end']);
    }

    /**
     * @return array{maxSks: int}
     */
    public function with(): array
    {
        return ['maxSks' => self::MAX_SKS];
    }
};
?>

<div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="font-semibold text-slate-900">Simulasi KRS</h2>
            <p class="text-sm text-slate-500">Teknik Informatika, data contoh</p>
        </div>
        <p class="text-right" aria-live="polite">
            <span class="block text-xs text-slate-500">Total SKS terpilih</span>
            <span class="text-lg font-semibold text-indigo-600 tabular-nums">{{ $this->totalSks }} / {{ $maxSks }} SKS</span>
        </p>
    </div>

    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
        <div class="h-full rounded-full bg-indigo-600 transition-all duration-300" style="width: {{ $this->totalSks / $maxSks * 100 }}%"></div>
    </div>

    @if ($clashWarning)
        <p role="alert" class="mt-4 flex gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 shrink-0" />
            <span>{{ $clashWarning }}</span>
        </p>
    @endif

    <ul class="mt-4 divide-y divide-slate-100">
        @foreach ($this->courses as $code => $course)
            <li wire:key="course-{{ $code }}" class="flex items-center justify-between gap-3 py-3">
                <div class="min-w-0">
                    <p class="truncate font-medium text-slate-900">{{ $course['name'] }} <span class="font-normal text-slate-500">· {{ $course['sks'] }} SKS</span></p>
                    <p class="text-sm text-slate-500">
                        {{ $course['day'] }} {{ $course['start'] }}–{{ $course['end'] }} ·
                        <span @class(['tabular-nums', 'text-amber-600' => $course['seatsLeft'] <= 3])>sisa {{ $course['seatsLeft'] }} kursi</span>
                    </p>
                </div>
                @if ($course['taken'])
                    <button type="button" wire:click="drop('{{ $code }}')" aria-label="Lepas {{ $course['name'] }}"
                        class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-700 transition hover:bg-emerald-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 data-loading:opacity-60">
                        <flux:icon.check variant="micro" /> Diambil
                    </button>
                @else
                    <button type="button" wire:click="take('{{ $code }}')" aria-label="Ambil {{ $course['name'] }}"
                        class="shrink-0 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-indigo-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 data-loading:opacity-60">
                        Ambil
                    </button>
                @endif
            </li>
        @endforeach
    </ul>

    <div class="mt-2 rounded-xl bg-slate-50 p-4">
        <h3 class="flex items-center gap-1.5 text-sm font-semibold text-slate-900">
            <flux:icon.sparkles variant="mini" class="text-indigo-600" /> Rekomendasi urutan ambil
        </h3>
        @if ($this->recommended === [])
            <p class="mt-2 text-sm text-slate-500">Semua mata kuliah yang muat sudah kamu ambil.</p>
        @else
            <ol class="mt-2 space-y-1.5 text-sm">
                @foreach ($this->recommended as $step)
                    <li wire:key="step-{{ $step['code'] }}" class="flex items-baseline gap-2" data-test="recommended-{{ $step['code'] }}">
                        @if ($step['skip'])
                            <span class="w-5 shrink-0 text-center text-slate-400" aria-hidden="true">–</span>
                            <span class="text-slate-400">{{ $step['name'] }} <span class="text-red-600/80">· {{ $step['reason'] }}</span></span>
                        @else
                            <span class="w-5 shrink-0 text-center font-semibold text-indigo-600 tabular-nums">{{ $loop->iteration }}.</span>
                            <span class="text-slate-700">{{ $step['name'] }} <span class="text-slate-500">· {{ $step['reason'] }}</span></span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</div>
