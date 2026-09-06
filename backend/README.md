# Auditoría SUH Municipal — backend

API de la aplicación de auditoría al Sistema Único de Habilitación de los
puestos de salud de la E.S.E. Municipal de Villavicencio, según la
**Resolución 3100 de 2019**.

Laravel 13 · PHP 8.3 · MySQL 8 (SQLite en pruebas) · PhpSpreadsheet 5.

Estado: **fases 1 y 2 completas** — cimientos y extractor.

## Puesta en marcha

```sh
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed          # crea el esquema y siembra los catálogos
php artisan serve
```

Por defecto usa SQLite (`database/database.sqlite`) para poder arrancar sin
servidor. Para MySQL, en `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=auditoria_suh
DB_USERNAME=root
DB_PASSWORD=
```

## Leer un archivo sin cargarlo

```sh
php artisan suh:extraer "../SUH Morichal.xlsx" --cumplimiento
php artisan suh:extraer "../Seguiguimiento hallazgos SUH - Auditoria interna.xls" --detalle
```

Si la plantilla cambió, el comando falla indicando **hoja y fila exactas** en vez
de interpretar el archivo a medias.

## Cómo está organizado

```
app/Domain/Extraccion/      reglas puras, sin Laravel ni base de datos
  TextoNormalizador         MAYÚSCULAS sin tildes: la clave de todo catálogo
  CatalogoEstandares        los 7 estándares de la 3100/2019 + E0 no normativo
  CatalogoServicios         nombre oficial del servicio por el nombre de la hoja
  ClasificadorHallazgo      distingue un hallazgo de un «Cumple» o «No auditado»
  SegmentadorHallazgos      parte celdas con varios hallazgos, sin inventarlos
  MapeadorEstado            los tres estados de la matriz, búsqueda exacta
  Lectores/                 un lector por formato de Excel
app/Services/Extraccion/    orquestación: detecta el formato y delega
app/Models/                 Eloquent
```

## Dos decisiones que explican el diseño

**El hallazgo cuelga de la sede, no de la auditoría.** Un problema atraviesa
varias auditorías: nace en una, se repite en la siguiente y en algún momento
deja de aparecer y se cierra. Si colgara de la auditoría, cerrarlo no
significaría nada. `auditoria_origen_id` recuerda dónde nació.

**El estado vive en el mes, no en el hallazgo.** El seguimiento a las sedes es
mensual, así que `hallazgo_estado_corte` guarda una fila por hallazgo vigente en
cada corte cerrado. Eso hace el consolidado fechado y reproducible —el de marzo
sigue dando lo mismo en diciembre—, habilita la serie mensual y permite calcular
antigüedad. `hallazgos.estado` es solo la caché del último corte cerrado.

## Lo que el extractor corrige

El consolidado histórico reporta **238 hallazgos**; los reales son **223**. Las
15 filas de diferencia dicen literalmente «Cumple» o «No auditado» y están
marcadas como hallazgos cerrados, lo que sube el avance del municipio de
**14,8 % a 19,7 %** sin que se haya cerrado nada. No es error de digitación: el
formato no distingue «no hubo hallazgo» de «el hallazgo se cerró».

La normativa **nunca** se lee del encabezado del archivo. La plantilla de
autoevaluación todavía dice «RES 2003 DE 2014» en ocho hojas mientras su propia
hoja INFORME ya cita la 3100 de 2019; la etiqueta se guarda solo como aviso.

## Pruebas

```sh
php artisan test
```

Corren contra los archivos reales de la E.S.E., que viven en la carpeta padre y
fuera del repositorio. Si no están, esas pruebas se omiten en vez de fallar.

Las cifras verificadas a mano son el contrato con los datos: 223 hallazgos,
120 de infraestructura, 116 abiertos, y los seis porcentajes de cumplimiento de
la hoja RESULTADOS de Morichal. Si alguien toca el extractor y esos números se
mueven, algo se rompió.
