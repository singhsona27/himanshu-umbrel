$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

if (Test-Path -LiteralPath ".env") {
  Write-Host ".env already exists. Move it aside first if you want to regenerate credentials."
  exit 0
}

Copy-Item -LiteralPath ".env.example" -Destination ".env"
$bytes = New-Object byte[] 36
[Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
$pass = [Convert]::ToBase64String($bytes).Replace("/", "_").Replace("&", "_")
$dashBytes = New-Object byte[] 36
[Security.Cryptography.RandomNumberGenerator]::Fill($dashBytes)
$dashPass = [Convert]::ToBase64String($dashBytes).Replace("/", "_").Replace("&", "_")
$content = Get-Content -LiteralPath ".env" -Raw
$content = [regex]::Replace($content, "CHANGE_ME_GENERATE_A_LONG_RANDOM_VALUE", $pass, 1)
$content = [regex]::Replace($content, "CHANGE_ME_GENERATE_A_LONG_RANDOM_VALUE", $dashPass, 1)
Set-Content -LiteralPath ".env" -Value $content -Encoding ASCII
Write-Host "Created .env with random RPC credentials."
Write-Host "Dashboard access is protected by Umbrel's app proxy."
Write-Host "Set DGB_MINING_ADDRESS from the dashboard settings or edit .env before mining."
