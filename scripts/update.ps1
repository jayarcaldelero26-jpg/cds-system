<#
.SYNOPSIS
Safely fast-forwards this checkout to its configured origin-tracking branch.

.PARAMETER CheckOnly
Checks the repository location, branch/upstream, tracked worktree state, tools,
dependency folders, and build manifest. It never fetches or changes files, and
does not verify remote freshness.

.EXAMPLE
.\scripts\update.ps1

.EXAMPLE
.\scripts\update.ps1 -CheckOnly
#>
[CmdletBinding()]
param(
    [switch]$CheckOnly
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

function Write-Result {
    param([string]$Message)
    [Console]::WriteLine($Message)
}

function Get-Tool {
    param([string]$Name)
    $tool = Get-Command -Name $Name -CommandType Application -ErrorAction SilentlyContinue
    if ($null -eq $tool) {
        throw "Required tool is unavailable: $Name"
    }
    return ($tool | Select-Object -First 1)
}

function Invoke-Native {
    param(
        [string]$Name,
        [string[]]$Arguments,
        [switch]$SuppressOutput
    )

    $tool = Get-Tool -Name $Name
    $savedErrorAction = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& $tool.Source @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $savedErrorAction
    }
    if ($null -eq $exitCode) {
        $exitCode = 0
    }
    $text = ($output | ForEach-Object { [string]$_ }) -join [Environment]::NewLine
    if (-not $SuppressOutput -and $text.Length -gt 0) {
        $displayText = [regex]::Replace($text, '(?i)(https?://)[^/\s:@]+(?::[^/@\s]*)?@', '$1[redacted]@')
        foreach ($line in ($displayText -split "`r?`n")) {
            if ($line.Length -gt 0) {
                Write-Result $line
            }
        }
    }
    return [pscustomobject]@{
        ExitCode = [int]$exitCode
        Output = $text
    }
}

function Invoke-GitText {
    param([string[]]$Arguments)
    $result = Invoke-Native -Name 'git' -Arguments $Arguments -SuppressOutput
    if ($result.ExitCode -ne 0) {
        throw "Git validation command failed (exit $($result.ExitCode)); details suppressed."
    }
    return $result.Output.Trim()
}

function Get-StatusRecords {
    $result = Invoke-Native -Name 'git' -Arguments @('status', '--porcelain=v1', '-z', '--untracked-files=all') -SuppressOutput
    if ($result.ExitCode -ne 0) {
        throw "Could not inspect Git worktree status (exit $($result.ExitCode))."
    }
    if ([string]::IsNullOrEmpty($result.Output)) {
        return @()
    }
    return @($result.Output.Split([char]0, [StringSplitOptions]::RemoveEmptyEntries))
}

function Convert-GitPath {
    param([string]$Path)
    return $Path.Replace('\', '/')
}

function Test-PathOverlap {
    param([string]$Left, [string]$Right)
    $a = (Convert-GitPath $Left).TrimEnd('/')
    $b = (Convert-GitPath $Right).TrimEnd('/')
    return $a.Equals($b, [StringComparison]::OrdinalIgnoreCase) -or
        $a.StartsWith($b + '/', [StringComparison]::OrdinalIgnoreCase) -or
        $b.StartsWith($a + '/', [StringComparison]::OrdinalIgnoreCase)
}

function Get-NullSeparatedGitPaths {
    param([string[]]$Arguments)
    $result = Invoke-Native -Name 'git' -Arguments $Arguments -SuppressOutput
    if ($result.ExitCode -ne 0) {
        throw "Could not inspect Git paths (exit $($result.ExitCode)); details suppressed."
    }
    if ([string]::IsNullOrEmpty($result.Output)) {
        return @()
    }
    return @($result.Output.Split([char]0, [StringSplitOptions]::RemoveEmptyEntries) | ForEach-Object { Convert-GitPath $_ })
}

function Assert-CleanTrackedWorktree {
    param([object[]]$StatusRecords)
    $tracked = @($StatusRecords | Where-Object {
        $_.Length -ge 2 -and $_.Substring(0, 2) -ne '??' -and $_.Substring(0, 2) -ne '!!'
    })
    if ($tracked.Count -gt 0) {
        $paths = @($tracked | ForEach-Object { if ($_.Length -gt 3) { $_.Substring(3) } })
        $summary = ($paths | Select-Object -First 8) -join ', '
        if ($paths.Count -gt 8) { $summary += ', …' }
        throw "Tracked or staged changes are present: $summary. Commit or otherwise resolve them before updating; nothing was fetched or changed."
    }
}

function Get-RepositoryContext {
    param([string]$RepositoryRoot)

    $top = Invoke-GitText @('rev-parse', '--show-toplevel')
    $expected = [IO.Path]::GetFullPath($RepositoryRoot).TrimEnd('\', '/')
    $actual = [IO.Path]::GetFullPath($top).TrimEnd('\', '/')
    if (-not $actual.Equals($expected, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Script location resolved to '$expected', but Git reports '$actual'."
    }

    $branchResult = Invoke-Native -Name 'git' -Arguments @('symbolic-ref', '--quiet', '--short', 'HEAD') -SuppressOutput
    if ($branchResult.ExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($branchResult.Output)) {
        throw 'HEAD is detached; switch to the intended branch before updating.'
    }
    $branch = $branchResult.Output.Trim()

    $origin = Invoke-Native -Name 'git' -Arguments @('remote', 'get-url', '--all', 'origin') -SuppressOutput
    if ($origin.ExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($origin.Output)) {
        throw 'The origin remote is not configured; configure and verify origin before updating.'
    }

    $remote = Invoke-GitText @('config', '--get', "branch.$branch.remote")
    $mergeRef = Invoke-GitText @('config', '--get', "branch.$branch.merge")
    if ([string]::IsNullOrWhiteSpace($remote) -or [string]::IsNullOrWhiteSpace($mergeRef) -or $remote -eq '.') {
        throw "Branch '$branch' has no usable configured remote upstream."
    }
    $remoteUrl = Invoke-Native -Name 'git' -Arguments @('remote', 'get-url', '--all', $remote) -SuppressOutput
    if ($remoteUrl.ExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($remoteUrl.Output)) {
        throw "The configured upstream remote '$remote' is unavailable."
    }
    $upstream = Invoke-GitText @('rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}')
    if (-not $upstream.StartsWith($remote + '/', [StringComparison]::OrdinalIgnoreCase)) {
        throw "Branch '$branch' upstream does not resolve to its configured remote '$remote'."
    }

    $head = Invoke-GitText @('rev-parse', '--verify', 'HEAD')
    if ($head -notmatch '^[0-9a-fA-F]{40,64}$') {
        throw 'Could not verify the current commit.'
    }
    return [pscustomobject]@{
        Branch = $branch
        Remote = $remote
        Upstream = $upstream
        Head = $head
    }
}

function Get-ChangedPaths {
    param([string]$OldHead, [string]$NewHead)
    if ($OldHead -eq $NewHead) { return @() }
    return @(Get-NullSeparatedGitPaths @('diff', '--name-only', '-z', $OldHead, $NewHead, '--'))
}

function Assert-SafeIncomingPaths {
    param(
        [string[]]$IncomingPaths,
        [string[]]$UntrackedPaths,
        [string]$OldHead,
        [string]$RepositoryRoot
    )

    $oldTracked = @(Get-NullSeparatedGitPaths @('ls-tree', '-r', '--name-only', '-z', $OldHead, '--'))
    $oldSet = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
    foreach ($path in $oldTracked) { [void]$oldSet.Add($path) }
    $recoveryPath = Join-Path $RepositoryRoot '.recovery'
    $recoveryExists = Test-Path -LiteralPath $recoveryPath

    foreach ($incoming in $IncomingPaths) {
        $path = Convert-GitPath $incoming
        if ($recoveryExists -and ($path -eq '.recovery' -or $path.StartsWith('.recovery/', [StringComparison]::OrdinalIgnoreCase))) {
            throw "Incoming path '$path' touches the preserved .recovery directory; update stopped before merge."
        }
        if ($path -eq '.env') {
            throw "Incoming path '.env' would replace runtime configuration; review it separately before updating."
        }
        if ($path.StartsWith('storage/app/', [StringComparison]::OrdinalIgnoreCase)) {
            throw "Incoming path '$path' touches operational storage/uploads; review it separately before updating."
        }
        if ($path.StartsWith('bootstrap/cache/', [StringComparison]::OrdinalIgnoreCase)) {
            throw "Incoming path '$path' touches generated runtime cache; review it separately before updating."
        }

        foreach ($untracked in $UntrackedPaths) {
            if (Test-PathOverlap -Left $path -Right $untracked) {
                throw "Incoming path '$path' overlaps untracked work '$untracked'; move or resolve the collision before updating."
            }
        }

        if (-not $oldSet.Contains($path)) {
            $localPath = Join-Path $RepositoryRoot ($path.Replace('/', [IO.Path]::DirectorySeparatorChar))
            if (Test-Path -LiteralPath $localPath) {
                throw "Incoming path '$path' already exists locally but is not tracked at the current commit; update stopped to preserve it."
            }
        }
    }
}

function Test-FrontendInputsChanged {
    param([string[]]$Paths)
    foreach ($rawPath in $Paths) {
        $path = Convert-GitPath $rawPath
        if ($path.StartsWith('resources/', [StringComparison]::OrdinalIgnoreCase) -or
            $path -match '(^|/)(package\.json|package-lock\.json|vite\.config\.[^/]+|postcss\.config\.[^/]+|tailwind\.config\.[^/]+)$' -or
            $path -ieq 'scripts/clean-vite-hot.js') {
            return $true
        }
    }
    return $false
}

function Test-MigrationChanges {
    param([string[]]$Paths)
    return @($Paths | Where-Object { (Convert-GitPath $_) -match '(^|/)database/migrations/.*\.php$' })
}

function Invoke-Phase {
    param(
        [string]$Phase,
        [string]$Tool,
        [string[]]$Arguments
    )
    Write-Result "Running $Phase..."
    $result = Invoke-Native -Name $Tool -Arguments $Arguments
    if ($result.ExitCode -ne 0) {
        $headResult = Invoke-Native -Name 'git' -Arguments @('rev-parse', '--verify', 'HEAD') -SuppressOutput
        $headNow = if ($headResult.ExitCode -eq 0) { $headResult.Output.Trim() } else { 'unavailable' }
        throw "$Phase failed with exit code $($result.ExitCode). Current HEAD: $headNow. State was preserved; no rollback was attempted."
    }
}

function Invoke-ProjectUpdate {
    $repositoryRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
    Push-Location -LiteralPath $repositoryRoot
    try {
        [void](Get-Tool 'git')
        $context = Get-RepositoryContext -RepositoryRoot $repositoryRoot
        $statusRecords = @(Get-StatusRecords)
        Assert-CleanTrackedWorktree -StatusRecords $statusRecords
        $untracked = @($statusRecords | Where-Object { $_.Length -ge 3 -and $_.Substring(0, 2) -eq '??' } | ForEach-Object { Convert-GitPath $_.Substring(3) })

        $hasComposer = Test-Path -LiteralPath (Join-Path $repositoryRoot 'composer.json')
        $hasPackage = Test-Path -LiteralPath (Join-Path $repositoryRoot 'package.json')
        if ($hasComposer) {
            [void](Get-Tool 'composer')
            [void](Get-Tool 'php')
            if (-not (Test-Path -LiteralPath (Join-Path $repositoryRoot 'composer.lock'))) {
                throw 'composer.lock is missing; review and commit a lockfile before using automated dependency installation.'
            }
        }
        if ($hasPackage) {
            [void](Get-Tool 'npm.cmd')
            if (-not (Test-Path -LiteralPath (Join-Path $repositoryRoot 'package-lock.json'))) {
                throw 'package-lock.json is missing; review and commit a lockfile before using automated dependency installation.'
            }
        }

        $vendorAbsent = $hasComposer -and -not (Test-Path -LiteralPath (Join-Path $repositoryRoot 'vendor') -PathType Container)
        $nodeModulesAbsent = $hasPackage -and -not (Test-Path -LiteralPath (Join-Path $repositoryRoot 'node_modules') -PathType Container)
        $manifestAbsent = $hasPackage -and -not (Test-Path -LiteralPath (Join-Path $repositoryRoot 'public/build/manifest.json') -PathType Leaf)

        if ($CheckOnly) {
            $state = if ($vendorAbsent) { 'Composer dependencies are absent.' } else { 'Composer dependencies are present or not used.' }
            $state += if ($nodeModulesAbsent) { ' npm dependencies are absent.' } else { ' npm dependencies are present or not used.' }
            $state += if ($manifestAbsent) { ' Production build manifest is absent.' } else { ' Production build manifest is present or not required.' }
            Write-Result "CheckOnly: branch '$($context.Branch)' tracks '$($context.Upstream)'; HEAD $($context.Head)."
            if ($untracked.Count -gt 0) {
                Write-Result "Untracked files are preserved ($($untracked.Count)); collisions will be checked after fetch in normal mode."
            }
            Write-Result $state
            Write-Result 'No fetch, install, merge, or build was performed; upstream freshness was not checked.'
            return
        }

        $beforeHead = $context.Head
        Write-Result "Fetching configured remote '$($context.Remote)' (remote URL is not displayed)..."
        $fetch = Invoke-Native -Name 'git' -Arguments @('fetch', '--no-tags', $context.Remote) -SuppressOutput
        if ($fetch.ExitCode -ne 0) {
            throw "Git fetch failed with exit code $($fetch.ExitCode); output suppressed to protect remote credentials. No project files were changed."
        }

        $newHead = Invoke-GitText @('rev-parse', '--verify', $context.Upstream)
        if ($newHead -notmatch '^[0-9a-fA-F]{40,64}$') {
            throw 'Fetched upstream did not resolve to a commit.'
        }
        $range = "$beforeHead...$newHead"
        $counts = Invoke-GitText @('rev-list', '--left-right', '--count', $range)
        $countParts = @($counts -split '\s+' | Where-Object { $_.Length -gt 0 })
        if ($countParts.Count -ne 2) { throw 'Could not compare local and upstream commit counts.' }
        $ahead = [int]$countParts[0]
        $behind = [int]$countParts[1]
        if ($ahead -gt 0 -and $behind -gt 0) {
            throw "Branch '$($context.Branch)' has diverged ($ahead local-only commit(s), $behind upstream commit(s)); reconcile it manually. No merge was applied."
        }
        if ($ahead -gt 0) {
            throw "Branch '$($context.Branch)' has $ahead local-only/unpublished commit(s); publish or reconcile them separately. No merge was applied."
        }

        $incomingPaths = @(Get-ChangedPaths -OldHead $beforeHead -NewHead $newHead)
        if ($behind -gt 0) {
            Assert-SafeIncomingPaths -IncomingPaths $incomingPaths -UntrackedPaths $untracked -OldHead $beforeHead -RepositoryRoot $repositoryRoot
            $plannedComposerInstall = $hasComposer -and ($vendorAbsent -or ($incomingPaths -contains 'composer.json') -or ($incomingPaths -contains 'composer.lock'))
            $plannedNpmInstall = $hasPackage -and ($nodeModulesAbsent -or ($incomingPaths -contains 'package.json') -or ($incomingPaths -contains 'package-lock.json'))
            $plannedBuild = $hasPackage -and ($plannedComposerInstall -or $plannedNpmInstall -or (Test-FrontendInputsChanged $incomingPaths) -or $manifestAbsent)
            if ($plannedBuild -and (Test-Path -LiteralPath (Join-Path $repositoryRoot 'public/hot') -PathType Leaf)) {
                throw 'public/hot indicates an active Vite dev server, and the project build script removes that file. Stop the dev server and retry so its runtime marker is preserved.'
            }

            Write-Result "Applying safe fast-forward $beforeHead -> $newHead..."
            $merge = Invoke-Native -Name 'git' -Arguments @('merge', '--ff-only', '--no-edit', $newHead) -SuppressOutput
            if ($merge.ExitCode -ne 0) {
                throw "Fast-forward failed with exit code $($merge.ExitCode); no reset or cleanup was attempted. Current HEAD: $(Invoke-GitText @('rev-parse','--verify','HEAD'))."
            }
        } else {
            Write-Result 'Already current with the configured upstream.'
        }

        $plannedComposerInstall = $hasComposer -and ($vendorAbsent -or ($incomingPaths -contains 'composer.json') -or ($incomingPaths -contains 'composer.lock'))
        $plannedNpmInstall = $hasPackage -and ($nodeModulesAbsent -or ($incomingPaths -contains 'package.json') -or ($incomingPaths -contains 'package-lock.json'))
        $plannedBuild = $hasPackage -and ($plannedComposerInstall -or $plannedNpmInstall -or (Test-FrontendInputsChanged $incomingPaths) -or $manifestAbsent)
        if ($plannedBuild -and (Test-Path -LiteralPath (Join-Path $repositoryRoot 'public/hot') -PathType Leaf)) {
            throw 'public/hot indicates an active Vite dev server, and the project build script removes that file. Stop the dev server and retry so its runtime marker is preserved.'
        }

        $currentHead = Invoke-GitText @('rev-parse', '--verify', 'HEAD')
        $composerChanged = $incomingPaths -contains 'composer.json' -or $incomingPaths -contains 'composer.lock'
        $npmChanged = $incomingPaths -contains 'package.json' -or $incomingPaths -contains 'package-lock.json'
        $composerInstalled = $false
        $npmInstalled = $false

        if ($hasComposer -and ($composerChanged -or $vendorAbsent)) {
            Invoke-Phase -Phase 'Composer lockfile install (scripts and plugins disabled)' -Tool 'composer' -Arguments @('install', '--no-interaction', '--prefer-dist', '--no-scripts', '--no-plugins')
            $cacheDirectory = Join-Path $repositoryRoot 'bootstrap/cache'
            try {
                foreach ($manifestName in @('packages.php','services.php')) {
                    $manifestPath = Join-Path $cacheDirectory $manifestName
                    if (Test-Path -LiteralPath $manifestPath -PathType Leaf) {
                        Remove-Item -LiteralPath $manifestPath -Force
                    }
                }
            } catch {
                $headNow = Invoke-GitText @('rev-parse', '--verify', 'HEAD')
                throw "Targeted Laravel package manifest refresh failed: $($_.Exception.Message). Current HEAD: $headNow. No rollback was attempted."
            }
            Invoke-Phase -Phase 'Laravel package discovery (targeted manifests only)' -Tool 'php' -Arguments @('artisan', 'package:discover', '--ansi')
            Write-Result 'Composer asset publishing was skipped; review whether updated packages require an explicit asset publish.'
            $composerInstalled = $true
        } elseif ($hasComposer) {
            Write-Result 'Composer install skipped: composer files are unchanged and vendor is present.'
        }

        if ($hasPackage -and ($npmChanged -or $nodeModulesAbsent)) {
            Invoke-Phase -Phase 'npm lockfile install (lifecycle scripts disabled)' -Tool 'npm.cmd' -Arguments @('ci', '--ignore-scripts')
            $npmInstalled = $true
        } elseif ($hasPackage) {
            Write-Result 'npm ci skipped: package files are unchanged and node_modules is present.'
        }

        $frontendChanged = Test-FrontendInputsChanged $incomingPaths
        $buildOutputAbsent = $hasPackage -and -not (Test-Path -LiteralPath (Join-Path $repositoryRoot 'public/build/manifest.json') -PathType Leaf)
        $buildNeeded = $hasPackage -and ($frontendChanged -or $composerInstalled -or $npmInstalled -or $buildOutputAbsent)
        if ($buildNeeded) {
            Invoke-Phase -Phase 'Production frontend build' -Tool 'npm.cmd' -Arguments @('run', 'build')
        } elseif ($hasPackage) {
            Write-Result 'Production build skipped: frontend inputs are unchanged, dependencies are present, and the build manifest exists.'
        }

        $migrationPaths = @(Test-MigrationChanges $incomingPaths)
        if ($migrationPaths.Count -gt 0) {
            Write-Result 'Manual review required: incoming migration files were not run:'
            foreach ($migrationPath in $migrationPaths) { Write-Result "  $migrationPath" }
        }

        $updateState = if ($beforeHead -eq $currentHead) { 'already current' } else { 'updated' }
        Write-Result "Update complete: $updateState."
        Write-Result "Old commit: $beforeHead"
        Write-Result "New commit: $currentHead"
        if (-not $composerInstalled) { Write-Result 'Composer: skipped (unchanged/present or unused).' }
        if (-not $npmInstalled) { Write-Result 'npm: skipped (unchanged/present or unused).' }
        if (-not $buildNeeded) { Write-Result 'Build: skipped (inputs unchanged and output present).' }
        if ($migrationPaths.Count -eq 0) { Write-Result 'Migrations: no incoming migration files.' }
        Write-Result 'No migrations, seeders, database operations, jobs, notifications, or external archive actions were run. Only Laravel package/provider manifests were refreshed; cached config was preserved.'
    }
    finally {
        Pop-Location
    }
}

try {
    Invoke-ProjectUpdate
} catch {
    [Console]::Error.WriteLine("Update stopped: $($_.Exception.Message)")
    exit 1
}
