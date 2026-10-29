<?php

namespace App\Services;

/**
 * What the server's PHP must provide for the features that build files. The
 * checks live here so a missing extension gives a clear message instead of a
 * library error, and so tests can stand in for a server without it.
 */
class PhpRequirements
{
    /** @var list<string> job order PDF (dompdf) */
    public const PDF = ['gd', 'mbstring', 'dom'];

    /** @var list<string> job order Excel workbook (zip archive patching) */
    public const WORKBOOK = ['zip', 'dom'];

    /** @var list<string> consolidated report Excel export (phpspreadsheet) */
    public const REPORT_EXPORT = ['gd', 'zip', 'mbstring', 'xml', 'xmlwriter'];

    /** @var list<string> everything the application needs, as shown by tickets:check-server */
    public const ALL = ['gd', 'mbstring', 'dom', 'zip', 'fileinfo', 'xml', 'xmlwriter', 'simplexml', 'libxml', 'openssl', 'pdo_mysql'];

    /**
     * @param  list<string>  $extensions
     * @return list<string> the extensions that are not loaded
     */
    public function missing(array $extensions): array
    {
        return array_values(array_filter($extensions, fn (string $name) => ! extension_loaded($name)));
    }
}
