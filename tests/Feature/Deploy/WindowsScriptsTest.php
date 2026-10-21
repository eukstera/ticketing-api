<?php

namespace Tests\Feature\Deploy;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WindowsScriptsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function scripts(): array
    {
        return [
            'install' => ['deploy/windows/install-scheduler-service.ps1'],
            'uninstall' => ['deploy/windows/uninstall-scheduler-service.ps1'],
        ];
    }

    #[DataProvider('scripts')]
    public function test_script_has_no_powershell_syntax_errors(string $relativePath): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('The PowerShell parser is only checked on Windows.');
        }

        $path = base_path($relativePath);
        $this->assertFileExists($path);

        // Parses the script without running it.
        $process = new Process([
            'powershell', '-NoProfile', '-NonInteractive', '-Command',
            '$errors = $null; $tokens = $null; '
            .'[void][System.Management.Automation.Language.Parser]::ParseFile($env:SCRIPT_PATH, [ref]$tokens, [ref]$errors); '
            .'if ($errors.Count -gt 0) { $errors | ForEach-Object { $_.Message }; exit 1 }',
        ], null, ['SCRIPT_PATH' => $path]);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }
}
