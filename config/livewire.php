<?php

// Solo lo que se cambia de Livewire; el resto queda con los valores de fábrica.
// (Livewire mezcla solo las claves de primer nivel: por eso va el bloque completo de temporary_file_upload.)
return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        // listas de hasta 50 MB (el Excel de Brancat pesa 30 MB; el valor de fábrica es 12 MB)
        'rules' => ['required', 'file', 'max:51200'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 10,
        'cleanup' => true,
    ],
];
