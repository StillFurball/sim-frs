<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group heading="Menu" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        Dashboard
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @can('take-courses')
                    <flux:sidebar.group heading="KRS" class="grid">
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('krs.index')" :current="request()->routeIs('krs.index')" wire:navigate>
                            Isi KRS
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="sparkles" :href="route('krs.planner')" :current="request()->routeIs('krs.planner')" wire:navigate>
                            Rencana Otomatis
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

                @can('manage-academics')
                    <flux:sidebar.group heading="Admin" class="grid">
                        <flux:sidebar.item icon="calendar" :href="route('admin.periods')" :current="request()->routeIs('admin.periods')" wire:navigate>
                            Periode Akademik
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="book-open" :href="route('admin.courses')" :current="request()->routeIs('admin.courses')" wire:navigate>
                            Mata Kuliah
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="calendar-days" :href="route('admin.classes')" :current="request()->routeIs('admin.classes')" wire:navigate>
                            Kelas & Jadwal
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="academic-cap" :href="route('admin.lecturers')" :current="request()->routeIs('admin.lecturers')" wire:navigate>
                            Dosen
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="users" :href="route('admin.students')" :current="request()->routeIs('admin.students')" wire:navigate>
                            Mahasiswa
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="arrow-up-tray" :href="route('admin.import')" :current="request()->routeIs('admin.import')" wire:navigate>
                            Import Jadwal
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
