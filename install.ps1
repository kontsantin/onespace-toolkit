param(
	[Parameter(Mandatory = $true)]
	[string]$Path,
	[string]$WpCli = '',
	[string]$Url = ''
)

$ErrorActionPreference = 'Stop'

if (!(Test-Path (Join-Path $Path 'wp-config.php'))) {
	throw "WordPress not found: $Path"
}

if ([string]::IsNullOrWhiteSpace($WpCli)) {
	$WpCli = Join-Path $env:TEMP 'opencode\wp-cli.phar'

	if (!(Test-Path $WpCli)) {
		$dir = Split-Path -Parent $WpCli
		if (!(Test-Path $dir)) {
			New-Item -ItemType Directory -Path $dir -Force | Out-Null
		}
		Invoke-WebRequest -Uri 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar' -OutFile $WpCli -UseBasicParsing
	}
}

$zip = Join-Path $PSScriptRoot 'build\onespace-toolkit.zip'
& powershell -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'build.ps1') -Output $zip
if ($LASTEXITCODE -ne 0) {
	throw 'Build failed.'
}

php $WpCli --path=$Path plugin install $zip --activate --force
if ($LASTEXITCODE -ne 0) {
	throw 'Plugin install failed.'
}

php $WpCli --path=$Path option update onespace_toolkit_translit 1
php $WpCli --path=$Path option update onespace_toolkit_cf_fix 1
if (![string]::IsNullOrWhiteSpace($Url)) {
	php $WpCli --path=$Path option update home $Url
	php $WpCli --path=$Path option update siteurl $Url
}

php $WpCli --path=$Path plugin list --status=active

Write-Host 'Done: OneSpace Toolkit installed and activated.'
