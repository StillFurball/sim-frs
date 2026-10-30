@props([
    /** @var array{class: \App\Models\CourseClass, state: string, reason: string|null, tone: string|null, detail: string|null} One row of the KRS class list. */
    'row',
    /** Whether the viewed period's KRS may be changed. */
    'editable' => false,
])

@php($class = $row['class'])

<div {{ $attributes->class('flex flex-wrap items-center gap-2') }}>
    @switch($row['state'])
        @case('taken')
            <flux:badge size="sm" color="green" icon="check">Diambil</flux:badge>
            @if ($editable)
                <flux:button size="sm" variant="ghost" wire:click="drop({{ $class->id }})">Lepas</flux:button>
            @endif
            @break
        @case('switch')
            <flux:button size="sm" icon="arrows-right-left" wire:click="take({{ $class->id }})">Pindah ke kelas ini</flux:button>
            @break
        @case('take')
            <flux:button size="sm" variant="primary" icon="plus" wire:click="take({{ $class->id }})">Ambil</flux:button>
            @break
        @case('readonly')
            <span class="text-sm text-zinc-500 dark:text-zinc-400">Hanya lihat</span>
            @break
        @default
            <flux:badge
                size="sm"
                :color="$row['tone'] ?? 'zinc'"
                :icon="match ($row['tone']) { 'amber' => 'exclamation-triangle', 'red' => 'no-symbol', default => 'scale' }"
            >{{ $row['reason'] }}</flux:badge>
            @if ($row['detail'])
                <span class="basis-full text-xs whitespace-normal text-zinc-500 dark:text-zinc-400">{{ $row['detail'] }}</span>
            @endif
    @endswitch
</div>
