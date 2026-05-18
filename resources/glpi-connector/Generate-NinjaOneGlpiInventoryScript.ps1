param(
    [string] $GlpiBaseUrl = 'https://support.tinisys.fr',
    [string] $GlpiInventoryUrl = '',
    [string] $InventoryTag = 'NinjaOne',
    [string] $AgentZipSource = 'https://github.com/glpi-project/glpi-agent/releases/download/1.17/GLPI-Agent-1.17-x64.zip',
    [string] $OutputPath = ''
)

$ErrorActionPreference = 'Stop'

$templatePath = Join-Path $PSScriptRoot 'templates\Invoke-GlpiPortableInventory.ps1'
if (-not (Test-Path -LiteralPath $templatePath)) {
    throw "Template not found: $templatePath"
}

function ConvertTo-PowerShellSingleQuotedLiteral {
    param([string] $Value)

    return "'" + ($Value -replace "'", "''") + "'"
}

$content = Get-Content -LiteralPath $templatePath -Raw
$replacements = @{
    '$GlpiBaseUrl = ''https://support.tinisys.fr''' = '$GlpiBaseUrl = ' + (ConvertTo-PowerShellSingleQuotedLiteral $GlpiBaseUrl)
    '$GlpiInventoryUrl = ''''' = '$GlpiInventoryUrl = ' + (ConvertTo-PowerShellSingleQuotedLiteral $GlpiInventoryUrl)
    '$InventoryTag = ''NinjaOne''' = '$InventoryTag = ' + (ConvertTo-PowerShellSingleQuotedLiteral $InventoryTag)
    '$AgentZipSource = ''https://github.com/glpi-project/glpi-agent/releases/download/1.17/GLPI-Agent-1.17-x64.zip''' = '$AgentZipSource = ' + (ConvertTo-PowerShellSingleQuotedLiteral $AgentZipSource)
}

foreach ($key in $replacements.Keys) {
    $content = $content.Replace($key, $replacements[$key])
}

if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $content
    exit 0
}

$parent = Split-Path -Parent $OutputPath
if (-not [string]::IsNullOrWhiteSpace($parent)) {
    New-Item -ItemType Directory -Path $parent -Force | Out-Null
}

Set-Content -LiteralPath $OutputPath -Value $content -Encoding UTF8
Write-Output "Generated NinjaOne GLPI inventory script: $OutputPath"
