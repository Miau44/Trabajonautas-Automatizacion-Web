# Trabajonautas Automatización Web

Aplicación local en Laravel para recopilar y revisar convocatorias. El scraping y las previsualizaciones se guardan en la base de datos local de cada PC. Al publicar, la aplicación se conecta a la base de datos del sitio y, cuando hay adjuntos, los transfiere por SFTP sobre SSH.

## Requisitos para Windows

- Git para Windows.
- Laragon con PHP 8.1 o posterior y MySQL/MariaDB. Se recomienda PHP 8.3.
- Composer 2.
- Node.js 18 o posterior y npm. SICOES requiere Node.js 18+.
- Chrome o Microsoft Edge para el flujo del navegador de SICOES.
- `pdftotext` (Poppler) para extraer texto de PDF de SICOES. Si no está en `PATH`, se configura su ruta en `SICOES_PDFTOTEXT_PATH`.

Comprueba que las herramientas estén disponibles en PowerShell:

```powershell
git --version
php -v
composer --version
node --version
npm --version
```

Si `php` no se reconoce, usa la terminal de Laragon o añade al `PATH` la carpeta de la versión de PHP instalada en `C:\laragon\bin\php\`.

## Descargar el proyecto

Clona la rama compartida desde PowerShell:

```powershell
cd C:\laragon\www
git clone --branch integrar-scraper-sicoes https://github.com/Miau44/Trabajonautas-Automatizacion-Web.git
cd Trabajonautas-Automatizacion-Web
```

El repositorio debe estar compartido con la cuenta de GitHub del compañero. Para actualizar una copia existente:

```powershell
git pull origin integrar-scraper-sicoes
```

## Instalar dependencias

Desde la carpeta del proyecto:

```powershell
composer install
npm install
npm run build
```

## Crear el `.env` local

Crea el archivo a partir de la plantilla:

```powershell
Copy-Item .env.example .env
```

Abre `.env` y completa los valores para esa PC. Genera una clave propia para la instalación:

```powershell
php artisan key:generate
```

No copies el `.env` de otra computadora ni lo subas al repositorio. Cada PC debe tener su propio `APP_KEY`, su configuración local y credenciales protegidas.

### Variables principales

`.env.example` es la plantilla completa y la lista de referencia de todas las variables del proyecto. En una instalación normal revisa estos grupos:

| Variables | Para qué sirven | Cuándo se necesitan |
| --- | --- | --- |
| `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL` | Configuración de Laravel | Siempre; en la PC de oficina se usa normalmente `local` y `http://127.0.0.1:8000`. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Base de datos local de esa PC | Siempre. No se debe apuntar aquí a la base productiva del sitio. |
| `SEED_ADMIN_NAME`, `SEED_ADMIN_EMAIL`, `SEED_ADMIN_PASSWORD`, `SEED_ADMIN_PHONE` | Cuenta administradora local inicial | Para crear el administrador cuando se ejecuta el seeder por primera vez. Usa una contraseña nueva y segura. |
| `ANTHROPIC_API_KEY` y `ANTHROPIC_MODEL` | Análisis de convocatorias con Claude | Si se utilizarán las funciones de análisis con IA. La clave consume la cuenta/cupo al que pertenece. |
| `SICOES_NODE_PATH`, `SICOES_BROWSER_PATH`, `SICOES_PDFTOTEXT_PATH` | Rutas locales de Node, Chrome/Edge y `pdftotext` | Solo si no se detectan automáticamente o el scraper lo requiere. Déjalas vacías si se encuentran por `PATH` o en las rutas conocidas. |
| `SITE_DB_HOST`, `SITE_DB_PORT`, `SITE_DB_DATABASE`, `SITE_DB_USERNAME`, `SITE_DB_PASSWORD` | Conexión remota a la base existente del sitio | Solo para sincronizar empresas y publicar. El host debe ser la IP pública del VPS, no `127.0.0.1`. El administrador debe autorizar la IP pública de la oficina. |
| `SITE_FILES_PROTOCOL`, `SITE_FILES_HOST`, `SITE_FILES_PORT`, `SITE_FILES_USERNAME`, `SITE_FILES_PASSWORD`, `SITE_FILES_ROOT`, `SITE_FILES_HOSTKEY_SHA256` | Transferencia de archivos por SFTP autenticado con huella SHA-256 | Necesarias si se publicarán convocatorias con PDF o imágenes. La ruta debe ser absoluta y corresponder al almacenamiento que sirve `public/storage`. La huella debe confirmarse con el administrador. |

Las demás opciones están documentadas como nombres y valores predeterminados en `.env.example`. No agregues contraseñas, claves API, tokens ni datos de producción a ese archivo de ejemplo.

### ¿Qué configuración se comparte por privado?

No le envíes el archivo `.env` completo. Puede incluir la clave de Laravel, credenciales de la base local, API keys y accesos productivos. Comparte solo los valores estrictamente necesarios por un canal privado y seguro; es preferible que el responsable de cada servicio configure las claves directamente en cada PC.

- La cuenta local de MySQL/MariaDB se crea y configura para cada PC.
- `APP_KEY` se genera en cada PC con `php artisan key:generate`; no se comparte.
- La clave de Claude debe entregarla/configurarla quien administre esa cuenta, teniendo en cuenta que todas las PCs usarían su cuota.
- Las credenciales `SITE_DB_*` y `SITE_FILES_*` permiten acceder a sistemas del sitio. Deben entregarse solo a usuarios autorizados, no enviarse por un chat grupal y no guardarse en GitHub.
- La IP autorizada por el VPS debe ser la IP pública de salida de la oficina, no una IPv4 privada como `192.168.x.x`. Si cambia la IP pública, el administrador debe actualizar la regla.

## Crear la base local

En Laragon, inicia MySQL/MariaDB y crea una base vacía con el nombre indicado por `DB_DATABASE` en el `.env`. Después ejecuta las migraciones y los datos iniciales locales:

```powershell
php artisan migrate --seed
php artisan storage:link
```

Configura `SEED_ADMIN_*` antes de `migrate --seed`. El administrador creado usará el correo y contraseña puestos allí. El seeder también carga catálogos locales; al abrir la sección del bot, el catálogo de empresas se sincroniza desde la web cuando `SITE_DB_*` está configurado.

**No ejecutes migraciones ni seeders contra la base remota del sitio.** Los valores `DB_*` deben apuntar a MySQL local; la conexión `SITE_DB_*` solo se usa para las operaciones de catálogo/publicación existentes.

## Levantar la aplicación

En una terminal abierta en la carpeta del proyecto:

```powershell
php artisan serve
```

Abre la dirección que indique Laravel, normalmente <http://127.0.0.1:8000>. Mantén esa terminal abierta mientras se usa el sistema. Si se actualizó `.env` y Laravel conserva configuración anterior, ejecuta:

```powershell
php artisan config:clear
```

## Scraping y publicación

- Cada PC scrapea y guarda sus previsualizaciones en su propia base `DB_*`.
- `SITE_DB_*` permite leer el catálogo remoto y escribir anuncios en las tablas existentes del sitio cuando se pulsa publicar.
- La acción **Publicar todas** procesa las previsualizaciones revisadas/editadas del lote actual; revisa el lote antes de confirmar.
- La misma URL de origen se reconoce para evitar crear otra fila para esa misma fuente.
- Si una convocatoria incluye archivos, deben subirse al servidor antes de que se considere publicada correctamente. La PC debe tener SFTP en el puerto 22, la huella SSH verificada y la ruta correcta en `SITE_FILES_ROOT`.

El usuario SFTP puede tener acceso a una carpeta distinta del directorio que la web publica como `storage`. El administrador debe confirmar qué ruta remota corresponde a `public/storage` y que los archivos guardados allí se sirvan mediante `https://trabajonautas.com/storage/...`. No se debe publicar una convocatoria con adjuntos hasta confirmar esa correspondencia y verificar una transferencia.

## Solución de problemas

- `php` no se reconoce: abre una terminal de Laragon o configura el `PATH` de PHP.
- Error de conexión a `DB_*`: confirma que MySQL está iniciado y que el nombre de base/usuario/contraseña son los locales de esta PC.
- No aparecen empresas web: revisa `SITE_DB_*`, la conectividad desde la oficina y que la IP pública esté permitida por el VPS.
- Claude no está disponible: revisa que `ANTHROPIC_API_KEY` esté configurada en `.env` y limpia la caché de configuración.
- SICOES no inicia: confirma Node.js 18+, un navegador Chrome/Edge instalado y que `pdftotext` esté disponible si se requiere extraer PDFs.
- Los archivos no se publican: verifica que el servidor permita SFTP por el puerto 22, que `SITE_FILES_HOSTKEY_SHA256` coincida con la huella confirmada por el administrador y que `SITE_FILES_ROOT` apunte al almacenamiento público de la web.

## Seguridad

- `.env` está excluido de Git; conserva esa protección.
- Nunca publiques `.env`, contraseñas, claves API, archivos de certificados privados ni respaldos de bases productivas.
- Si una credencial se compartió por error, rótala en el servicio que la emitió y actualiza las PCs autorizadas.
