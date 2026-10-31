# FelFact API

API HTTP para emitir documentos tributarios electrónicos en Chile: facturas (33), boletas (39), notas de crédito (61) y débito (56), con envío al SII y representación impresa.

Es un servicio aparte: otro software lo usa por HTTP y no necesita compartir su código con él.

## Basado en LibreDTE

Este proyecto usa [LibreDTE](https://www.libredte.cl), creado por [SASCO SpA](https://www.sasco.cl), mediante su biblioteca [libredte-lib-core](https://github.com/libredte/libredte-lib-core). Se publica bajo la misma licencia y respeta sus [términos y condiciones de uso](https://core.libredte.cl/legal).

Además agrega el envío de boletas por la API REST del SII, que la biblioteca no implementa (`src/SiiBoleta.php`).

## Uso

```
POST /v1/<accion>
X-Api-Token: <token>
Content-Type: application/json
```

Responde `{"ok": true, ...}` o `{"ok": false, "error": "..."}`. `GET /salud` indica si el servicio está arriba.

Acciones: `caf_info`, `generar`, `generar_lote`, `generar_libro`, `pdf`, `intercambio`, `enviar`, `estado`, `probar`, `certificado_info`, `certificado_guardar`.

## Instalación

```bash
cp .env.example .env   # define LIBREDTE_API_TOKEN y LIBREDTE_CLAVE_MAESTRA, de 32 caracteres o más
docker build -t felfact-api .
docker run -d --env-file .env -e LIBREDTE_CERTIFICADO=/privado/certificado.p12 -v "$PWD/privado:/privado" felfact-api
```

Protege el acceso con un proxy: la API no debe quedar expuesta a internet sin restricción de origen.

## Licencia

[GNU AGPL v3 o posterior](LICENSE). Si modificas este proyecto y lo ofreces como servicio, debes publicar el código de esos cambios.
