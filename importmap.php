<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    'sql-editor' => ['path' => './assets/sql-editor.js'],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    'bootstrap' => ['version' => '5.3.9'],
    'bootstrap/dist/css/bootstrap.min.css' => ['version' => '5.3.9', 'type' => 'css'],
    '@fortawesome/fontawesome-free/css/all.min.css' => ['version' => '7.3.1', 'type' => 'css'],
    'jquery' => ['version' => '4.1.0'],
    'datatables.net-bs5' => ['version' => '3.1.6'],
    'datatables.net-bs5/css/dataTables.bootstrap5.min.css' => ['version' => '3.1.6', 'type' => 'css'],
    'sweetalert2/dist/sweetalert2.min.css' => ['version' => '11.26.26', 'type' => 'css'],
    'altcha' => ['version' => '2.3.0'],
    'datatables.net-plugins/i18n/it-IT.mjs' => ['version' => '3.1.5'],
    '@popperjs/core' => ['version' => '2.11.8'],
    'datatables.net' => ['version' => '3.1.6'],
    'sweetalert2/dist/sweetalert2.esm.js' => ['version' => '11.26.26'],
    'codemirror' => ['version' => '6.0.2'],
    '@codemirror/lang-sql' => ['version' => '6.10.0'],
    '@codemirror/view' => ['version' => '6.44.2'],
    '@codemirror/state' => ['version' => '6.7.6'],
    '@codemirror/autocomplete' => ['version' => '6.20.4'],
    '@codemirror/language' => ['version' => '6.13.1'],
    '@codemirror/commands' => ['version' => '6.11.2'],
    '@codemirror/search' => ['version' => '6.7.3'],
    '@codemirror/lint' => ['version' => '6.9.8'],
    '@lezer/highlight' => ['version' => '1.2.5'],
    '@lezer/lr' => ['version' => '1.4.13'],
    'style-mod' => ['version' => '4.1.5'],
    'w3c-keyname' => ['version' => '2.2.8'],
    'crelt' => ['version' => '1.0.7'],
    '@marijn/find-cluster-break' => ['version' => '1.0.4'],
    '@lezer/common' => ['version' => '1.5.7'],
    '@codemirror/streamparser' => ['version' => '6.0.0'],
];
