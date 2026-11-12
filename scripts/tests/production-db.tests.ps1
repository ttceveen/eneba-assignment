#requires -Version 5.1
# Offline tests: Docker and Read-Host are mocked. No database is contacted.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$productionScript = Join-Path $PSScriptRoot '../production-db.ps1'
$tempDir = Join-Path ([IO.Path]::GetTempPath()) ('Eneba db script tests ' + [Guid]::NewGuid().ToString('N'))
$null = New-Item -ItemType Directory -Path $tempDir
$envFile = Join-Path $tempDir '.env.production.local'
$secret = 'TEST_ONLY_SECRET_DO_NOT_PRINT'
$global:EnebaTestCalls = New-Object 'System.Collections.Generic.List[object]'
$global:EnebaTestPrompts = 0
$global:EnebaTestAnswer = ''
$global:EnebaTestFailOnCall = 0
$global:LASTEXITCODE = 0

function global:docker {
    $global:EnebaTestCalls.Add([string[]]$args)
    Write-Output 'TEST_ONLY_SECRET_DO_NOT_PRINT'
    Write-Error 'TEST_ONLY_SECRET_DO_NOT_PRINT' -ErrorAction Continue
    $global:LASTEXITCODE = if ($global:EnebaTestCalls.Count -eq $global:EnebaTestFailOnCall) { 7 } else { 0 }
}
function global:Read-Host {
    param([string]$Prompt)
    $global:EnebaTestPrompts++
    return $global:EnebaTestAnswer
}
function Assert-Test {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { throw $Message }
}
function Reset-Env {
    $content = @(
        'APP_ENV=production', 'DB_CONNECTION=pgsql', "APP_KEY=$secret",
        "DATABASE_URL=postgresql://fixture:$secret@ep-fixture.eu-central-1.aws.neon.tech/fixture?sslmode=require&channel_binding=require"
    ) -join "`n"
    [IO.File]::WriteAllText($envFile, $content + "`n")
}
function Invoke-TestScript {
    param([hashtable]$Arguments, [string]$Answer = '', [int]$FailOnCall = 0)
    $global:EnebaTestCalls.Clear()
    $global:EnebaTestPrompts = 0
    $global:EnebaTestAnswer = $Answer
    $global:EnebaTestFailOnCall = $FailOnCall
    $global:LASTEXITCODE = 0
    $captured = @()
    $failure = ''
    try { $captured = @(& $productionScript @Arguments 2>&1 6>&1) }
    catch { $failure = $_.Exception.Message }
    $text = ($captured | Out-String) + $failure
    Assert-Test (-not $text.Contains($secret)) 'Secret appeared in script output.'
    return [pscustomobject]@{ Failed = ($failure -ne ''); Error = $failure; Text = $text }
}

try {
    $tokens = $null; $parseErrors = $null
    $null = [System.Management.Automation.Language.Parser]::ParseFile($productionScript, [ref]$tokens, [ref]$parseErrors)
    Assert-Test ($parseErrors.Count -eq 0) 'PowerShell parse errors.'
    Write-Host 'PASS: PowerShell syntax'

    Reset-Env
    $hash = (Get-FileHash -LiteralPath $envFile).Hash
    $r = Invoke-TestScript @{ Action = 'Migrate'; EnvFile = $envFile; DryRun = $true }
    Assert-Test (-not $r.Failed) ("Dry run failed: " + $r.Error)
    Assert-Test ($global:EnebaTestCalls.Count -eq 0 -and $global:EnebaTestPrompts -eq 0) 'Dry run invoked Docker or prompted.'
    Write-Host 'PASS: DryRun invokes neither Docker nor confirmation; path with spaces supported'

    $r = Invoke-TestScript @{ Action = 'Migrate'; EnvFile = $envFile } 'NO'
    Assert-Test (-not $r.Failed -and $global:EnebaTestCalls.Count -eq 0 -and $global:EnebaTestPrompts -eq 1) 'Cancellation did not stop the operation.'
    Write-Host 'PASS: refusing confirmation prevents every Docker call'

    $r = Invoke-TestScript @{ Action = 'Migrate'; EnvFile = $envFile } 'MIGRATE NEON'
    Assert-Test (-not $r.Failed -and $global:EnebaTestCalls.Count -eq 2 -and $global:EnebaTestPrompts -eq 1) ("Migration plan failed: " + $r.Error)
    $build = $global:EnebaTestCalls[0]; $run = $global:EnebaTestCalls[1]
    Assert-Test ($build[0] -eq 'build' -and $run[0] -eq 'run') 'Expected build followed by disposable run.'
    Assert-Test ($run -contains '--env-file' -and $run -contains $envFile -and $run -contains '--rm') 'Private env file not passed correctly.'
    Assert-Test (($run[-3..-1] -join ' ') -eq 'migrate --force --no-interaction') 'Unexpected migration command.'
    Assert-Test ($run -contains '--cap-drop=ALL' -and $run -contains 'no-new-privileges=true') 'Container privilege restrictions missing.'
    Write-Host 'PASS: explicit migration confirmation, exact allowlisted command, secret output suppressed'

    $r = Invoke-TestScript @{ Action = 'Seed'; Seeder = 'GameSeeder'; EnvFile = $envFile } 'SEED GameSeeder NEON'
    Assert-Test (-not $r.Failed -and $global:EnebaTestCalls.Count -eq 2) ("Seeder plan failed: " + $r.Error)
    $run = $global:EnebaTestCalls[1]
    Assert-Test ($run -contains 'db:seed' -and $run -contains '--class=GameSeeder' -and -not ($run -contains 'migrate')) 'Unexpected seeder command.'
    Write-Host 'PASS: separate explicitly confirmed seeder command'
    $r = Invoke-TestScript @{ Action = 'Seed'; Seeder = 'GameSeeder'; EnvFile = $envFile } 'MIGRATE NEON'
    Assert-Test (-not $r.Failed -and $global:EnebaTestCalls.Count -eq 0) 'Migration approval authorized a seeder.'
    $r = Invoke-TestScript @{ Action = 'Seed'; Seeder = 'Database\Seeders\GameSeeder'; EnvFile = $envFile } 'SEED Database\Seeders\GameSeeder NEON'
    Assert-Test (-not $r.Failed -and $global:EnebaTestCalls.Count -eq 2) 'Qualified seeder class rejected.'
    Write-Host 'PASS: confirmations are operation-specific; qualified seeder classes supported'


    $r = Invoke-TestScript @{ Action = 'Seed'; EnvFile = $envFile }
    Assert-Test ($r.Failed -and $global:EnebaTestCalls.Count -eq 0) 'Seeder class must be explicit.'
    $r = Invoke-TestScript @{ Action = 'migrate:fresh'; EnvFile = $envFile }
    Assert-Test ($r.Failed -and $global:EnebaTestCalls.Count -eq 0) 'Destructive action accepted.'
    $r = Invoke-TestScript @{ Action = 'Seed'; Seeder = 'GameSeeder --force'; EnvFile = $envFile }
    Assert-Test ($r.Failed -and $global:EnebaTestCalls.Count -eq 0) 'Arbitrary seeder arguments accepted.'
    Write-Host 'PASS: missing seeder, destructive action and argument injection rejected'

    foreach ($change in @(
        @{ From = 'ep-fixture.eu-central-1.aws.neon.tech'; To = 'localhost' },
        @{ From = 'ep-fixture.eu-central-1.aws.neon.tech'; To = 'ep-fixture-pooler.eu-central-1.aws.neon.tech' },
        @{ From = 'sslmode=require'; To = 'sslmode=disable' },
        @{ From = 'channel_binding=require'; To = 'channel_binding=disable' },
        @{ From = 'sslmode=require'; To = 'SSLmode=require' },
        @{ From = 'DATABASE_URL='; To = 'database_url=' }
    )) {
        Reset-Env
        [IO.File]::WriteAllText($envFile, [IO.File]::ReadAllText($envFile).Replace($change.From, $change.To))
        $r = Invoke-TestScript @{ Action = 'Migrate'; EnvFile = $envFile; DryRun = $true }
        Assert-Test ($r.Failed -and $global:EnebaTestCalls.Count -eq 0) 'Unsafe connection configuration accepted.'
    }
    Reset-Env
    [IO.File]::AppendAllText($envFile, "DB_HOST=localhost`n")
    $r = Invoke-TestScript @{ Action = 'Migrate'; EnvFile = $envFile; DryRun = $true }
    Assert-Test ($r.Failed -and $global:EnebaTestCalls.Count -eq 0) 'Conflicting database configuration accepted.'
    Write-Host 'PASS: non-Neon/pooler/weak TLS/channel binding/conflicting DB settings rejected'

    Reset-Env
    $r = Invoke-TestScript @{ Action = 'Migrate'; EnvFile = $envFile } 'MIGRATE NEON' 1
    Assert-Test ($r.Failed -and $global:EnebaTestCalls.Count -eq 1) 'Build failure did not stop the operation.'
    $r = Invoke-TestScript @{ Action = 'Migrate'; EnvFile = $envFile } 'MIGRATE NEON' 2
    Assert-Test ($r.Failed -and $global:EnebaTestCalls.Count -eq 2) 'Operation failure was not reported.'
    Assert-Test ((Get-FileHash -LiteralPath $envFile).Hash -eq $hash) 'Env file changed.'
    Write-Host 'PASS: Docker failures stop execution without leaking output; env file unchanged'
    Write-Host 'All offline tests passed. No Docker commands or database connections were executed.'
} finally {
    Remove-Item -LiteralPath $tempDir -Recurse -Force
    Remove-Item Function:\docker -ErrorAction SilentlyContinue
    Remove-Item Function:\Read-Host -ErrorAction SilentlyContinue
    Remove-Variable EnebaTestCalls, EnebaTestPrompts, EnebaTestAnswer, EnebaTestFailOnCall -Scope Global -ErrorAction SilentlyContinue
}
