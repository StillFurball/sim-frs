<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Indonesian messages for the rules this application uses. Rules that are not
    | listed here fall back to the English messages (APP_FALLBACK_LOCALE).
    |
    */

    'accepted' => ':Attribute harus diterima.',
    'after' => ':Attribute harus setelah :date.',
    'after_or_equal' => ':Attribute harus sama dengan atau setelah :date.',
    'array' => ':Attribute harus berupa daftar.',
    'before' => ':Attribute harus sebelum :date.',
    'boolean' => ':Attribute harus bernilai benar atau salah.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Kata sandi salah.',
    'date' => ':Attribute bukan tanggal yang valid.',
    'date_format' => ':Attribute harus berformat :format.',
    'different' => ':Attribute dan :other harus berbeda.',
    'distinct' => ':Attribute memiliki nilai ganda.',
    'email' => ':Attribute harus berupa alamat email yang valid.',
    'enum' => ':Attribute yang dipilih tidak valid.',
    'exists' => ':Attribute yang dipilih tidak valid.',
    'extensions' => ':Attribute harus berekstensi: :values.',
    'file' => ':Attribute harus berupa file.',
    'filled' => ':Attribute wajib diisi.',
    'in' => ':Attribute yang dipilih tidak valid.',
    'integer' => ':Attribute harus berupa bilangan bulat.',
    'lowercase' => ':Attribute harus huruf kecil.',
    'max' => [
        'array' => ':Attribute tidak boleh lebih dari :max item.',
        'file' => ':Attribute tidak boleh lebih dari :max kilobyte.',
        'numeric' => ':Attribute tidak boleh lebih dari :max.',
        'string' => ':Attribute tidak boleh lebih dari :max karakter.',
    ],
    'mimes' => ':Attribute harus berupa file bertipe: :values.',
    'min' => [
        'array' => ':Attribute minimal berisi :min item.',
        'file' => ':Attribute minimal :min kilobyte.',
        'numeric' => ':Attribute minimal :min.',
        'string' => ':Attribute minimal :min karakter.',
    ],
    'numeric' => ':Attribute harus berupa angka.',
    'password' => [
        'letters' => ':Attribute harus mengandung minimal satu huruf.',
        'mixed' => ':Attribute harus mengandung minimal satu huruf besar dan satu huruf kecil.',
        'numbers' => ':Attribute harus mengandung minimal satu angka.',
        'symbols' => ':Attribute harus mengandung minimal satu simbol.',
        'uncompromised' => ':Attribute ini pernah bocor dalam kebocoran data. Pilih :attribute lain.',
    ],
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':Attribute wajib diisi.',
    'same' => ':Attribute dan :other harus sama.',
    'string' => ':Attribute harus berupa teks.',
    'unique' => ':Attribute sudah digunakan.',
    'uploaded' => ':Attribute gagal diunggah.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    */

    'attributes' => [
        'name' => 'nama',
        'email' => 'email',
        'password' => 'kata sandi',
        'current_password' => 'kata sandi saat ini',
        'password_confirmation' => 'konfirmasi kata sandi',
    ],

];
