@echo off
cd /d "%~dp0public"
echo Actualizador de precios - http://127.0.0.1:8000
echo (cerrar esta ventana para apagarlo)
start "" http://127.0.0.1:8000
php -d upload_max_filesize=50M -d post_max_size=55M -d max_execution_time=300 -d memory_limit=512M -S 127.0.0.1:8000 "..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php"
pause