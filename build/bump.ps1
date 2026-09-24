<#
.SYNOPSIS
    Sets the plugin version everywhere it is written, together.

.DESCRIPTION
    The version lives in four places that must agree:
      - the "Version:" line of the plugin header (deployseal/deployseal.php),
      - the DEPLOYSEAL_VERSION constant (same file) - the tag's data-ds-installer
        "wordpress-plugin/<version>" is built from it,
      - "Stable tag:" in deployseal/readme.txt,
      - PLUGIN_VERSION in tests/e2e/verify.mjs (the installer string the end-to-end test expects).
    Add the changelog entry (readme.txt "== Changelog ==" and CHANGELOG.md) by hand.

.EXAMPLE
    ./build/bump.ps1 -Version 1.0.1
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string]$Version
)

$ErrorActionPreference = "Stop"
$repo = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Update-File([string]$relative, [string]$pattern, [string]$replacement) {
    $path = Join-Path $repo $relative
    $text = [System.IO.File]::ReadAllText($path)
    $regex = New-Object System.Text.RegularExpressions.Regex($pattern, [System.Text.RegularExpressions.RegexOptions]::Multiline)
    if (-not $regex.IsMatch($text)) { throw "Pattern not found in ${relative}: $pattern" }
    $text = $regex.Replace($text, $replacement, 1)
    [System.IO.File]::WriteAllText($path, $text, $utf8)
    Write-Host "  $relative"
}

Write-Host "Setting version $Version in:"
Update-File "deployseal/deployseal.php" "^(\s*\*\s*Version:\s*)\S+" "`${1}$Version"
Update-File "deployseal/deployseal.php" "(define\(\s*'DEPLOYSEAL_VERSION',\s*')[^']+(')" "`${1}$Version`${2}"
Update-File "deployseal/readme.txt" "^(Stable tag:\s*)\S+" "`${1}$Version"
Update-File "tests/e2e/verify.mjs" "^(const PLUGIN_VERSION = ')[^']+(';)" "`${1}$Version`${2}"
Write-Host "Installer string is now wordpress-plugin/$Version. Add the changelog entries, then build/build.ps1."
