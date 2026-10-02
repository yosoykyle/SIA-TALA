$ErrorActionPreference = 'Stop'
$previewRoot = $PSScriptRoot
$layoutRoot = Split-Path $previewRoot -Parent
$dependencyRoot = if ($env:TALA_DEPENDENCY_ROOT) { $env:TALA_DEPENDENCY_ROOT } else { 'C:/C SCHOOL/1st_SEM_Resources/Fundamentals_of_Research/GROUP/ACTIVITIES/SIA-TALA' }
if (-not (Test-Path -LiteralPath (Join-Path $dependencyRoot 'vendor/autoload.php'))) {
    throw 'Set TALA_DEPENDENCY_ROOT to a compatible Laravel/Filament installation with vendor packages and published framework assets.'
}
foreach ($directory in @('bootstrap/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache', 'storage/logs', 'public/assets', 'public/css', 'public/js', 'public/fonts')) {
    New-Item -ItemType Directory -Path (Join-Path $previewRoot $directory) -Force | Out-Null
}
foreach ($kind in @('css', 'js', 'fonts')) {
    $source = Join-Path $dependencyRoot "public/$kind/filament"
    if (-not (Test-Path -LiteralPath $source)) { throw "Missing published framework assets: $source" }
    Copy-Item -LiteralPath $source -Destination (Join-Path $previewRoot "public/$kind") -Recurse -Force
}
foreach ($name in @('InterVariable.woff2', 'Inter-LICENSE.txt', 'servitech-crest.webp', 'talalogo.png', 'bootstrap.min.css', 'bootstrap.bundle.min.js')) {
    Copy-Item -LiteralPath (Join-Path $layoutRoot "assets/$name") -Destination (Join-Path $previewRoot "public/assets/$name") -Force
}
foreach ($name in @('bootstrap-theme.css', 'public-demo.js')) {
    Copy-Item -LiteralPath (Join-Path $previewRoot "public/assets/$name") -Destination (Join-Path $layoutRoot "assets/$name") -Force
}
Copy-Item -LiteralPath (Join-Path $layoutRoot 'public-example.html') -Destination (Join-Path $layoutRoot 'index.html') -Force
Copy-Item -LiteralPath (Join-Path $layoutRoot 'public-example.html') -Destination (Join-Path $previewRoot 'resources/views/public.blade.php') -Force
Write-Output 'Native preview assets and public-page mirrors prepared. No dependency source files were changed.'
