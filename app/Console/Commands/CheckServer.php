<?php

namespace App\Console\Commands;

use App\Services\PhpRequirements;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tickets:check-server')]
#[Description('Check the PHP extensions, writable folders and settings the Ticketing System needs on this server')]
class CheckServer extends Command
{
    /**
     * Fails (exit code 1) when an extension is missing or a folder is not writable;
     * a development setting in production is only a warning.
     */
    public function handle(PhpRequirements $requirements): int
    {
        $failed = false;

        $this->line('PHP '.PHP_VERSION.' ('.PHP_SAPI.'), php.ini: '.(php_ini_loaded_file() ?: 'none'));
        $this->line('The web server may use a different PHP than this command line. Check it with php --ini for the PHP that serves the site.');
        $this->newLine();

        $missing = $requirements->missing(PhpRequirements::ALL);
        foreach (PhpRequirements::ALL as $extension) {
            $this->line(sprintf('  %-12s %s', $extension, in_array($extension, $missing, true) ? '<fg=red>MISSING</>' : 'ok'));
        }

        if ($missing !== []) {
            $failed = true;
            $this->error('Missing PHP extension(s): '.implode(', ', $missing).'. Enable them in php.ini (extension=NAME), then restart PHP and the web server.');
        }

        $this->newLine();
        foreach ([storage_path('logs'), storage_path('framework'.DIRECTORY_SEPARATOR.'cache'), base_path('bootstrap'.DIRECTORY_SEPARATOR.'cache')] as $folder) {
            $writable = is_dir($folder) && is_writable($folder);
            $this->line(sprintf('  %-40s %s', str_replace(base_path().DIRECTORY_SEPARATOR, '', $folder), $writable ? 'writable' : '<fg=red>NOT WRITABLE</>'));
            $failed = $failed || ! $writable;
        }

        $this->newLine();
        if (! app()->environment('production')) {
            $this->warn('APP_ENV is "'.app()->environment().'". On the live server it should be production.');
        }
        if (config('app.debug')) {
            $this->warn('APP_DEBUG is true. On the live server it should be false.');
        }
        if (str_ends_with((string) config('tickets.requestor_portal_url'), '.invalid')) {
            $this->warn('REQUESTOR_PORTAL_URL is not set, so confirmation links will not work.');
        }

        $failed ? $this->error('The server check found problems.') : $this->info('The server check passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
