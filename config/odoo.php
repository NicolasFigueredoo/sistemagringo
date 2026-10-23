<?php

return [
    'url'     => env('ODOO_URL', 'https://ferreteriatiogringo.odoo.com'),
    'db'      => env('ODOO_DB', 'ferreteriatiogringo'),
    'user'    => env('ODOO_USER'),
    'api_key' => env('ODOO_API_KEY'),

    // ruta al ejecutable pdftotext (poppler)
    'pdftotext' => env('PDFTOTEXT_PATH', 'pdftotext'),
];
