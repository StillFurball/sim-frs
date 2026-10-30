@php
    $passwordRules = \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString();
    $passwordNote = app()->isProduction() ? __('At least 12 characters, with upper and lower case letters, a number and a symbol.') : null;
@endphp

<x-layouts::auth
    :title="__('Reset password')"
    :heading="__('New password')"
    :lead="__('Choose a new password for your account. You will use it the next time you log in.')"
>
    <h2 class="auth__card-title">{{ __('Reset password') }}</h2>

    <form method="POST" action="{{ route('password.update') }}" class="auth__form">
        @csrf
        <input type="hidden" name="token" value="{{ request()->route('token') }}">

        <x-auth.field
            name="email"
            :label="__('Email address')"
            type="email"
            :value="old('email', request('email'))"
            required
            autocomplete="email"
        />

        <x-auth.field
            name="password"
            :label="__('New password')"
            type="password"
            :note="$passwordNote"
            required
            autofocus
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

        <button type="submit" class="button button--ink button--bar" data-test="reset-password-button">{{ __('Reset password') }}</button>
    </form>
</x-layouts::auth>
