param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $ComposerArguments
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$toolsDirectory = Join-Path $projectRoot '.tools'
$composerPath = Join-Path $toolsDirectory 'composer.phar'

if (-not (Test-Path -LiteralPath $composerPath)) {
    New-Item -ItemType Directory -Path $toolsDirectory -Force | Out-Null
    $downloadPath = Join-Path $toolsDirectory 'composer.download'
    Invoke-WebRequest 'https://getcomposer.org/composer-stable.phar' -OutFile $downloadPath
    $checksum = (Invoke-RestMethod 'https://getcomposer.org/composer-stable.phar.sha256sum').Split(' ')[0].Trim()
    $actual = (Get-FileHash -LiteralPath $downloadPath -Algorithm SHA256).Hash.ToLowerInvariant()

    if ($actual -ne $checksum) {
        throw 'Composer download failed SHA-256 verification.'
    }

    Move-Item -LiteralPath $downloadPath -Destination $composerPath
}

& php $composerPath @ComposerArguments
exit $LASTEXITCODE
