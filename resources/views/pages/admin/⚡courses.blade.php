<?php

use App\Enums\CourseType;
use App\Models\AcademicPeriod;
use App\Models\Course;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Mata Kuliah')] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $typeFilter = '';

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $code = '';

    public string $name = '';

    public string $semester = '';

    public string $type = 'pilihan';

    public string $sks = '3';

    /**
     * Reset pagination when a filter changes.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'typeFilter'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Get the active academic period, whose classes are counted in the table.
     */
    #[Computed]
    public function activePeriod(): ?AcademicPeriod
    {
        return AcademicPeriod::current();
    }

    /**
     * Get the paginated, filtered courses with their class count in the active period.
     *
     * @return LengthAwarePaginator<int, Course>
     */
    #[Computed]
    public function courses(): LengthAwarePaginator
    {
        return Course::query()
            ->withCount(['classes' => fn ($query) => $query->where('academic_period_id', $this->activePeriod?->id ?? 0)])
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%")))
            ->when($this->typeFilter !== '', fn ($query) => $query->where('type', $this->typeFilter))
            ->orderBy('semester')
            ->orderBy('name')
            ->paginate(15);
    }

    /**
     * Open the form for a new course.
     */
    public function create(): void
    {
        $this->resetForm();

        Flux::modal('course-form')->show();
    }

    /**
     * Open the form for an existing course.
     */
    public function edit(int $courseId): void
    {
        $course = Course::query()->findOrFail($courseId);

        $this->resetValidation();
        $this->editingId = $course->id;
        $this->code = (string) $course->code;
        $this->name = $course->name;
        $this->semester = (string) $course->semester;
        $this->type = $course->type->value;
        $this->sks = (string) $course->sks;

        Flux::modal('course-form')->show();
    }

    /**
     * Create or update the course.
     */
    public function save(): void
    {
        Gate::authorize('manage-academics');

        $this->code = Str::upper(trim($this->code));
        $this->name = trim($this->name);

        $validated = $this->validate([
            'code' => ['nullable', 'string', 'max:20', Rule::unique('courses', 'code')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:255', Rule::unique('courses', 'name')->ignore($this->editingId)],
            'semester' => ['nullable', 'integer', 'min:1', 'max:14'],
            'type' => ['required', Rule::enum(CourseType::class)],
            'sks' => ['required', 'integer', 'min:1', 'max:24'],
        ], attributes: [
            'code' => 'kode',
            'name' => 'nama mata kuliah',
            'type' => 'jenis',
        ]);

        $attributes = [
            'code' => $validated['code'] ?: null,
            'name' => $validated['name'],
            'semester' => $validated['semester'] !== null && $validated['semester'] !== '' ? (int) $validated['semester'] : null,
            'type' => $validated['type'],
            'sks' => (int) $validated['sks'],
        ];

        if ($this->editingId !== null) {
            $course = Course::query()->findOrFail($this->editingId);

            DB::transaction(function () use ($course, $attributes): void {
                $course->update($attributes);
                $course->classes()->inPeriod(AcademicPeriod::current()->id ?? 0)->update(['sks' => $attributes['sks']]);
            });

            $message = 'Mata kuliah diperbarui.';
        } else {
            Course::query()->create($attributes);
            $message = 'Mata kuliah ditambahkan.';
        }

        Flux::modal('course-form')->close();
        $this->resetForm();
        unset($this->courses);

        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Ask for confirmation before deleting a course.
     */
    public function confirmDelete(int $courseId): void
    {
        $this->deletingId = $courseId;
        unset($this->deletingCourse);

        Flux::modal('course-delete')->show();
    }

    /**
     * Delete the course together with its classes and enrollments.
     */
    public function delete(): void
    {
        Gate::authorize('manage-academics');

        Course::query()->findOrFail($this->deletingId)->delete();

        $this->deletingId = null;
        unset($this->courses);

        Flux::modal('course-delete')->close();
        Flux::toast(variant: 'success', text: 'Mata kuliah dihapus.');
    }

    /**
     * Get the course pending deletion, with its class and enrollment totals across every period.
     */
    #[Computed]
    public function deletingCourse(): ?Course
    {
        return $this->deletingId !== null
            ? Course::query()
                ->withCount('classes')
                ->addSelect(['enrollments_count' => DB::table('enrollments')
                    ->join('course_classes', 'course_classes.id', '=', 'enrollments.course_class_id')
                    ->whereColumn('course_classes.course_id', 'courses.id')
                    ->selectRaw('count(*)')])
                ->find($this->deletingId)
            : null;
    }

    /**
     * Clear the form fields.
     */
    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'code', 'name', 'semester', 'type', 'sks');
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Mata Kuliah</flux:heading>
            <flux:text class="mt-1">Kelola daftar mata kuliah wajib, pilihan, dan MK umum.</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="create">Tambah Mata Kuliah</flux:button>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" aria-label="Cari mata kuliah" placeholder="Cari nama atau kode..." class="sm:max-w-xs" />

        <flux:select wire:model.live="typeFilter" aria-label="Filter jenis mata kuliah" class="sm:max-w-48">
            <flux:select.option value="">Semua jenis</flux:select.option>
            @foreach (\App\Enums\CourseType::cases() as $courseType)
                <flux:select.option wire:key="type-filter-{{ $courseType->value }}" value="{{ $courseType->value }}">{{ $courseType->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->courses">
        <flux:table.columns>
            <flux:table.column>Kode</flux:table.column>
            <flux:table.column>Nama</flux:table.column>
            <flux:table.column>Semester</flux:table.column>
            <flux:table.column>Jenis</flux:table.column>
            <flux:table.column>SKS</flux:table.column>
            <flux:table.column>Kelas (periode aktif)</flux:table.column>
            <flux:table.column align="end">Aksi</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->courses as $course)
                <flux:table.row wire:key="course-{{ $course->id }}">
                    <flux:table.cell>{{ $course->code ?? '—' }}</flux:table.cell>
                    <flux:table.cell variant="strong" class="whitespace-normal">{{ $course->name }}</flux:table.cell>
                    <flux:table.cell>{{ $course->semester ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$course->type->color()">{{ $course->type->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ $course->sks }}</flux:table.cell>
                    <flux:table.cell>{{ $this->activePeriod ? $course->classes_count : '–' }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $course->id }})" aria-label="Ubah" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $course->id }})" aria-label="Hapus" />
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center text-zinc-500">Belum ada mata kuliah.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="course-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Ubah Mata Kuliah' : 'Tambah Mata Kuliah' }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="code" label="Kode MK" placeholder="EF234601" />
                <flux:input wire:model="semester" label="Semester" type="number" min="1" max="14" />
            </div>

            <flux:input wire:model="name" label="Nama mata kuliah" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="type" label="Jenis">
                    @foreach (\App\Enums\CourseType::cases() as $courseType)
                        <flux:select.option wire:key="type-option-{{ $courseType->value }}" value="{{ $courseType->value }}">{{ $courseType->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="sks" label="SKS" type="number" min="1" max="24" required description="Berlaku untuk kelas di periode aktif dan kelas baru. Periode lain tidak berubah." />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Simpan</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="course-delete" class="w-full max-w-md">
        <div class="space-y-5">
            <flux:heading size="lg">Hapus mata kuliah?</flux:heading>
            @if ($this->deletingCourse)
                <flux:text>
                    <strong>{{ $this->deletingCourse->name }}</strong> beserta {{ $this->deletingCourse->classes_count }} kelasnya di semua periode akan dihapus,
                    termasuk {{ (int) $this->deletingCourse->enrollments_count }} pengambilan KRS mahasiswa untuk mata kuliah ini.
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
