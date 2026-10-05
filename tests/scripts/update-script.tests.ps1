$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$sourceScript = Join-Path $projectRoot 'scripts/update.ps1'
$testRoot = Join-Path ([IO.Path]::GetTempPath()) ('cds-update-script-tests-' + [guid]::NewGuid().ToString('N'))
$bin = Join-Path $testRoot 'bin'
$script:passed = 0
$script:failed = 0

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { throw $Message }
}

function Git-Setup {
    param([string]$Directory, [string[]]$GitArgs)
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& git -C $Directory @GitArgs 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $savedPreference }
    if ($code -ne 0) { throw "Fixture git failed ($code) [$($GitArgs -join ' ')]: $($output -join ' ')" }
    return (($output | ForEach-Object { [string]$_ }) -join "`n").Trim()
}

function Write-Fixture {
    param([string]$Root, [string]$RelativePath, [string]$Content)
    $path = Join-Path $Root ($RelativePath.Replace('/', [IO.Path]::DirectorySeparatorChar))
    $parent = Split-Path -Parent $path
    if (-not (Test-Path -LiteralPath $parent)) { New-Item -ItemType Directory -Path $parent -Force | Out-Null }
    [IO.File]::WriteAllText($path, $Content, (New-Object Text.UTF8Encoding($false)))
}

function New-Fixture {
    $root = Join-Path $testRoot ([guid]::NewGuid().ToString('N'))
    $remote = Join-Path $root 'remote.git'
    $seed = Join-Path $root 'seed'
    $local = Join-Path $root 'local'
    New-Item -ItemType Directory -Path $root -Force | Out-Null
    $null = & git init --bare $remote 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'Could not initialize disposable bare remote.' }
    $null = & git init --initial-branch=main $seed 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'Could not initialize disposable seed repository.' }
    Git-Setup $seed @('config','user.name','CDS Update Test') | Out-Null
    Git-Setup $seed @('config','user.email','cds-update-test@example.invalid') | Out-Null
    Git-Setup $seed @('remote','add','origin',$remote) | Out-Null
    Write-Fixture $seed '.gitignore' "/vendor/`n/node_modules/`n/public/hot`n/public/build/`n/bootstrap/cache/*.php`n/.recovery/`n/storage/app/`n"
    Write-Fixture $seed 'scripts/update.ps1' ([IO.File]::ReadAllText($sourceScript))
    Write-Fixture $seed 'package.json' '{"private":true,"scripts":{"build":"node build"}}'
    Write-Fixture $seed 'package-lock.json' '{"name":"fixture","lockfileVersion":3,"packages":{"":{"name":"fixture"}}}'
    Write-Fixture $seed 'composer.json' '{"name":"fixture/app","require":{},"scripts":{"post-update-cmd":["unsafe-fixture-hook"]}}'
    Write-Fixture $seed 'composer.lock' '{"packages":[],"packages-dev":[],"platform":{},"platform-dev":{},"plugin-api-version":"2.9.0"}'
    Write-Fixture $seed 'resources/js/app.jsx' 'export const revision = 1;'
    Write-Fixture $seed 'app/Domain.php' '<?php return true;'
    Write-Fixture $seed 'database/migrations/2026_01_01_000000_base.php' '<?php return new class {};'
    Git-Setup $seed @('add','--all') | Out-Null
    Git-Setup $seed @('commit','-m','fixture baseline') | Out-Null
    Git-Setup $seed @('push','--set-upstream','origin','main') | Out-Null
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $clone = @(& git clone --branch main $remote $local 2>&1)
        $cloneCode = $LASTEXITCODE
    } finally { $ErrorActionPreference = $savedPreference }
    if ($cloneCode -ne 0) { throw "Could not clone fixture: $($clone -join ' ')" }
    New-Item -ItemType Directory -Path (Join-Path $local 'vendor'),(Join-Path $local 'node_modules') -Force | Out-Null
    Write-Fixture $local 'public/build/manifest.json' '{}'
    return [pscustomobject]@{ Root=$root; Remote=$remote; Seed=$seed; Local=$local; Script=(Join-Path $local 'scripts/update.ps1') }
}

function Commit-SeedChange {
    param($Fixture, [string]$Path, [string]$Content, [string]$Message='fixture update')
    Write-Fixture $Fixture.Seed $Path $Content
    Git-Setup $Fixture.Seed @('add','--all') | Out-Null
    Git-Setup $Fixture.Seed @('commit','-m',$Message) | Out-Null
    Git-Setup $Fixture.Seed @('push','origin','main') | Out-Null
    return (Git-Setup $Fixture.Seed @('rev-parse','HEAD'))
}

function Run-Update {
    param($Fixture, [switch]$CheckOnly, [string]$FailPhase='')
    $log = Join-Path $Fixture.Root 'commands.log'
    Remove-Item -LiteralPath $log -Force -ErrorAction SilentlyContinue
    $env:CDS_UPDATE_LOG = $log
    $env:CDS_UPDATE_FAIL = $FailPhase
    $childArgs = @('-NoProfile','-ExecutionPolicy','RemoteSigned','-File',$Fixture.Script)
    if ($CheckOnly) { $childArgs += '-CheckOnly' }
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& powershell.exe @childArgs 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $savedPreference }
    $text = ($output | ForEach-Object { [string]$_ }) -join "`n"
    $commands = if (Test-Path -LiteralPath $log) { [IO.File]::ReadAllText($log) } else { '' }
    return [pscustomobject]@{ ExitCode=$code; Text=$text; Commands=$commands }
}

function Test-Case {
    param([string]$Name, [scriptblock]$Body)
    try { & $Body; $script:passed++; Write-Output "PASS $Name" }
    catch { $script:failed++; Write-Output ('FAIL {0} - {1}' -f $Name, $_.Exception.Message) }
}

$oldPath = $env:PATH
$oldLog = $env:CDS_UPDATE_LOG
$oldFailure = $env:CDS_UPDATE_FAIL
try {
    New-Item -ItemType Directory -Path $bin -Force | Out-Null
    $npmStub = @'
@echo off
>>"%CDS_UPDATE_LOG%" echo npm %*
if /I "%CDS_UPDATE_FAIL%"=="npm-ci" if /I "%1"=="ci" exit /b 41
if /I "%CDS_UPDATE_FAIL%"=="npm-build" if /I "%1"=="run" if /I "%2"=="build" exit /b 42
exit /b 0
'@
    $composerStub = @'
@echo off
>>"%CDS_UPDATE_LOG%" echo composer %*
if /I "%CDS_UPDATE_FAIL%"=="composer" exit /b 43
exit /b 0
'@
    $phpStub = @'
@echo off
>>"%CDS_UPDATE_LOG%" echo php %*
if /I "%1"=="artisan" if /I "%2"=="package:discover" (
  if not exist bootstrap\cache mkdir bootstrap\cache
  >bootstrap\cache\packages.php echo discovered
  >bootstrap\cache\services.php echo refreshed
)
exit /b 0
'@
    [IO.File]::WriteAllText((Join-Path $bin 'npm.cmd'),$npmStub,(New-Object Text.ASCIIEncoding))
    [IO.File]::WriteAllText((Join-Path $bin 'composer.cmd'),$composerStub,(New-Object Text.ASCIIEncoding))
    [IO.File]::WriteAllText((Join-Path $bin 'php.cmd'),$phpStub,(New-Object Text.ASCIIEncoding))
    $env:PATH = $bin + ';' + $oldPath

    Test-Case 'clean fast-forward runs the frontend build for resource changes' {
        $f=New-Fixture
        $new=Commit-SeedChange $f 'resources/js/app.jsx' 'export const revision = 2;'
        $r=Run-Update $f
        Assert-True ($r.ExitCode -eq 0) $r.Text
        Assert-True ($r.Text.Contains("New commit: $new")) $r.Text
        Assert-True ($r.Commands -match 'npm run build') $r.Commands
        Assert-True ($r.Commands -notmatch 'npm ci|composer') $r.Commands
        Assert-True ((Git-Setup $f.Local @('rev-parse','HEAD')) -eq $new) 'Fast-forward did not reach upstream.'
    }

    Test-Case 'already current skips dependency and build steps when present' {
        $f=New-Fixture
        $r=Run-Update $f
        Assert-True ($r.ExitCode -eq 0) $r.Text
        Assert-True ($r.Text.Contains('Already current')) $r.Text
        Assert-True ($r.Commands -eq '') $r.Commands
        Assert-True ($r.Text.Contains('Build: skipped')) $r.Text
    }

    Test-Case 'CheckOnly does not fetch or mutate when upstream is ahead' {
        $f=New-Fixture
        $new=Commit-SeedChange $f 'app/Domain.php' '<?php return 2;'
        $headBefore=Git-Setup $f.Local @('rev-parse','HEAD')
        $refBefore=Git-Setup $f.Local @('rev-parse','refs/remotes/origin/main')
        $r=Run-Update $f -CheckOnly
        Assert-True ($r.ExitCode -eq 0) $r.Text
        Assert-True ($r.Text.Contains('No fetch, install, merge, or build')) $r.Text
        Assert-True ($r.Commands -eq '') $r.Commands
        Assert-True ((Git-Setup $f.Local @('rev-parse','HEAD')) -eq $headBefore) 'HEAD changed in CheckOnly.'
        Assert-True ((Git-Setup $f.Local @('rev-parse','refs/remotes/origin/main')) -eq $refBefore) 'Remote ref changed in CheckOnly.'
        Assert-True ($new -ne $headBefore) 'Fixture did not advance remote.'
    }

    Test-Case 'tracked dirty work blocks before fetch' {
        $f=New-Fixture
        Write-Fixture $f.Local 'app/Domain.php' '<?php return 9;'
        $r=Run-Update $f
        Assert-True ($r.ExitCode -ne 0 -and $r.Text.Contains('Tracked or staged changes')) $r.Text
        Assert-True ($r.Text.Contains('nothing was fetched')) $r.Text
        Assert-True ($r.Commands -eq '') $r.Commands
    }

    Test-Case 'staged work blocks before fetch' {
        $f=New-Fixture
        Write-Fixture $f.Local 'app/Domain.php' '<?php return 8;'
        Git-Setup $f.Local @('add','app/Domain.php') | Out-Null
        $r=Run-Update $f
        Assert-True ($r.ExitCode -ne 0 -and $r.Text.Contains('Tracked or staged changes')) $r.Text
        Assert-True ($r.Commands -eq '') $r.Commands
    }

    Test-Case 'harmless untracked file survives fast-forward' {
        $f=New-Fixture
        Write-Fixture $f.Local 'notes/local.txt' 'keep me'
        $null=Commit-SeedChange $f 'app/Domain.php' '<?php return 3;'
        $r=Run-Update $f
        Assert-True ($r.ExitCode -eq 0) $r.Text
        Assert-True ((Get-Content (Join-Path $f.Local 'notes/local.txt') -Raw) -eq 'keep me') 'Untracked file changed.'
    }

    Test-Case 'untracked path collision blocks merge and preserves local file' {
        $f=New-Fixture
        $null=Commit-SeedChange $f 'notes/collision.txt' 'upstream'
        Write-Fixture $f.Local 'notes/collision.txt' 'local'
        $head=Git-Setup $f.Local @('rev-parse','HEAD')
        $r=Run-Update $f
        Assert-True ($r.ExitCode -ne 0 -and $r.Text.Contains('overlaps untracked work')) $r.Text
        Assert-True ((Git-Setup $f.Local @('rev-parse','HEAD')) -eq $head) 'Collision was checked after merge.'
        Assert-True ((Get-Content (Join-Path $f.Local 'notes/collision.txt') -Raw) -eq 'local') 'Local file changed.'
    }

    Test-Case '.recovery remains untouched when incoming history touches it' {
        $f=New-Fixture
        Write-Fixture $f.Local '.recovery/keep.txt' 'recovery data'
        $null=Commit-SeedChange $f 'app/Domain.php' '<?php return 4;'
        Write-Fixture $f.Seed '.recovery/keep.txt' 'incoming'
        Git-Setup $f.Seed @('add','-f','.recovery/keep.txt') | Out-Null
        Git-Setup $f.Seed @('commit','-m','touch recovery') | Out-Null
        Git-Setup $f.Seed @('push','origin','main') | Out-Null
        $head=Git-Setup $f.Local @('rev-parse','HEAD')
        $r=Run-Update $f
        Assert-True ($r.ExitCode -ne 0 -and $r.Text.Contains('preserved .recovery')) $r.Text
        Assert-True ((Git-Setup $f.Local @('rev-parse','HEAD')) -eq $head) 'Recovery protection happened after merge.'
        Assert-True ((Get-Content (Join-Path $f.Local '.recovery/keep.txt') -Raw) -eq 'recovery data') 'Recovery data changed.'
    }

    Test-Case 'branch divergence is rejected' {
        $f=New-Fixture
        Write-Fixture $f.Local 'app/Domain.php' '<?php return 7;'
        Git-Setup $f.Local @('add','app/Domain.php') | Out-Null
        Git-Setup $f.Local @('commit','-m','local-only') | Out-Null
        $null=Commit-SeedChange $f 'resources/js/app.jsx' 'export const revision = 4;'
        $r=Run-Update $f
        Assert-True ($r.ExitCode -ne 0 -and $r.Text.Contains('diverged')) $r.Text
        Assert-True ($r.Text -notmatch 'Applying safe fast-forward') $r.Text
    }

    Test-Case 'unpublished local-only commits are rejected' {
        $f=New-Fixture
        Write-Fixture $f.Local 'app/Domain.php' '<?php return 10;'
        Git-Setup $f.Local @('add','app/Domain.php') | Out-Null
        Git-Setup $f.Local @('commit','-m','unpublished local change') | Out-Null
        $r=Run-Update $f
        Assert-True ($r.ExitCode -ne 0 -and $r.Text.Contains('local-only/unpublished')) $r.Text
        Assert-True ($r.Text -notmatch 'Applying safe fast-forward') $r.Text
    }

    Test-Case 'detached HEAD is rejected' {
        $f=New-Fixture
        Git-Setup $f.Local @('checkout','--detach','HEAD') | Out-Null
        $r=Run-Update $f
        Assert-True ($r.ExitCode -ne 0 -and $r.Text.Contains('HEAD is detached')) $r.Text
        Assert-True ($r.Commands -eq '') $r.Commands
    }

    Test-Case 'fetch failure does not print remote URL details' {
        $f=New-Fixture
        Git-Setup $f.Local @('remote','set-url','origin',(Join-Path $f.Root 'missing-remote.git')) | Out-Null
        $r=Run-Update $f
        Assert-True ($r.ExitCode -ne 0 -and $r.Text.Contains('Git fetch failed')) $r.Text
        Assert-True ($r.Text -notmatch 'missing-remote|file:|https?://') 'Remote details were printed.'
        Assert-True ($r.Text -notmatch 'Applying safe fast-forward') $r.Text
    }

    Test-Case 'npm install failure preserves new HEAD and reports failed phase' {
        $f=New-Fixture
        $new=Commit-SeedChange $f 'package-lock.json' '{"name":"fixture","lockfileVersion":3,"packages":{"":{"name":"fixture"},"node_modules/new":{"version":"1.0.0"}}}'
        $r=Run-Update $f -FailPhase 'npm-ci'
        Assert-True ($r.ExitCode -ne 0 -and $r.Text.Contains('npm lockfile install')) $r.Text
        Assert-True ($r.Text.Contains("Current HEAD: $new")) $r.Text
        Assert-True ((Git-Setup $f.Local @('rev-parse','HEAD')) -eq $new) 'Install failure caused rollback.'
        Assert-True ($r.Commands -match 'npm ci --ignore-scripts') $r.Commands
    }

    Test-Case 'build failure preserves new HEAD and reports failed phase' {
        $f=New-Fixture
        $new=Commit-SeedChange $f 'resources/js/app.jsx' 'export const revision = 5;'
        $r=Run-Update $f -FailPhase 'npm-build'
        Assert-True ($r.ExitCode -ne 0 -and $r.Text.Contains('Production frontend build')) $r.Text
        Assert-True ($r.Text.Contains("Current HEAD: $new")) $r.Text
        Assert-True ((Git-Setup $f.Local @('rev-parse','HEAD')) -eq $new) 'Build failure caused rollback.'
        Assert-True ($r.Commands -match 'npm run build') $r.Commands
    }

    Test-Case 'Composer lock changes install with hooks disabled and build frontend' {
        $f=New-Fixture
        Write-Fixture $f.Local 'bootstrap/cache/config.php' 'preserve-config'
        Write-Fixture $f.Local 'bootstrap/cache/packages.php' 'stale-packages'
        Write-Fixture $f.Local 'bootstrap/cache/services.php' 'stale-services'
        $null=Commit-SeedChange $f 'composer.lock' '{"packages":[{"name":"fixture/dependency","version":"1.0.1"}],"packages-dev":[],"platform":{},"platform-dev":{},"plugin-api-version":"2.9.0"}'
        $r=Run-Update $f
        Assert-True ($r.ExitCode -eq 0) $r.Text
        Assert-True ($r.Commands -match 'composer install --no-interaction --prefer-dist --no-scripts --no-plugins') $r.Commands
        Assert-True ($r.Commands -match 'php artisan package:discover --ansi') $r.Commands
        Assert-True ($r.Commands -match 'npm run build') $r.Commands
        Assert-True ($r.Commands -notmatch 'artisan (migrate|db:seed|test)|vendor:publish|composer setup') 'Unsafe Composer hook was run.'
        Assert-True ((Get-Content (Join-Path $f.Local 'bootstrap/cache/config.php') -Raw) -eq 'preserve-config') 'Cached configuration was changed.'
        Assert-True ((Get-Content (Join-Path $f.Local 'bootstrap/cache/packages.php') -Raw).Trim() -eq 'discovered') 'Package manifest was not regenerated.'
        Assert-True ((Get-Content (Join-Path $f.Local 'bootstrap/cache/services.php') -Raw).Trim() -eq 'refreshed') 'Provider manifest was not regenerated.'
    }

    Test-Case 'PHP-only update skips frontend commands with dependencies and output present' {
        $f=New-Fixture
        $null=Commit-SeedChange $f 'app/Domain.php' '<?php return 6;'
        $r=Run-Update $f
        Assert-True ($r.ExitCode -eq 0) $r.Text
        Assert-True ($r.Commands -eq '') $r.Commands
        Assert-True ($r.Text.Contains('Build: skipped')) $r.Text
    }

    Test-Case 'missing dependencies and build manifest trigger installs and build' {
        $f=New-Fixture
        Remove-Item -LiteralPath (Join-Path $f.Local 'vendor') -Recurse -Force
        Remove-Item -LiteralPath (Join-Path $f.Local 'node_modules') -Recurse -Force
        Remove-Item -LiteralPath (Join-Path $f.Local 'public/build/manifest.json') -Force
        $r=Run-Update $f
        Assert-True ($r.ExitCode -eq 0) $r.Text
        Assert-True ($r.Commands -match 'composer install --no-interaction --prefer-dist --no-scripts --no-plugins') $r.Commands
        Assert-True ($r.Commands -match 'npm ci --ignore-scripts') $r.Commands
        Assert-True ($r.Commands -match 'npm run build') $r.Commands
    }

    Test-Case 'incoming migrations are reported but never executed' {
        $f=New-Fixture
        $null=Commit-SeedChange $f 'database/migrations/2026_02_02_000000_new.php' '<?php return new class {};'
        $r=Run-Update $f
        Assert-True ($r.ExitCode -eq 0) $r.Text
        Assert-True ($r.Text.Contains('Manual review required') -and $r.Text.Contains('2026_02_02_000000_new.php')) $r.Text
        Assert-True ($r.Commands -notmatch 'migrate|seed|artisan') $r.Commands
    }

    Test-Case 'public hot dev marker blocks build before merge' {
        $f=New-Fixture
        $null=Commit-SeedChange $f 'resources/js/app.jsx' 'export const revision = 7;'
        Write-Fixture $f.Local 'public/hot' 'http://127.0.0.1:5173'
        $head=Git-Setup $f.Local @('rev-parse','HEAD')
        $r=Run-Update $f
        Assert-True ($r.ExitCode -ne 0 -and $r.Text.Contains('public/hot')) $r.Text
        Assert-True ((Git-Setup $f.Local @('rev-parse','HEAD')) -eq $head) 'Dev marker check happened after merge.'
        Assert-True ((Get-Content (Join-Path $f.Local 'public/hot') -Raw) -eq 'http://127.0.0.1:5173') 'Dev marker changed.'
        Assert-True ($r.Commands -eq '') $r.Commands
    }

    Test-Case 'incoming operational uploads path is blocked' {
        $f=New-Fixture
        Write-Fixture $f.Seed 'storage/app/public/document.pdf' 'incoming'
        Git-Setup $f.Seed @('add','-f','storage/app/public/document.pdf') | Out-Null
        Git-Setup $f.Seed @('commit','-m','incoming upload path') | Out-Null
        Git-Setup $f.Seed @('push','origin','main') | Out-Null
        $r=Run-Update $f
        Assert-True ($r.ExitCode -ne 0 -and $r.Text.Contains('operational storage/uploads')) $r.Text
        Assert-True ($r.Text -notmatch 'Applying safe fast-forward') $r.Text
    }

    Test-Case 'incoming env path is blocked for manual review' {
        $f=New-Fixture
        Write-Fixture $f.Seed '.env' 'APP_KEY=not-a-real-secret'
        Git-Setup $f.Seed @('add','-f','.env') | Out-Null
        Git-Setup $f.Seed @('commit','-m','unsafe env file') | Out-Null
        Git-Setup $f.Seed @('push','origin','main') | Out-Null
        $r=Run-Update $f
        Assert-True ($r.ExitCode -ne 0 -and $r.Text.Contains("Incoming path '.env'")) $r.Text
        Assert-True ($r.Text -notmatch 'APP_KEY|not-a-real-secret') 'Environment contents were printed.'
    }
} finally {
    $env:PATH = $oldPath
    if ($null -eq $oldLog) { Remove-Item Env:CDS_UPDATE_LOG -ErrorAction SilentlyContinue } else { $env:CDS_UPDATE_LOG = $oldLog }
    if ($null -eq $oldFailure) { Remove-Item Env:CDS_UPDATE_FAIL -ErrorAction SilentlyContinue } else { $env:CDS_UPDATE_FAIL = $oldFailure }
    $tempPrefix = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\') + '\'
    $target = [IO.Path]::GetFullPath($testRoot)
    if (-not $target.StartsWith($tempPrefix,[StringComparison]::OrdinalIgnoreCase) -or -not (Split-Path -Leaf $target).StartsWith('cds-update-script-tests-')) {
        throw 'Refusing cleanup outside the named temporary test directory.'
    }
    Remove-Item -LiteralPath $target -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Output "RESULT passed=$script:passed failed=$script:failed"
if ($script:failed -gt 0) { exit 1 }
