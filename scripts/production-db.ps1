#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Migrate', 'Seed')]
    [string]$Action,

    [ValidatePattern('^(Database\\Seeders\\)?[A-Za-z_][A-Za-z0-9_]*$')]
    [string]$Seeder,

    [string]$EnvFile,
    [switch]$DryRun
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$repoRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$image = 'eneba-backend-prod'

if ($Action -eq 'Seed' -and [string]::IsNullOrWhiteSpace($Seeder)) {
    throw 'Seed requires an explicit -Seeder class, for example GameSeeder.'
}
if ($Action -eq 'Migrate' -and $Seeder) {
    throw '-Seeder is only valid with -Action Seed.'
}
if ([string]::IsNullOrWhiteSpace($EnvFile)) {
    $EnvFile = Join-Path $repoRoot 'backend/.env.production.local'
}
if (-not (Test-Path -LiteralPath $EnvFile -PathType Leaf)) {
    throw 'Private production env file not found. Create backend/.env.production.local first.'
}
$envPath = (Resolve-Path -LiteralPath $EnvFile).ProviderPath

# Read only for validation. Never print values or copy the private file.
$settings = @{}
try {
    foreach ($line in [IO.File]::ReadAllLines($envPath)) {
        if ([string]::IsNullOrWhiteSpace($line) -or $line.TrimStart().StartsWith('#')) { continue }
        if ($line -cnotmatch '^([A-Z_][A-Z0-9_]*)=(.*)$') {
            throw 'Invalid env file format.'
        }
        $settings[$Matches[1]] = $Matches[2]
    }
} catch {
    throw 'Cannot read the env file. Use uppercase KEY=value lines without export or surrounding quotes.'
}
foreach ($required in @('APP_KEY', 'DATABASE_URL')) {
    if (-not $settings.ContainsKey($required) -or [string]::IsNullOrWhiteSpace($settings[$required])) {
        throw 'The private env file must contain nonempty APP_KEY and DATABASE_URL.'
    }
}
if ($settings['APP_ENV'] -cne 'production' -or $settings['DB_CONNECTION'] -cne 'pgsql') {
    throw 'The private env file must set APP_ENV=production and DB_CONNECTION=pgsql.'
}
foreach ($conflicting in @('DB_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD')) {
    if ($settings.ContainsKey($conflicting) -and -not [string]::IsNullOrEmpty($settings[$conflicting])) {
        throw 'Use DATABASE_URL only; remove DB_URL and separate DB_* connection values from this file.'
    }
}
$databaseUri = $null
if (-not [Uri]::TryCreate($settings['DATABASE_URL'], [UriKind]::Absolute, [ref]$databaseUri)) {
    throw 'Invalid DATABASE_URL. Its value is intentionally hidden.'
}
if ($databaseUri.Scheme -notin @('postgres', 'postgresql') -or
    -not $databaseUri.Host.EndsWith('.neon.tech', [StringComparison]::OrdinalIgnoreCase)) {
    throw 'DATABASE_URL must point to a Neon PostgreSQL host ending in .neon.tech.'
}
if ($databaseUri.Host -match '-pooler\.') {
    throw 'Use the direct Neon connection URL for manual database operations, not the pooler URL.'
}
$query = @{}
foreach ($part in $databaseUri.Query.TrimStart('?').Split('&')) {
    if (-not $part) { continue }
    $pair = $part -split '=', 2
    if ($pair.Length -eq 2) {
        $key = [Uri]::UnescapeDataString($pair[0])
        if ($key -cmatch '^(sslmode|channel_binding)$') {
            $query[$key] = [Uri]::UnescapeDataString($pair[1])
        }
    }
}
if (-not (@('require', 'verify-ca', 'verify-full') -ccontains $query['sslmode']) -or
    $query['channel_binding'] -cne 'require') {
    throw 'DATABASE_URL must require TLS (sslmode=require or verify-*) and channel_binding=require.'
}

$artisanArgs = if ($Action -eq 'Migrate') {
    @('migrate', '--force', '--no-interaction')
} else {
    @('db:seed', "--class=$Seeder", '--force', '--no-interaction')
}
$confirmation = if ($Action -eq 'Migrate') { 'MIGRATE NEON' } else { "SEED $Seeder NEON" }

Write-Host "Target: Neon production. Action: $Action."
if ($Action -eq 'Seed') { Write-Host "Seeder: $Seeder" }
Write-Host 'Plan: build backend/Dockerfile.prod, then run the selected Artisan command in a disposable container.'
Write-Host 'Credentials and raw Docker/Artisan output will not be printed.'
if ($DryRun) {
    Write-Host 'Dry run complete: Docker was not invoked; no database connection was attempted.'
    return
}

Write-Host 'Continue only after applying and verifying these migrations locally and reviewing the intended changes.'
$answer = Read-Host "Type exactly $confirmation to authorize this operation"
if ($answer -cne $confirmation) {
    Write-Host 'Cancelled. Docker was not invoked; Neon was not contacted.'
    return
}
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker CLI not found. Start Docker Desktop and try again.'
}

function Invoke-SafeDocker {
    param([string[]]$DockerArgs, [string]$Step)
    # Windows PowerShell 5.1 can turn native stderr into ErrorRecord objects.
    # Suppress every output stream, including credential-bearing failure text.
    $ErrorActionPreference = 'Continue'
    & docker @DockerArgs *> $null
    $code = $LASTEXITCODE
    if ($code -ne 0) {
        throw "$Step failed (Docker exit code $code). Raw output is hidden to protect credentials."
    }
}

Write-Host 'Building the current production image...'
Invoke-SafeDocker -Step 'Image build' -DockerArgs @(
    'build', '-f', (Join-Path $repoRoot 'backend/Dockerfile.prod'), '-t', $image, (Join-Path $repoRoot 'backend')
)
Write-Host "Running $Action..."
Invoke-SafeDocker -Step 'Production operation' -DockerArgs (@(
    'run', '--rm', '--cap-drop=ALL', '--security-opt', 'no-new-privileges=true',
    '--env-file', $envPath, '-e', 'APP_ENV=production', '-e', 'APP_DEBUG=false',
    '-e', 'PGCONNECT_TIMEOUT=10', $image, 'php', 'artisan'
) + $artisanArgs)
Write-Host "$Action completed successfully."
