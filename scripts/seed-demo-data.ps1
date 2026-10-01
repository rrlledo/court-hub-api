[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$backendPath = Split-Path -Parent $PSScriptRoot

Push-Location $backendPath
try {
    php artisan court-hub:demo-data
}
finally {
    Pop-Location
}
