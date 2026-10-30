@php
    $passwordRules = \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString();
    $passwordNote = app()->isProduction() ? __('At least 12 characters, with upper and lower case letters, a number and a symbol.') : null;
@endphp

<x-layouts::auth
    :title="__('Register')"
    :heading="__('Start your card')"
    :lead="__('Create a student account to try your KRS against the real class schedule before registration day.')"
>
    <h2 class="auth__card-title">{{ __('Create an account') }}</h2>

    <form method="POST" action="{{ route('register.store') }}" class="auth__form">
        @csrf

        <x-auth.field
            name="name"
            :label="__('Full name')"
            :value="old('name')"
            required
            autofocus
            autocomplete="name"
        />

        <x-auth.field
            name="email"
            :label="__('Email address')"
            type="email"
            :value="old('email')"
            required
            autocomplete="email"
            placeholder="nama@student.its.ac.id"
        />

        <x-auth.field
            name="password"
            :label="__('Password')"
            type="password"
            :note="$passwordNote"
            required
            autocomplete="new-password"
            :passwordrules="$passwordRules"
            viewable
        />

        <x-auth.field
            name="password_confirmation"
            :label="__('Confirm password')"
            type="password"
            required
            autocomplete="new-password"
            :passwordrules="$passwordRules"
            viewable
        />

        <button type="submit" class="button button--ink button--bar" data-test="register-user-button">{{ __('Create account') }}</button>
    </form>

    <p class="auth__switch">
        {{ __('Already have an account?') }}
        <a class="text-link" href="{{ route('login') }}">{{ __('Log in') }}</a>
    </p>
</x-layouts::auth>
