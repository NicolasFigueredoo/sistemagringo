# Actualizador de precios → Odoo

Sistema web chico (Laravel + Livewire) para que el cliente suba la lista del proveedor (Excel o PDF), revise y ajuste los precios, y los mande a Odoo con un botón. **Sin IA: costo de uso cero.**

## Flujo del cliente

1. **Cargar lista**: elige el proveedor, sube el Excel o PDF y confirma las columnas (se adivinan solas y quedan guardadas por proveedor). Pone el descuento, la ganancia, si la lista trae IVA, si suma IVA a la venta y el redondeo.
2. **Revisar**: ve todos los productos con colores:
   - 🟢 **Encontrado**: por código de barras, o porque ya se asoció antes. Se actualiza solo.
   - 🟡 **Confirmar**: nombre idéntico. Hay que tildarlo.
   - 🟠 **Elegir**: muestra hasta 3 parecidos y el cliente toca el correcto. También hay un buscador.
   - ⚪ **No lo vende**: está en la lista del proveedor, pero no en Odoo. Se oculta por defecto.
   - 🔴 **Cambio grande**: el costo varió más de un 30%. Conviene revisarlo.

   Puede cambiar el % o el precio de cada fila, aplicar un % a todos, o usar el bloque **Por categoría**.
3. **Actualizar en Odoo**: pide confirmación, escribe `standard_price` y `list_price`, y guarda el historial.
4. **Historial**: botón **Deshacer** para volver a los precios anteriores.

Cada asociación que el cliente confirma a mano queda guardada (por proveedor + código), así la próxima vez sale en 🟢.

## Prioridad de los %

1. El precio escrito a mano en la revisión.
2. La **categoría de Odoo** del producto (sección *Categorías*, o el bloque *Por categoría* con "Recordar").
3. El proveedor (ganancia habitual) y el descuento general de la carga.

"Ganancia % para todos" pisa todo lo anterior en esa carga.

## Instalación en Windows (para probar)

Requisitos: **PHP 8.2+** y **Composer** en el PATH.

1. Descomprimí la carpeta.
2. En la terminal, dentro de la carpeta:
   ```powershell
   powershell -ExecutionPolicy Bypass -File .\instalar.ps1
   ```
   El script hace todo: `composer install`, `.env`, la base SQLite, las migraciones, descarga `pdftotext` (poppler), te pide el usuario y la API key de Odoo, crea tu usuario y baja el catálogo.
3. Doble clic en **`iniciar.bat`** y abrí http://127.0.0.1:8000

Si `composer install` se queja de una extensión, activala en el `php.ini`: `zip`, `gd`, `pdo_sqlite`, `fileinfo` y `mbstring`. El script te dice cuál falta y dónde está el `php.ini`.

## Instalación en servidor Linux (producción)

```bash
apt install poppler-utils
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# completar ODOO_USER / ODOO_API_KEY (y DB si usás MySQL)
php artisan migrate --force
php artisan usuario:crear cliente@ferreteria CLAVE "Tio Gringo"
php artisan odoo:sincronizar
```

- **php.ini:** `upload_max_filesize=50M`, `post_max_size=55M`, `max_execution_time=300`. La lista de Ferriplast pesa 2,7 MB y el límite por defecto es de 2 MB.
- **Cron**, para que el catálogo se sincronice solo cada mañana:
  ```
  * * * * * cd /ruta/proyecto && php artisan schedule:run >> /dev/null 2>&1
  ```
- **HTTPS obligatorio:** el login es HTTP Basic.

## Comandos útiles

| Comando | Qué hace |
|---|---|
| `php artisan odoo:sincronizar` | Baja productos y categorías de Odoo |
| `php artisan usuario:crear email clave "Nombre"` | Crea un usuario o le cambia la clave |
| `php tests-standalone/test.php` | Pruebas de lectura, matching y precios |

## Antes de usarlo en producción

- **La regla automática de Odoo** que recalcula `list_price` cuando cambia el costo: desactivala. Si no, pisa el precio que manda este sistema.
- **IVA en el precio de venta**: fijate si el impuesto de venta en Odoo está como "incluido en el precio". Si está incluido, dejá tildado "Sumar IVA". Si no, destildalo.

## Archivos

| Archivo | Qué hace |
|---|---|
| `app/Services/LectorLista.php` | Lee Excel/CSV/PDF. Los PDF se leen renglón por renglón (código, descripción, unidad, precio) |
| `app/Services/Matcher.php` | Busca el producto: barcode → asociación guardada → nombre idéntico → parecidos (medidas idénticas obligatorias) |
| `app/Services/Precios.php` | Cuentas: costo, venta, IVA, redondeo, números en formato argentino |
| `app/Services/Procesador.php` | Arma la carga, aplica reglas por categoría y escribe en Odoo / deshace |
| `app/Services/OdooClient.php` | JSON-RPC con API key |
| `app/Livewire/*` | Pantallas: Cargar lista, Revisar, Categorías, Historial |
| `tests-standalone/test.php` | Pruebas de la lógica sin Laravel: `php tests-standalone/test.php` |

## Probado con

- Lista Ferriplast 02/10/2026 (98 páginas): 6.014 de 6.014 renglones leídos.
- Lista agosto 2026 (discos, brocas, etc.): 965 renglones. No toma los ~20 productos que tienen la descripción partida en dos renglones.
