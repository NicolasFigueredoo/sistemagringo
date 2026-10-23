<?php

return [
    'url'     => env('ODOO_URL', 'https://ferreteriatiogringo.odoo.com'),
    'db'      => env('ODOO_DB', 'ferreteriatiogringo'),
    'user'    => env('ODOO_USER'),
    'api_key' => env('ODOO_API_KEY'),

    // ubicación donde se suma el stock que ingresa (vacío = "Existencias" del primer depósito)
    'stock_location_id' => env('ODOO_STOCK_LOCATION_ID'),

    // ruta al ejecutable pdftotext (poppler)
    'pdftotext' => env('PDFTOTEXT_PATH', 'pdftotext'),
];
