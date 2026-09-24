<#
.SYNOPSIS
    Packs the plugin folder into artifacts/deployseal-<version>.zip, ready for
    Plugins -> Add New -> Upload Plugin and for the WordPress.org SVN import.

.DESCRIPTION
    Portable: runs on Windows PowerShell 5.1 and PowerShell 7 on Linux/macOS.

      1. reads the version from the plugin header in deployseal/deployseal.php and checks that the
         DEPLOYSEAL_VERSION constant and readme.txt's Stable tag agree with it,
      2. zips deployseal/ so the archive root holds one folder, "deployseal/", with entry names
         using forward slashes. (Compress-Archive on Windows PowerShell writes backslashes, which
         unzip on Linux - and therefore WordPress's upgrader on most hosts - turns into flat
         files named "deployseal\deployseal.php"; this script writes the entries itself through
         System.IO.Compression instead.)
      3. prints the SHA-256 of the zip.

.EXAMPLE
    ./build/build.ps1
#>
[CmdletBinding()]
param(
    [string]$OutDir
)

$ErrorActionPreference = "Stop"
$repo = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$pluginDir = Join-Path $repo "deployseal"
if (-not $OutDir) { $OutDir = Join-Path $repo "artifacts" }

# --- 1. Version, and the three places that must agree ---------------------------------------------
$main = Get-Content (Join-Path $pluginDir "deployseal.php") -Raw
$headerVersion = [regex]::Match($main, "(?m)^\s*\*\s*Version:\s*(\S+)").Groups[1].Value
$constVersion = [regex]::Match($main, "define\(\s*'DEPLOYSEAL_VERSION',\s*'([^']+)'").Groups[1].Value
$readme = Get-Content (Join-Path $pluginDir "readme.txt") -Raw
$stableTag = [regex]::Match($readme, "(?m)^Stable tag:\s*(\S+)").Groups[1].Value
if (-not $headerVersion) { throw "No 'Version:' in the plugin header." }
if ($constVersion -ne $headerVersion) { throw "DEPLOYSEAL_VERSION ($constVersion) does not match the header Version ($headerVersion). Run build/bump.ps1." }
if ($stableTag -ne $headerVersion) { throw "readme.txt Stable tag ($stableTag) does not match the header Version ($headerVersion). Run build/bump.ps1." }
$version = $headerVersion
Write-Host "DeploySeal for WordPress $version"

# --- 2. Zip with forward slashes, plugin folder at the root ---------------------------------------
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$zipPath = Join-Path $OutDir "deployseal-$version.zip"
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

$files = Get-ChildItem -Path $pluginDir -Recurse -File | Where-Object { $_.Name -notmatch '^\.(DS_Store|gitkeep)$' } | Sort-Object FullName
$stream = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::CreateNew)
try {
    $zip = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($file in $files) {
            $relative = $file.FullName.Substring($pluginDir.Length).TrimStart('\', '/') -replace '\\', '/'
            $entryName = "deployseal/$relative"
            $entry = $zip.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
            $entry.LastWriteTime = [DateTimeOffset]$file.LastWriteTimeUtc
            $in = [System.IO.File]::OpenRead($file.FullName)
            $out = $entry.Open()
            try { $in.CopyTo($out) } finally { $out.Dispose(); $in.Dispose() }
        }
    } finally { $zip.Dispose() }
} finally { $stream.Dispose() }

# --- 3. Check and report ---------------------------------------------------------------------------
$check = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $names = @($check.Entries | ForEach-Object { $_.FullName })
} finally { $check.Dispose() }
if ($names | Where-Object { $_ -match '\\' }) { throw "A zip entry contains a backslash." }
if ($names | Where-Object { -not $_.StartsWith("deployseal/") }) { throw "A zip entry is outside deployseal/." }
if ($names -notcontains "deployseal/deployseal.php") { throw "deployseal/deployseal.php is missing from the zip." }
if ($names -notcontains "deployseal/readme.txt") { throw "deployseal/readme.txt is missing from the zip." }

$hash = (Get-FileHash -Algorithm SHA256 $zipPath).Hash.ToLowerInvariant()
Write-Host ("{0} ({1} files, {2:N0} bytes)" -f $zipPath, $names.Count, (Get-Item $zipPath).Length)
Write-Host "sha256 $hash"
