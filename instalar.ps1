# Instalador del Actualizador de precios (Windows / PowerShell)
# Uso: clic derecho > "Ejecutar con PowerShell", o en la terminal:  powershell -ExecutionPolicy Bypass -File .\instalar.ps1

# 'Continue': los avisos de PHP y el progreso de composer salen por stderr y no tienen que frenar el script.
# Los errores reales se controlan con $LASTEXITCODE y -ErrorAction Stop.
$ErrorActionPreference = 'Continue'
Set-Location $PSScriptRoot

function Paso($t) { Write-Host "`n==> $t" -ForegroundColor Cyan }
function Ok($t)   { Write-Host "    OK  $t" -ForegroundColor Green }
function Aviso($t){ Write-Host "    !!  $t" -ForegroundColor Yellow }

function Get-EnvValue($key) {
    $c = [IO.File]::ReadAllText("$PSScriptRoot\.env")
    $m = [regex]::Match($c, "(?m)^$key=(.*)$")
    if ($m.Success) { return $m.Groups[1].Value.Trim().Trim("'").Trim('"') } else { return '' }
}
function Set-EnvValue($key, $value) {
    $ruta = "$PSScriptRoot\.env"
    $c = [IO.File]::ReadAllText($ruta)
    if ($value -match '[\s#\\]') { $value = "'$value'" }   # comillas simples: sin escapes
    $linea = "$key=$value"
    if ([regex]::IsMatch($c, "(?m)^$key=.*$")) {
        $c = [regex]::Replace($c, "(?m)^$key=.*$", [System.Text.RegularExpressions.MatchEvaluator]{ param($m) $linea })
    } else {
        $c = $c.TrimEnd() + "`n$linea`n"
    }
    [IO.File]::WriteAllText($ruta, $c, (New-Object System.Text.UTF8Encoding $false))
}

# ---------------------------------------------------------------- PHP
Paso "Revisando PHP"
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw "No encuentro PHP. Instala PHP 8.2 o superior (https://windows.php.net/download) y agregalo al PATH."
}
# los avisos de PHP ("Module ... already loaded") salen mezclados: nos quedamos solo con la linea de la version
$ver = (php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>$null) | Where-Object { $_ -match '^\d+\.\d+$' } | Select-Object -Last 1
if (-not $ver) { throw "No pude leer la version de PHP." }
if ([version]$ver -lt [version]'8.2') { throw "Tenes PHP $ver. Hace falta PHP 8.2 o superior." }
Ok "PHP $ver"

$mods = (php -m 2>$null) | ForEach-Object { "$_".Trim().ToLower() }
$faltan = @('pdo_sqlite','zip','fileinfo','mbstring','gd','xml','curl','openssl') | Where-Object { $mods -notcontains $_ }
if ($faltan) {
    Aviso ("Faltan extensiones de PHP: " + ($faltan -join ', '))
    $ini = (php -r "echo php_ini_loaded_file();" 2>$null) | Where-Object { $_ -match '\.ini$' } | Select-Object -Last 1
    Aviso ("Activalas en el php.ini (sacando el ';' de 'extension=...'). Archivo: " + $ini)
    throw "Activa las extensiones y volve a correr el instalador."
}
Ok "Extensiones de PHP"

# ---------------------------------------------------------------- Composer
Paso "Instalando dependencias (composer install)"
if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    throw "No encuentro Composer. Instalalo desde https://getcomposer.org/Composer-Setup.exe"
}
composer install --no-interaction
if ($LASTEXITCODE -ne 0) { throw "composer install fallo (mira el error de arriba)." }
Ok "vendor/ listo"

# ---------------------------------------------------------------- .env y base
Paso "Configurando .env y base de datos"
if (-not (Test-Path .env)) { Copy-Item .env.example .env; Ok ".env creado" }
if ((Get-EnvValue 'APP_KEY') -eq '') { php artisan key:generate --force | Out-Null; Ok "APP_KEY generada" }
if (-not (Test-Path database\database.sqlite)) { New-Item database\database.sqlite -ItemType File | Out-Null }
php artisan migrate --force --no-interaction
if ($LASTEXITCODE -ne 0) { throw "Fallo la migracion." }
Ok "Base SQLite lista (database\database.sqlite)"
# Odoo viejo (facturas por foto): su propia base
if (-not (Test-Path database\viejo.sqlite)) { New-Item database\viejo.sqlite -ItemType File | Out-Null }
php artisan migrate --database=sqlite_viejo --force --no-interaction
if ($LASTEXITCODE -ne 0) { throw "Fallo la migracion de la base del Odoo viejo." }
Ok "Base del Odoo viejo lista (database\viejo.sqlite)"

# ---------------------------------------------------------------- pdftotext
Paso "Lector de PDF (pdftotext)"
$pdf = Get-EnvValue 'PDFTOTEXT_PATH'
$tiene = ($pdf -and (Test-Path $pdf)) -or (Get-Command pdftotext -ErrorAction SilentlyContinue)
if (-not $tiene) {
    $existente = Get-ChildItem "$PSScriptRoot\tools\poppler" -Recurse -Filter pdftotext.exe -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $existente) {
        Write-Host "    Descargando poppler para Windows..."
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        $rel = Invoke-RestMethod 'https://api.github.com/repos/oschwartz10612/poppler-windows/releases/latest' -Headers @{ 'User-Agent' = 'instalador' } -ErrorAction Stop
        $asset = $rel.assets | Where-Object { $_.name -like '*.zip' } | Select-Object -First 1
        New-Item "$PSScriptRoot\tools" -ItemType Directory -Force | Out-Null
        $zip = "$PSScriptRoot\tools\poppler.zip"
        Invoke-WebRequest $asset.browser_download_url -OutFile $zip -UseBasicParsing -ErrorAction Stop
        Expand-Archive $zip -DestinationPath "$PSScriptRoot\tools\poppler" -Force -ErrorAction Stop
        Remove-Item $zip
        $existente = Get-ChildItem "$PSScriptRoot\tools\poppler" -Recurse -Filter pdftotext.exe | Select-Object -First 1
    }
    Set-EnvValue 'PDFTOTEXT_PATH' $existente.FullName
    Ok ("pdftotext: " + $existente.FullName)
} else {
    Ok "pdftotext ya disponible"
}

# ---------------------------------------------------------------- Odoo
Paso "Datos de Odoo"
if ((Get-EnvValue 'ODOO_USER') -eq '') {
    $u = Read-Host "    Usuario (email) de Odoo"
    Set-EnvValue 'ODOO_USER' $u
}
if ((Get-EnvValue 'ODOO_API_KEY') -eq '') {
    Write-Host "    La clave API se crea en Odoo: Mis preferencias > Seguridad de la cuenta > Nueva clave API"
    $k = Read-Host "    Clave API de Odoo"
    Set-EnvValue 'ODOO_API_KEY' $k
}
php artisan config:clear | Out-Null
Ok ("Odoo: " + (Get-EnvValue 'ODOO_URL') + "  base: " + (Get-EnvValue 'ODOO_DB'))

# ---------------------------------------------------------------- Usuario
Paso "Usuario para entrar al sistema"
$email = Read-Host "    Email (Enter = admin@precios)"
if (-not $email) { $email = 'admin@precios' }
$pass = Read-Host "    Clave"
php artisan usuario:crear $email $pass "Administrador"
if ($LASTEXITCODE -ne 0) { throw "No se pudo crear el usuario." }

# ---------------------------------------------------------------- Catalogo
Paso "Bajando catalogo y categorias de Odoo"
php artisan odoo:sincronizar
if ($LASTEXITCODE -ne 0) { Aviso "No se pudo conectar con Odoo. Revisa ODOO_USER / ODOO_API_KEY en el .env y despues toca 'Actualizar catalogo' en la pantalla." }

Paso "Listo"
Write-Host "    Para usarlo: doble clic en iniciar.bat  ->  http://127.0.0.1:8000" -ForegroundColor Green
Write-Host "    Entras con: $email" -ForegroundColor Green
