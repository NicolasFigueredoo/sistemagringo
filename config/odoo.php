<?php

return [
    'url'     => env('ODOO_URL', 'https://ferreteriatiogringo.odoo.com'),
    'db'      => env('ODOO_DB', 'ferreteriatiogringo'),
    'user'    => env('ODOO_USER'),
    'api_key' => env('ODOO_API_KEY'),

    // ubicación donde se suma el stock que ingresa (vacío = "Existencias" del primer depósito)
    'stock_location_id' => env('ODOO_STOCK_LOCATION_ID'),

    // Odoo viejo: solo para cargar facturas por foto (pantallas /viejo)
    'viejo' => [
        'url'     => env('ODOO_VIEJO_URL', 'https://ferreteriatiogringo.odoo.com'),
        'db'      => env('ODOO_VIEJO_DB', 'ferreteriatiogringo'),
        'user'    => env('ODOO_VIEJO_USER'),
        'api_key' => env('ODOO_VIEJO_API_KEY'),
        'stock_location_id' => env('ODOO_VIEJO_STOCK_LOCATION_ID'),
    ],

    // ruta al ejecutable pdftotext (poppler)
    'pdftotext' => env('PDFTOTEXT_PATH', 'pdftotext'),
];
