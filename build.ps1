param(
	[string]$Output = (Join-Path $PSScriptRoot 'build\onespace-toolkit.zip')
)

$ErrorActionPreference = 'Stop'

$php = Get-Command php -ErrorAction SilentlyContinue

if ($php) {
	& php (Join-Path $PSScriptRoot 'build.php') $Output

	if ($LASTEXITCODE -ne 0) {
		throw 'Build failed.'
	}

	return
}

$stageRoot = Join-Path $env:TEMP 'onespace-toolkit-stage'
$stagePlugin = Join-Path $stageRoot 'onespace-toolkit'

if (Test-Path $stageRoot) {
	Remove-Item -LiteralPath $stageRoot -Recurse -Force
}

New-Item -ItemType Directory -Path $stagePlugin -Force | Out-Null

robocopy $PSScriptRoot $stagePlugin /E /XD build .git .vscode /XF *.zip install.ps1 build.ps1 build.php .gitignore /NFL /NDL /NJH /NJS /NP | Out-Null

$outputDir = Split-Path -Parent $Output
if (!(Test-Path $outputDir)) {
	New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

if (Test-Path $Output) {
	Remove-Item -LiteralPath $Output -Force
}

Compress-Archive -Path $stagePlugin -DestinationPath $Output -Force
Remove-Item -LiteralPath $stageRoot -Recurse -Force

Write-Host "Built: $Output"
