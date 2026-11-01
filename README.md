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

Publícala detrás de un proxy con https. El acceso único debe limitarse por origen; los emisores pueden entrar desde cualquier red, protegidos por su token.

## Varios emisores

Define `FELFACT_EMISORES` con una carpeta persistente y crea un emisor por RUT:

```bash
php bin/emisor.php crear 12345678-5 "Mi Empresa SpA"   # muestra el token una sola vez
php bin/emisor.php revocar 12345678-5
php bin/emisor.php listar
```

Cada emisor sube su certificado con `certificado_guardar`, usando su token. Ese token solo firma con ese certificado y solo opera por ese RUT: una instalación no puede emitir a nombre de otra. Sin marca propia, sus PDF no llevan logo ni marca. La clave del certificado se cifra con `LIBREDTE_CLAVE_MAESTRA`, así que esa llave no se puede cambiar sin volver a subir los certificados.

`LIBREDTE_API_TOKEN` sigue funcionando como acceso único, con el certificado de `LIBREDTE_CERTIFICADO` y sin restricción de RUT. Si no se usa, puede quedar vacío.

Con `sin_pdf: true`, `generar` responde más rápido y el PDF se pide después con la acción `pdf`.

## Licencia

[GNU AGPL v3 o posterior](LICENSE). Si modificas este proyecto y lo ofreces como servicio, debes publicar el código de esos cambios.
