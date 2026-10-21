<#
.SYNOPSIS
Stops and removes the scheduler Windows service created by install-scheduler-service.ps1.

.DESCRIPTION
Does nothing when the service does not exist. Run from an elevated PowerShell.

.PARAMETER ServiceName
Name of the Windows service. Default: TicketingScheduler.

.PARAMETER NssmPath
Path to nssm.exe, or "nssm" when it is on PATH. Default: nssm.

.EXAMPLE
.\uninstall-scheduler-service.ps1
#>
[CmdletBinding()]
param(
    [string]$ServiceName = 'TicketingScheduler',

    [string]$NssmPath = 'nssm'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

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

    $text = ($output -replace "`0", '').Trim()

    if ($exitCode -ne 0) {
        throw "nssm $($Arguments -join ' ') failed with exit code $exitCode. $text"
    }

    return $text
}

$existing = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue

if (-not $existing) {
    Write-Host "Service '$ServiceName' is not installed. Nothing to do."
    return
}

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not (New-Object Security.Principal.WindowsPrincipal($identity)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script from an elevated (Administrator) PowerShell.'
}

$script:NssmExe = Resolve-NssmExecutable -Value $NssmPath

if ($existing.Status -ne 'Stopped') {
    Write-Host "Stopping '$ServiceName'."
    Invoke-Nssm -Arguments @('stop', $ServiceName) | Out-Null
}

Write-Host "Removing '$ServiceName'."
Invoke-Nssm -Arguments @('remove', $ServiceName, 'confirm') | Out-Null

Write-Host "Service '$ServiceName' removed."
