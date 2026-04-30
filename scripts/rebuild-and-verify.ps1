param(
    [string]$Service = "sam-books-lib",
    [string]$LocalFile = "site/assets/css/app.css",
    [string]$ContainerFile = "/var/www/html/assets/css/app.css"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Get-LowerHash([string]$path) {
    return (Get-FileHash -Algorithm SHA256 -Path $path).Hash.ToLowerInvariant()
}

Write-Host "==> Build image from absolute host path..."
docker build --no-cache -t sam/bookslib:latest -f D:/project/books/Dockerfile D:/project/books | Out-Host

Write-Host "==> Recreate container service: $Service ..."
docker compose up -d --force-recreate $Service | Out-Host

Write-Host "==> Compare version hash..."
$localHash = Get-LowerHash $LocalFile
$containerHash = (docker compose exec $Service sh -lc "sha256sum '$ContainerFile' | cut -d ' ' -f1").Trim().ToLowerInvariant()

Write-Host ("Local     : {0}  {1}" -f $localHash, $LocalFile)
Write-Host ("Container : {0}  {1}" -f $containerHash, $ContainerFile)

if ($localHash -ne $containerHash) {
    Write-Error "Version mismatch after rebuild."
    exit 1
}

Write-Host "Version verified: container is up-to-date."
