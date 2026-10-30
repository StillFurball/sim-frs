<?php

use App\Models\Lecturer;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Dosen')] class extends Component {
    use WithPagination;

    public string $search = '';

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $code = '';

    public string $name = '';

    /**
     * Reset pagination when the search changes.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Get the paginated, filtered lecturers.
     *
     * @return LengthAwarePaginator<int, Lecturer>
     */
    #[Computed]
    public function lecturers(): LengthAwarePaginator
    {
        return Lecturer::query()
            ->withCount('classes')
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('code', 'like', "%{$this->search}%")
                ->orWhere('name', 'like', "%{$this->search}%")))
            ->orderBy('code')
            ->paginate(20);
    }

    /**
     * Get the lecturer pending deletion.
     */
    #[Computed]
    public function deletingLecturer(): ?Lecturer
    {
        return $this->deletingId !== null ? Lecturer::query()->withCount('classes')->find($this->deletingId) : null;
    }

    /**
     * Open the form for a new lecturer.
     */
    public function create(): void
    {
        $this->resetForm();

        Flux::modal('lecturer-form')->show();
    }

    /**
     * Open the form for an existing lecturer.
     */
    public function edit(int $lecturerId): void
    {
        $lecturer = Lecturer::query()->findOrFail($lecturerId);

        $this->resetValidation();
        $this->editingId = $lecturer->id;
        $this->code = $lecturer->code;
        $this->name = (string) $lecturer->name;

        Flux::modal('lecturer-form')->show();
    }

    /**
     * Create or update the lecturer.
     */
    public function save(): void
    {
        Gate::authorize('manage-academics');

        $this->code = trim($this->code);
        $this->name = trim($this->name);

        $validated = $this->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('lecturers', 'code')->ignore($this->editingId)],
            'name' => ['nullable', 'string', 'max:255'],
        ], [
            'code.unique' => 'Kode dosen sudah digunakan.',
        ], [
            'code' => 'kode dosen',
            'name' => 'nama dosen',
        ]);

        $attributes = [
            'code' => $validated['code'],
            'name' => filled($validated['name']) ? $validated['name'] : null,
        ];

        if ($this->editingId !== null) {
            Lecturer::query()->findOrFail($this->editingId)->update($attributes);
            $message = 'Data dosen diperbarui.';
        } else {
            Lecturer::query()->create($attributes);
            $message = 'Dosen ditambahkan.';
        }

        Flux::modal('lecturer-form')->close();
        $this->resetForm();
        unset($this->lecturers);

        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Ask for confirmation before deleting a lecturer.
     */
    public function confirmDelete(int $lecturerId): void
    {
        $this->deletingId = $lecturerId;
        unset($this->deletingLecturer);

        Flux::modal('lecturer-delete')->show();
    }

    /**
     * Delete the lecturer and detach them from their classes.
     */
    public function delete(): void
    {
        Gate::authorize('manage-academics');

        Lecturer::query()->findOrFail($this->deletingId)->delete();

        $this->deletingId = null;
        unset($this->lecturers, $this->deletingLecturer);

        Flux::modal('lecturer-delete')->close();
        Flux::toast(variant: 'success', text: 'Dosen dihapus.');
    }

    /**
     * Clear the form fields.
     */
    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'code', 'name');
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Dosen</flux:heading>
            <flux:text class="mt-1">Kode dosen dipakai pada kelas dan pada filter "hindari dosen" di rencana otomatis.</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="create">Tambah Dosen</flux:button>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" aria-label="Cari dosen" placeholder="Cari kode atau nama..." class="sm:max-w-xs" />

    <flux:table :paginate="$this->lecturers">
        <flux:table.columns>
            <flux:table.column>Kode</flux:table.column>
            <flux:table.column>Nama</flux:table.column>
            <flux:table.column>Jumlah Kelas</flux:table.column>
            <flux:table.column align="end">Aksi</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->lecturers as $lecturer)
                <flux:table.row wire:key="lecturer-{{ $lecturer->id }}">
                    <flux:table.cell variant="strong">{{ $lecturer->code }}</flux:table.cell>
                    <flux:table.cell>{{ $lecturer->name ?? '—' }}</flux:table.cell>
                    <flux:table.cell>{{ $lecturer->classes_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $lecturer->id }})" aria-label="Ubah" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $lecturer->id }})" aria-label="Hapus" />
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="text-center text-zinc-500">Belum ada dosen.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="lecturer-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Ubah Dosen' : 'Tambah Dosen' }}</flux:heading>

            <flux:input wire:model="code" label="Kode dosen" placeholder="BN" required />
            <flux:input wire:model="name" label="Nama dosen" placeholder="Nama lengkap (opsional)" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Simpan</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="lecturer-delete" class="w-full max-w-md">
        <div class="space-y-5">
            <flux:heading size="lg">Hapus dosen?</flux:heading>
            @if ($this->deletingLecturer)
                <flux:text>
                    Dosen <strong>{{ $this->deletingLecturer->displayName() }}</strong> akan dihapus dan dilepas dari {{ $this->deletingLecturer->classes_count }} kelas. Kelasnya sendiri tidak ikut terhapus.
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
