<?php

return [
    'required' => 'Falta completar :attribute.',
    'file'     => ':attribute tiene que ser un archivo.',
    'uploaded' => 'No se pudo subir :attribute: puede ser muy pesado o se cortó la conexión.',
    'mimes'    => ':attribute tiene que ser de tipo: :values.',
    'mimetypes' => ':attribute tiene que ser de tipo: :values.',
    'image'    => ':attribute tiene que ser una imagen.',
    'numeric'  => ':attribute tiene que ser un número.',
    'max' => [
        'file'    => ':attribute no puede pesar más de :max KB.',
        'numeric' => ':attribute no puede ser mayor a :max.',
        'string'  => ':attribute no puede tener más de :max caracteres.',
        'array'   => 'No se pueden subir más de :max archivos.',
    ],
    'min' => [
        'file'    => ':attribute tiene que pesar al menos :min KB.',
        'numeric' => ':attribute tiene que ser al menos :min.',
        'string'  => ':attribute tiene que tener al menos :min caracteres.',
        'array'   => 'Hace falta al menos :min archivos.',
    ],

    'attributes' => [
        'archivo'   => 'el archivo',
        'nuevas'    => 'el archivo',
        'nuevas.*'  => 'el archivo',
        'files.*'   => 'el archivo',
    ],
];
