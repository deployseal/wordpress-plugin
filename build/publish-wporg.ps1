<#
.SYNOPSIS
    Publishes the plugin to the WordPress.org plugin directory over Subversion
    (https://plugins.svn.wordpress.org/deployseal): trunk, tags/<version>, assets.

.DESCRIPTION
    Needs Docker (the svn client runs in an alpine container; nothing is installed on
    the machine) and your WordPress.org SVN password, which the script prompts for and
    passes to the container through its environment only. Nothing is echoed or logged.

      1. checks out the SVN repository into artifacts/wporg-wc,
      2. replaces trunk/ with deployseal/ (additions and removals included),
      3. replaces assets/ with .wordpress-org/ (banners, icons, screenshots),
      4. copies trunk to tags/<version> unless that tag already exists,
      5. with -DryRun prints `svn status` and stops; otherwise commits as "deployseal".

    WordPress.org reads readme.txt from tags/<Stable tag>, so a readme change needs a
    new tag (or a -RetagExisting run, which replaces tags/<version> in place; use it
    only for readme/asset corrections, never to change shipped code).

.EXAMPLE
    ./build/publish-wporg.ps1 -DryRun
    ./build/publish-wporg.ps1 -Message "1.0.1: first release on WordPress.org"
#>
[CmdletBinding()]
param(
    [string]$Message,
    [switch]$DryRun,
    [switch]$RetagExisting
)

$ErrorActionPreference = "Stop"
$repo = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$header = Get-Content (Join-Path $repo "deployseal/deployseal.php") -Raw
if ($header -notmatch "(?m)^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)") { throw "No Version header in deployseal.php" }
$version = $Matches[1]
$readme = Get-Content (Join-Path $repo "deployseal/readme.txt") -Raw
if ($readme -notmatch "(?m)^Stable tag:\s*$([regex]::Escape($version))\s*$") { throw "readme.txt Stable tag does not match plugin version $version" }
if (-not $Message) { $Message = "$version" }

$wc = Join-Path $repo "artifacts/wporg-wc"
New-Item -ItemType Directory -Force (Join-Path $repo "artifacts") | Out-Null
if (Test-Path $wc) { Remove-Item -Recurse -Force $wc }

$password = $null
if (-not $DryRun) {
    $secure = Read-Host -AsSecureString "WordPress.org SVN password for user deployseal"
    $password = [Runtime.InteropServices.Marshal]::PtrToStringUni([Runtime.InteropServices.Marshal]::SecureStringToGlobalAllocUnicode($secure))
    if (-not $password) { throw "No password given" }
}
$env:SVN_PASSWORD = $password

$mode = if ($DryRun) { "dry" } elseif ($RetagExisting) { "retag" } else { "commit" }
$script = @'
set -e
apk add -q subversion rsync >/dev/null 2>&1
cd /work
svn co -q --non-interactive https://plugins.svn.wordpress.org/deployseal wc
cd wc
rsync -a --delete --exclude .svn /src/deployseal/ trunk/
rsync -a --delete --exclude .svn /src/.wordpress-org/ assets/
if [ -d "tags/$VERSION" ]; then
  if [ "$MODE" = "retag" ]; then rsync -a --delete --exclude .svn trunk/ "tags/$VERSION/"; else echo "tags/$VERSION already exists; trunk and assets only"; fi
else
  svn cp -q trunk "tags/$VERSION"
fi
svn add -q --force . >/dev/null
svn status | awk '$1=="!"{print $2}' | xargs -r svn rm -q
find assets -name '*.png' -print0 | xargs -0 -r svn propset -q svn:mime-type image/png
find assets -name '*.jpg' -print0 | xargs -0 -r svn propset -q svn:mime-type image/jpeg
echo "--- svn status"
svn status
if [ "$MODE" = "dry" ]; then echo "--- dry run: nothing committed"; exit 0; fi
svn ci -q --non-interactive --no-auth-cache --username deployseal --password "$SVN_PASSWORD" -m "$MESSAGE"
echo "--- committed; https://wordpress.org/plugins/deployseal updates within a few minutes"
'@
$scriptPath = Join-Path $repo "artifacts/wporg-publish.sh"
[IO.File]::WriteAllText($scriptPath, ($script -replace "`r`n", "`n"))

$env:MSYS_NO_PATHCONV = "1"
$srcMount = $repo -replace "\\", "/"
$artMount = (Join-Path $repo "artifacts") -replace "\\", "/"
try {
    docker run --rm -e SVN_PASSWORD -e "VERSION=$version" -e "MODE=$mode" -e "MESSAGE=$Message" `
        -v "${srcMount}:/src:ro" -v "${artMount}:/work" alpine sh /work/wporg-publish.sh
    if ($LASTEXITCODE -ne 0) { throw "svn publish failed (exit $LASTEXITCODE)" }
} finally {
    $env:SVN_PASSWORD = $null
    Remove-Item -Force $scriptPath -ErrorAction SilentlyContinue
}
