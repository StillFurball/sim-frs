<x-layouts::auth
    :title="__('Forgot password')"
    :heading="__('Forgot password')"
    :lead="__('We will email you a link to choose a new password. Your card stays exactly as you left it.')"
>
    <h2 class="auth__card-title">{{ __('Your email') }}</h2>

    @if (session('status'))
        <p class="auth__status" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="auth__form">
        @csrf

        <x-auth.field
            name="email"
            :label="__('Email address')"
            type="email"
            :value="old('email')"
            required
            autofocus
            autocomplete="email"
            placeholder="nama@student.its.ac.id"
        />

        <button type="submit" class="button button--ink button--bar" data-test="email-password-reset-link-button">{{ __('Email password reset link') }}</button>
    </form>

    <p class="auth__switch">
        {{ __('Or, return to') }}
        <a class="text-link" href="{{ route('login') }}">{{ __('log in') }}</a>
    </p>
</x-layouts::auth>
