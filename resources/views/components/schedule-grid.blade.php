@props([
    /** @var iterable<\App\Models\CourseClass> Classes with `course` (and optionally `lecturers`) loaded. */
    'classes' => [],
    'emptyText' => 'Belum ada kelas yang diambil.',
    /** Render a screen-reader list of the classes; turn off where the page already shows the classes as text. */
    'textAlternative' => true,
])

@php
    $classes = collect($classes);
    $toMinutes = fn (string $time): int => ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);

    $days = collect(\App\Enums\Day::cases())
        ->reject(fn (\App\Enums\Day $day) => $day === \App\Enums\Day::Sabtu && ! $classes->contains(fn ($class) => $class->day === \App\Enums\Day::Sabtu));

    $dayStart = min(7 * 60, $classes->min(fn ($class) => $toMinutes($class->start_time)) ?? 7 * 60);
    $dayEnd = max(17 * 60 + 30, $classes->max(fn ($class) => $toMinutes($class->end_time)) ?? 0);
    $dayStart -= $dayStart % 60;
    $span = max($dayEnd - $dayStart, 60);
    $hours = range(intdiv($dayStart, 60), intdiv($dayEnd - 1, 60));

    $palette = [
        'bg-sky-100 border-sky-300 text-sky-900 dark:bg-sky-900/40 dark:border-sky-700 dark:text-sky-100',
        'bg-emerald-100 border-emerald-300 text-emerald-900 dark:bg-emerald-900/40 dark:border-emerald-700 dark:text-emerald-100',
        'bg-amber-100 border-amber-300 text-amber-900 dark:bg-amber-900/40 dark:border-amber-700 dark:text-amber-100',
        'bg-rose-100 border-rose-300 text-rose-900 dark:bg-rose-900/40 dark:border-rose-700 dark:text-rose-100',
        'bg-violet-100 border-violet-300 text-violet-900 dark:bg-violet-900/40 dark:border-violet-700 dark:text-violet-100',
        'bg-teal-100 border-teal-300 text-teal-900 dark:bg-teal-900/40 dark:border-teal-700 dark:text-teal-100',
        'bg-orange-100 border-orange-300 text-orange-900 dark:bg-orange-900/40 dark:border-orange-700 dark:text-orange-100',
        'bg-indigo-100 border-indigo-300 text-indigo-900 dark:bg-indigo-900/40 dark:border-indigo-700 dark:text-indigo-100',
    ];
@endphp

<div {{ $attributes->class('overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700') }}>
    @if ($classes->isEmpty())
        <div class="p-6 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ $emptyText }}</div>
    @else
        {{-- Text alternative of the visual grid below, which is hidden from assistive technology. --}}
        @if ($textAlternative)
            <ul class="sr-only" aria-label="Jadwal mingguan">
                @foreach ($classes->sortBy(fn ($class) => sprintf('%d %s', $class->day->value, $class->start_time)) as $class)
                    <li wire:key="grid-sr-{{ $class->id }}">
                        {{ $class->scheduleLabel() }}: {{ $class->course->name }}, kelas {{ $class->label() }}{{ $class->room ? ', ruang '.$class->room : '' }}{{ $class->relationLoaded('lecturers') && $class->lecturers->isNotEmpty() ? ', dosen '.$class->lecturerCodes() : '' }}
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="grid min-w-[640px]" aria-hidden="true" style="grid-template-columns: 3.5rem repeat({{ $days->count() }}, minmax(0, 1fr));">
            <div class="border-b border-zinc-200 dark:border-zinc-700"></div>
            @foreach ($days as $day)
                <div wire:key="grid-head-{{ $day->value }}" class="border-b border-s border-zinc-200 px-2 py-2 text-center text-xs font-semibold text-zinc-700 dark:border-zinc-700 dark:text-zinc-200">
                    {{ $day->label() }}
                </div>
            @endforeach

            <div class="relative" style="height: {{ count($hours) * 3.5 }}rem;">
                @foreach ($hours as $hour)
                    <div wire:key="grid-hour-{{ $hour }}" class="absolute inset-x-0 pe-1 text-end text-xs text-zinc-500 dark:text-zinc-400" style="top: {{ (($hour * 60 - $dayStart) / $span) * 100 }}%;">
                        {{ sprintf('%02d:00', $hour) }}
                    </div>
                @endforeach
            </div>

            @foreach ($days as $day)
                <div wire:key="grid-day-{{ $day->value }}" class="relative border-s border-zinc-200 dark:border-zinc-700" style="height: {{ count($hours) * 3.5 }}rem;">
                    @foreach ($hours as $hour)
                        <div class="absolute inset-x-0 border-t border-dashed border-zinc-100 dark:border-zinc-800" style="top: {{ (($hour * 60 - $dayStart) / $span) * 100 }}%;"></div>
                    @endforeach

                    @foreach ($classes->filter(fn ($class) => $class->day === $day) as $class)
                        @php
                            $top = (($toMinutes($class->start_time) - $dayStart) / $span) * 100;
                            $height = (($toMinutes($class->end_time) - $toMinutes($class->start_time)) / $span) * 100;
                        @endphp
                        <div
                            wire:key="grid-class-{{ $class->id }}"
                            class="absolute inset-x-1 overflow-hidden rounded-md border px-1.5 py-1 text-xs leading-tight shadow-xs {{ $palette[$class->course_id % count($palette)] }}"
                            style="top: {{ $top }}%; height: {{ $height }}%;"
                            title="{{ $class->course->name }} ({{ $class->label() }}) · {{ $class->scheduleLabel() }}{{ $class->room ? ' · '.$class->room : '' }}"
                        >
                            <div class="font-semibold">{{ $class->course->name }}</div>
                            <div class="opacity-90">{{ $class->label() }} · {{ $class->start_time }}–{{ $class->end_time }}</div>
                            @if ($class->room)
                                <div class="truncate opacity-90">{{ $class->room }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</div>
