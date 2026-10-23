#!/usr/bin/env python3
"""
Aplica % de ganancia por categoría en Odoo:
  precio_venta = costo * (1 + % / 100)
- Si la categoría no existe, la crea.
- Solo modifica list_price (precio de venta). El costo no se toca.
- Por defecto corre en modo prueba (DRY_RUN = True): muestra qué haría sin escribir nada.

Uso:
  python3 aplicar_porcentajes.py              -> prueba
  python3 aplicar_porcentajes.py --aplicar    -> escribe en Odoo
"""
import csv
import sys
import xmlrpc.client

# ===== CONFIG =====
URL = "https://ferreteriatiogringo.odoo.com"
DB = "ferreteriatiogringo"
USER = "jyuli83@hotmail.com"
API_KEY = "25a6a2dd582d5eb7e476a0da406c0bb142387f10"          # Preferencias del usuario > Seguridad de la cuenta > Nueva clave API
CSV_PATH = "categorias_porcentajes.csv"
INCLUIR_SUBCATEGORIAS = False   # True = aplica también a productos de subcategorías
SALTEAR_COSTO_CERO = True
# ==================

DRY_RUN = "--aplicar" not in sys.argv

common = xmlrpc.client.ServerProxy(f"{URL}/xmlrpc/2/common")
uid = common.authenticate(DB, USER, API_KEY, {})
if not uid:
    sys.exit("Error de login: revisá URL, DB, usuario y API key.")
models = xmlrpc.client.ServerProxy(f"{URL}/xmlrpc/2/object", allow_none=True)


def call(model, method, *args, **kw):
    return models.execute_kw(DB, uid, API_KEY, model, method, list(args), kw)


def buscar_o_crear_categoria(nombre):
    ids = call("product.category", "search", [("name", "=ilike", nombre)], limit=2)
    if len(ids) > 1:
        print(f"  ⚠ Hay más de una categoría '{nombre}', uso la primera (id {ids[0]})")
    if ids:
        return ids[0], False
    if DRY_RUN:
        return None, True
    return call("product.category", "create", {"name": nombre}), True


print("=== MODO PRUEBA (no escribe nada) ===" if DRY_RUN else "=== APLICANDO CAMBIOS ===")

total_prod = 0
creadas = []
with open(CSV_PATH, newline="", encoding="utf-8") as f:
    for row in csv.DictReader(f):
        nombre = row["categoria"].strip()
        pct = float(row["porcentaje"])
        categ_id, nueva = buscar_o_crear_categoria(nombre)

        if nueva:
            creadas.append(nombre)
            print(f"\n[{nombre}] {pct:g}% -> categoría NUEVA (sin productos todavía)")
            continue

        op = "child_of" if INCLUIR_SUBCATEGORIAS else "="
        prods = call(
            "product.template", "search_read",
            [("categ_id", op, categ_id)],
            fields=["id", "name", "standard_price", "list_price"],
            context={"active_test": False},
        )
        print(f"\n[{nombre}] {pct:g}% -> {len(prods)} productos")

        for p in prods:
            costo = p["standard_price"] or 0.0
            if costo <= 0 and SALTEAR_COSTO_CERO:
                print(f"  - salteado (costo 0): {p['name']}")
                continue
            nuevo = round(costo * (1 + pct / 100), 2)
            if abs(nuevo - p["list_price"]) < 0.01:
                continue
            print(f"  {p['name'][:50]:50} costo {costo:>12,.2f}  venta {p['list_price']:>12,.2f} -> {nuevo:>12,.2f}")
            if not DRY_RUN:
                call("product.template", "write", [p["id"]], {"list_price": nuevo})
            total_prod += 1

print("\n=== RESUMEN ===")
print(f"Productos {'a modificar' if DRY_RUN else 'modificados'}: {total_prod}")
print(f"Categorías {'a crear' if DRY_RUN else 'creadas'}: {len(creadas)}")
for c in creadas:
    print(f"  + {c}")
if DRY_RUN:
    print("\nSi está todo bien, corré con --aplicar")
