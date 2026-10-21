<#
.SYNOPSIS
Installs (or updates) a Windows service that runs the Laravel scheduler through NSSM.

.DESCRIPTION
Runs "php artisan schedule:work" as an auto-start service, with rotated stdout/stderr logs
under storage\logs and automatic restart on exit. Safe to run again: when the service already
exists its settings are updated instead of reinstalling it. Run from an elevated PowerShell.

.PARAMETER PhpPath
Full path to php.exe, e.g. "C:\php\php.exe".

.PARAMETER AppPath
Full path to the application root (the folder that contains "artisan").

.PARAMETER ServiceName
Name of the Windows service. Default: TicketingScheduler.

.PARAMETER NssmPath
Path to nssm.exe, or "nssm" when it is on PATH. Default: nssm.

.PARAMETER StartService
Start the service when the script finishes (installs) or restart it (updates).

.EXAMPLE
.\install-scheduler-service.ps1 -PhpPath "C:\php\php.exe" -AppPath "C:\nginx\www\Ticketing System\TicketingSystem-API" -StartService
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$PhpPath,

    [Parameter(Mandatory = $true)]
    [string]$AppPath,

    [string]$ServiceName = 'TicketingScheduler',

    [string]$NssmPath = 'nssm',

    [switch]$StartService
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Resolve-ExistingPath {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Label,
        [ValidateSet('Leaf', 'Container')][string]$Type
    )

    if (-not (Test-Path -LiteralPath $Path -PathType $Type)) {
        throw "$Label not found: $Path"
    }

    # Drop a trailing slash: a quoted argument ending in a backslash would escape its closing quote.
    return (Resolve-Path -LiteralPath $Path).ProviderPath.TrimEnd('\', '/')
}

function Resolve-NssmExecutable {
    param([Parameter(Mandatory = $true)][string]$Value)

    if (Test-Path -LiteralPath $Value -PathType Leaf) {
        return (Resolve-Path -LiteralPath $Value).ProviderPath
    }

    $command = Get-Command -Name $Value -CommandType Application -ErrorAction SilentlyContinue
    if ($command) {
        return $command.Source
    }

    throw "nssm not found: $Value (pass -NssmPath with the full path to nssm.exe)"
}

function Invoke-Nssm {
    param([Parameter(Mandatory = $true)][string[]]$Arguments)

    # Windows PowerShell 5.1 turns native stderr into terminating errors under 'Stop'; the exit code is checked instead.
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $script:NssmExe @Arguments 2>&1 | Out-String
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previous
    }

    # nssm writes UTF-16, which can leave NUL characters in the captured text.
    $text = ($output -replace "`0", '').Trim()

    if ($exitCode -ne 0) {
        throw "nssm $($Arguments -join ' ') failed with exit code $exitCode. $text"
    }

    return $text
}

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not (New-Object Security.Principal.WindowsPrincipal($identity)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script from an elevated (Administrator) PowerShell.'
}

$php = Resolve-ExistingPath -Path $PhpPath -Label 'php.exe' -Type Leaf
$app = Resolve-ExistingPath -Path $AppPath -Label 'Application folder' -Type Container
$null = Resolve-ExistingPath -Path (Join-Path $app 'artisan') -Label 'artisan (is -AppPath the application root?)' -Type Leaf
$script:NssmExe = Resolve-NssmExecutable -Value $NssmPath

$logDirectory = Join-Path $app 'storage\logs'
if (-not (Test-Path -LiteralPath $logDirectory -PathType Container)) {
    New-Item -ItemType Directory -Path $logDirectory | Out-Null
    Write-Host "Created $logDirectory"
}

$stdoutLog = Join-Path $logDirectory 'scheduler-out.log'
$stderrLog = Join-Path $logDirectory 'scheduler-err.log'

$existing = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue

if ($existing) {
    Write-Host "Service '$ServiceName' already exists (status: $($existing.Status)). Updating its settings."
    Invoke-Nssm -Arguments @('set', $ServiceName, 'Application', $php) | Out-Null
}
else {
    Write-Host "Installing service '$ServiceName'."
    Invoke-Nssm -Arguments @('install', $ServiceName, $php) | Out-Null
}

$settings = @(
    , @('AppDirectory', $app)
    , @('AppParameters', 'artisan schedule:work')
    , @('DisplayName', 'Ticketing System Scheduler')
    , @('Description', 'Runs the Laravel scheduler (php artisan schedule:work) for the Ticketing System API.')
    , @('Start', 'SERVICE_AUTO_START')
    , @('AppStdout', $stdoutLog)
    , @('AppStderr', $stderrLog)
    , @('AppRotateFiles', '1')
    , @('AppRotateOnline', '1')
    , @('AppRotateBytes', '10485760')
    , @('AppExit', 'Default', 'Restart')
    , @('AppRestartDelay', '5000')
)

foreach ($setting in $settings) {
    Invoke-Nssm -Arguments (@('set', $ServiceName) + $setting) | Out-Null
    Write-Host ("  set {0} = {1}" -f $setting[0], ($setting[1..($setting.Count - 1)] -join ' '))
}

if ($StartService) {
    if ($existing -and $existing.Status -ne 'Stopped') {
        Write-Host "Restarting '$ServiceName' so the new settings apply."
        Invoke-Nssm -Arguments @('restart', $ServiceName) | Out-Null
    }
    else {
        Write-Host "Starting '$ServiceName'."
        Invoke-Nssm -Arguments @('start', $ServiceName) | Out-Null
    }
}
elseif ($existing) {
    Write-Host "Settings updated. Run 'nssm restart $ServiceName' for them to take effect."
}
else {
    Write-Host "Installed. Start it with 'nssm start $ServiceName' (it also starts automatically at boot)."
}

Write-Host "Logs: $stdoutLog and $stderrLog"
