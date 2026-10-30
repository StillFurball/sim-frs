<?php

use App\Enums\UserRole;
use App\Models\AcademicPeriod;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Mahasiswa')] class extends Component {
    use WithPagination;

    public string $search = '';

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public ?int $viewingId = null;

    public string $krsPeriodId = '';

    public string $name = '';

    public string $email = '';

    public string $nrp = '';

    public string $maxSks = '24';

    public string $password = '';

    /**
     * Reset pagination when the search changes.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Get the active academic period, whose KRS the table summarises.
     */
    #[Computed]
    public function activePeriod(): ?AcademicPeriod
    {
        return AcademicPeriod::current();
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
     * Get the paginated students with their taken SKS and class count in the active period.
     *
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function students(): LengthAwarePaginator
    {
        $activePeriodId = $this->activePeriod?->id ?? 0;

        return User::query()
            ->where('role', UserRole::Student)
            ->select('users.*')
            ->addSelect(['taken_sks' => DB::table('enrollments')
                ->join('course_classes', 'course_classes.id', '=', 'enrollments.course_class_id')
                ->join('courses', 'courses.id', '=', 'course_classes.course_id')
                ->whereColumn('enrollments.user_id', 'users.id')
                ->where('course_classes.academic_period_id', $activePeriodId)
                ->selectRaw('coalesce(sum(courses.sks), 0)')])
            ->withCount(['courseClasses' => fn ($query) => $query->where('course_classes.academic_period_id', $activePeriodId)])
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")
                ->orWhere('nrp', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->paginate(15);
    }

    /**
     * Get the student whose KRS is being viewed, with their classes in the selected period.
     */
    #[Computed]
    public function viewingStudent(): ?User
    {
        return $this->viewingId !== null
            ? $this->studentQuery()->with(['courseClasses' => fn ($query) => $query
                ->where('course_classes.academic_period_id', (int) $this->krsPeriodId)
                ->with(['course', 'lecturers'])
                ->orderBy('day')
                ->orderBy('start_time')])->find($this->viewingId)
            : null;
    }

    /**
     * Get the period whose KRS is shown in the modal.
     */
    #[Computed]
    public function krsPeriod(): ?AcademicPeriod
    {
        return $this->krsPeriodId !== '' ? $this->periods->firstWhere('id', (int) $this->krsPeriodId) : null;
    }

    /**
     * Refresh the viewed KRS when another period is selected.
     */
    public function updatedKrsPeriodId(): void
    {
        unset($this->viewingStudent, $this->krsPeriod);
    }

    /**
     * Get the student pending deletion.
     */
    #[Computed]
    public function deletingStudent(): ?User
    {
        return $this->deletingId !== null ? $this->studentQuery()->find($this->deletingId) : null;
    }

    /**
     * Open the form for a new student.
     */
    public function create(): void
    {
        $this->resetForm();

        Flux::modal('student-form')->show();
    }

    /**
     * Open the form for an existing student.
     */
    public function edit(int $studentId): void
    {
        $student = $this->studentQuery()->findOrFail($studentId);

        $this->resetValidation();
        $this->editingId = $student->id;
        $this->name = $student->name;
        $this->email = $student->email;
        $this->nrp = (string) $student->nrp;
        $this->maxSks = (string) $student->max_sks;
        $this->password = '';

        Flux::modal('student-form')->show();
    }

    /**
     * Create or update the student.
     */
    public function save(): void
    {
        Gate::authorize('manage-academics');

        $this->name = trim($this->name);
        $this->email = trim($this->email);
        $this->nrp = trim($this->nrp);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'nrp' => ['nullable', 'string', 'max:30', Rule::unique('users', 'nrp')->ignore($this->editingId)],
            'maxSks' => ['required', 'integer', Rule::in(User::SKS_LIMITS)],
            'password' => [$this->editingId === null ? 'required' : 'nullable', 'string', Password::defaults()],
        ], [
            'maxSks.in' => 'Batas SKS harus salah satu dari '.implode(', ', User::SKS_LIMITS).'.',
        ], [
            'name' => 'nama',
            'nrp' => 'NRP',
            'maxSks' => 'batas SKS',
            'password' => 'kata sandi',
        ]);

        $attributes = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'nrp' => filled($validated['nrp']) ? $validated['nrp'] : null,
            'max_sks' => (int) $validated['maxSks'],
        ];

        if (filled($validated['password'])) {
            $attributes['password'] = $validated['password'];
        }

        if ($this->editingId !== null) {
            $student = $this->studentQuery()->findOrFail($this->editingId);
            $takenSks = $this->activePeriod !== null ? $student->takenSks($this->activePeriod) : 0;

            if ($attributes['max_sks'] < $takenSks) {
                $this->addError('maxSks', "Mahasiswa ini sudah mengambil {$takenSks} SKS pada periode {$this->activePeriod?->label()}. Lepas beberapa kelas terlebih dahulu sebelum menurunkan batas SKS.");

                return;
            }

            $student->update($attributes);
            $message = 'Data mahasiswa diperbarui.';
        } else {
            $student = new User($attributes);
            $student->role = UserRole::Student;
            $student->email_verified_at = now();
            $student->save();
            $message = 'Mahasiswa ditambahkan.';
        }

        Flux::modal('student-form')->close();
        $this->resetForm();
        unset($this->students);

        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Ask for confirmation before deleting a student.
     */
    public function confirmDelete(int $studentId): void
    {
        $this->deletingId = $studentId;
        unset($this->deletingStudent);

        Flux::modal('student-delete')->show();
    }

    /**
     * Delete the student together with their KRS.
     */
    public function delete(): void
    {
        Gate::authorize('manage-academics');

        $this->studentQuery()->findOrFail($this->deletingId)->delete();

        $this->deletingId = null;
        unset($this->students, $this->deletingStudent);

        Flux::modal('student-delete')->close();
        Flux::toast(variant: 'success', text: 'Mahasiswa dihapus.');
    }

    /**
     * Show the KRS of a student.
     */
    public function showKrs(int $studentId): void
    {
        $this->viewingId = $this->studentQuery()->findOrFail($studentId)->id;
        $period = $this->activePeriod ?? $this->periods->first();
        $this->krsPeriodId = $period !== null ? (string) $period->id : '';
        unset($this->viewingStudent, $this->krsPeriod);

        Flux::modal('student-krs')->show();
    }

    /**
     * Remove a class from the KRS of the student being viewed.
     */
    public function dropClass(int $classId): void
    {
        Gate::authorize('manage-academics');

        $student = $this->studentQuery()->findOrFail($this->viewingId);
        $student->courseClasses()->detach($classId);

        unset($this->students, $this->viewingStudent);

        Flux::toast(variant: 'success', text: 'Kelas dilepas dari KRS mahasiswa.');
    }

    /**
     * Base query restricted to student accounts.
     *
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function studentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()->where('role', UserRole::Student);
    }

    /**
     * Clear the form fields.
     */
    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name', 'email', 'nrp', 'maxSks', 'password');
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Mahasiswa</flux:heading>
            <flux:text class="mt-1">Kelola akun mahasiswa, batas SKS, dan KRS yang sudah diambil.</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="create">Tambah Mahasiswa</flux:button>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" aria-label="Cari mahasiswa" placeholder="Cari nama, email, atau NRP..." class="sm:max-w-xs" />

    <flux:table :paginate="$this->students">
        <flux:table.columns>
            <flux:table.column>Nama</flux:table.column>
            <flux:table.column>NRP</flux:table.column>
            <flux:table.column>Email</flux:table.column>
            <flux:table.column>Batas SKS</flux:table.column>
            <flux:table.column>SKS Diambil{{ $this->activePeriod ? ' ('.$this->activePeriod->label().')' : '' }}</flux:table.column>
            <flux:table.column>Kelas{{ $this->activePeriod ? ' ('.$this->activePeriod->label().')' : '' }}</flux:table.column>
            <flux:table.column align="end">Aksi</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->students as $student)
                <flux:table.row wire:key="student-{{ $student->id }}">
                    <flux:table.cell variant="strong">{{ $student->name }}</flux:table.cell>
                    <flux:table.cell>{{ $student->nrp ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $student->email }}</flux:table.cell>
                    <flux:table.cell>{{ $student->max_sks }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($this->activePeriod)
                            <flux:badge size="sm" :color="(int) $student->taken_sks > 0 ? 'green' : 'zinc'">{{ (int) $student->taken_sks }} SKS</flux:badge>
                        @else
                            <span class="text-zinc-500 dark:text-zinc-400">–</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $this->activePeriod ? $student->course_classes_count : '–' }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            <flux:button size="sm" variant="ghost" icon="calendar-days" wire:click="showKrs({{ $student->id }})" aria-label="Lihat KRS">KRS</flux:button>
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $student->id }})" aria-label="Ubah" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $student->id }})" aria-label="Hapus" />
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center text-zinc-500">Belum ada mahasiswa.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="student-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Ubah Mahasiswa' : 'Tambah Mahasiswa' }}</flux:heading>

            <flux:input wire:model="name" label="Nama" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="nrp" label="NRP" placeholder="5025231001" />
                <flux:select wire:model="maxSks" label="Batas SKS">
                    @foreach (\App\Models\User::SKS_LIMITS as $limit)
                        <flux:select.option wire:key="sks-limit-{{ $limit }}" value="{{ $limit }}">{{ $limit }} SKS</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input wire:model="email" label="Email" type="email" required />

            <flux:input
                wire:model="password"
                label="Kata sandi"
                type="password"
                viewable
                :description="$editingId ? 'Kosongkan jika tidak ingin mengubah kata sandi.' : null"
                :required="! $editingId"
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Simpan</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="student-krs" class="w-full max-w-5xl">
        <div class="space-y-5">
            @if ($this->viewingStudent)
                @php($viewingClasses = $this->viewingStudent->courseClasses)
                <div class="flex flex-wrap items-start justify-between gap-4 pe-8">
                    <div>
                        <flux:heading size="lg">KRS {{ $this->viewingStudent->name }}</flux:heading>
                        <flux:text class="mt-1">
                            {{ $viewingClasses->sum(fn ($class) => $class->course->sks) }} / {{ $this->viewingStudent->max_sks }} SKS · {{ $viewingClasses->count() }} kelas
                        </flux:text>
                    </div>

                    @if ($this->periods->isNotEmpty())
                        <flux:select wire:model.live="krsPeriodId" aria-label="Periode KRS" class="sm:max-w-56" data-test="krs-period-select">
                            @foreach ($this->periods as $period)
                                <flux:select.option wire:key="krs-period-{{ $period->id }}" value="{{ $period->id }}">{{ $period->label() }}{{ $period->is_active ? ' (aktif)' : '' }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                </div>

                <x-schedule-grid :classes="$viewingClasses" :text-alternative="false" :empty-text="'Mahasiswa ini belum mengambil kelas'.($this->krsPeriod ? ' pada periode '.$this->krsPeriod->label() : '').'.'" />

                @if ($viewingClasses->isNotEmpty())
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Mata Kuliah</flux:table.column>
                            <flux:table.column>Kelas</flux:table.column>
                            <flux:table.column>Jadwal</flux:table.column>
                            <flux:table.column>SKS</flux:table.column>
                            <flux:table.column align="end"></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($viewingClasses as $class)
                                <flux:table.row wire:key="krs-class-{{ $class->id }}">
                                    <flux:table.cell class="whitespace-normal">{{ $class->course->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $class->label() }}</flux:table.cell>
                                    <flux:table.cell>{{ $class->scheduleLabel() }}</flux:table.cell>
                                    <flux:table.cell>{{ $class->course->sks }}</flux:table.cell>
                                    <flux:table.cell align="end">
                                        <flux:button size="sm" variant="danger" icon="x-mark" wire:click="dropClass({{ $class->id }})">Lepas</flux:button>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            @endif
        </div>
    </flux:modal>

    <flux:modal name="student-delete" class="w-full max-w-md">
        <div class="space-y-5">
            <flux:heading size="lg">Hapus mahasiswa?</flux:heading>
            @if ($this->deletingStudent)
                <flux:text>
                    Akun <strong>{{ $this->deletingStudent->name }}</strong> beserta seluruh KRS-nya akan dihapus permanen.
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
