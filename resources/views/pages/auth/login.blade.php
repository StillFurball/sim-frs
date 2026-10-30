<x-layouts::auth
    :title="__('Log in')"
    :heading="__('Back to your card')"
    :lead="__('Log in to fill in your KRS, check for clashes and run the automatic planner for this semester.')"
>
    <h2 class="auth__card-title">{{ __('Log in') }}</h2>

    @if (session('status'))
        <p class="auth__status" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="auth__form">
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

        <x-auth.field
            name="password"
            :label="__('Password')"
            type="password"
            required
            autocomplete="current-password"
            viewable
        />

        <div class="auth__row">
            <label class="check">
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                <span>{{ __('Remember me') }}</span>
            </label>

            @if (Route::has('password.request'))
                <a class="text-link" href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
            @endif
        </div>

        <button type="submit" class="button button--ink button--bar" data-test="login-button">{{ __('Log in') }}</button>
    </form>

    @if (Route::has('register'))
        <p class="auth__switch">
            {{ __('Don\'t have an account?') }}
            <a class="text-link" href="{{ route('register') }}">{{ __('Sign up') }}</a>
        </p>
    @endif
</x-layouts::auth>
