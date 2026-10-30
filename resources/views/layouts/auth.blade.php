@props([
    'title' => null,
    'heading',
    'lead',
])

@php
    $period = \App\Models\AcademicPeriod::current();
    $onRegister = request()->routeIs('register');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>{{ filled($title) ? $title.' · '.config('app.name', 'KRS') : config('app.name', 'KRS') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Big+Shoulders:wght@800&family=Public+Sans:wght@400;600&display=swap" rel="stylesheet">
        @vite(['resources/css/landing.css', 'resources/css/auth.css', 'resources/js/landing.js'])
    </head>
    <body class="auth">
        <img class="auth__photo" src="{{ asset('images/landing/its-library.jpg') }}" width="1920" height="960" alt="Gedung Perpustakaan ITS di malam hari, jendela-jendelanya menyala kuning">

        <header class="auth__bar">
            <a class="wordmark" href="{{ route('home') }}">KRS Informatika</a>
            @if ($onRegister)
                <a class="button button--paper" href="{{ route('login') }}">{{ __('Log in') }}</a>
            @elseif (Route::has('register'))
                <a class="button button--paper" href="{{ route('register') }}">{{ __('Create account') }}</a>
            @endif
        </header>

        <main class="auth__main">
            <div class="auth__intro">
                <h1 class="auth__title">{{ $heading }}</h1>
                <p class="auth__lead">{{ $lead }}</p>
                <p class="auth__period">
                    @if ($period)
                        <span>{{ __('Active period') }}</span>
                        <span class="diamond" aria-hidden="true"></span>
                        <span>{{ __('Semester') }} {{ $period->label() }}</span>
                    @else
                        <span>{{ __('No active period yet') }}</span>
                    @endif
                </p>
            </div>

            <section class="auth__card">
                <span class="card__scallop card__scallop--tl" aria-hidden="true"></span>
                <span class="card__scallop card__scallop--tr" aria-hidden="true"></span>
                <span class="card__scallop card__scallop--bl" aria-hidden="true"></span>
                <span class="card__scallop card__scallop--br" aria-hidden="true"></span>
                {{ $slot }}
            </section>
        </main>

        <footer class="auth__imprint">
            {{ __('Photo') }}: <a href="https://commons.wikimedia.org/wiki/File:ITS_Library.jpg">ITS Library</a>, Institut Teknologi Sepuluh Nopember, CC BY-SA 4.0, via Wikimedia Commons.
        </footer>
    </body>
</html>
