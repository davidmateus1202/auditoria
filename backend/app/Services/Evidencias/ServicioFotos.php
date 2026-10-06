<?php

declare(strict_types=1);

namespace App\Services\Evidencias;

use App\Domain\Extraccion\TextoNormalizador;
use App\Models\Auditoria;
use App\Models\FotoAuditoria;
use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\BaseDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use Throwable;

/**
 * Saca las fotos del registro fotográfico de una autoevaluación y las guarda
 * con una miniatura, para verlas en el módulo de evidencias sin abrir el Excel.
 *
 * La hoja no tiene un nombre fijo: en los archivos reales aparece como FOTOS,
 * fotos, Fotos, EVIDENCIAS, FOTOGRAFIAS o «registro fotografico». Por eso se
 * reconoce por las palabras de su nombre y no por una lista cerrada.
 */
final class ServicioFotos
{
    /** Lado mayor de la miniatura, en píxeles: suficiente para una cuadrícula nítida. */
    private const LADO_MINIATURA = 480;

    /**
     * Por debajo de esto es un logo o un ícono pegado en el encabezado de la
     * hoja, no una foto de la visita.
     */
    private const LADO_MINIMO = 150;

    private const EXTENSIONES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
    ];

    public static function esHojaDeFotos(string $nombre): bool
    {
        foreach (explode(' ', TextoNormalizador::canonica($nombre)) as $palabra) {
            if (str_starts_with($palabra, 'FOTO') || str_starts_with($palabra, 'EVIDENCIA')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reemplaza las fotos de la auditoría por las que trae el archivo. Es
     * idempotente: volver a procesar el mismo archivo deja lo mismo.
     *
     * @return int cuántas fotos quedaron
     */
    public function procesar(Auditoria $auditoria, string $rutaArchivo): int
    {
        $imagenes = $this->extraer($rutaArchivo);

        $this->eliminar($auditoria);

        $guardadas = 0;
        $vistas = [];

        foreach ($imagenes as $imagen) {
            $hash = hash('sha256', $imagen['contenido']);

            if (isset($vistas[$hash])) {
                continue;
            }

            $vistas[$hash] = true;
            $guardadas++;
            $base = $this->carpeta($auditoria).'/'.str_pad((string) $guardadas, 3, '0', STR_PAD_LEFT);
            $ruta = $base.'.'.self::EXTENSIONES[$imagen['mime']];
            Storage::put($ruta, $imagen['contenido']);

            $miniatura = $this->miniatura($imagen['contenido']);
            $rutaMiniatura = $miniatura === null ? $ruta : $base.'_mini.jpg';

            if ($miniatura !== null) {
                Storage::put($rutaMiniatura, $miniatura);
            }

            FotoAuditoria::create([
                'auditoria_id' => $auditoria->id,
                'hoja' => $imagen['hoja'],
                'celda' => $imagen['celda'],
                'orden' => $guardadas,
                'ruta' => $ruta,
                'ruta_miniatura' => $rutaMiniatura,
                'mime' => $imagen['mime'],
                'bytes' => strlen($imagen['contenido']),
                'ancho' => $imagen['ancho'],
                'alto' => $imagen['alto'],
                'hash_sha256' => $hash,
            ]);
        }

        return $guardadas;
    }

    /** Borra las fotos de la auditoría, filas y archivos. */
    public function eliminar(Auditoria $auditoria): void
    {
        DB::transaction(fn () => FotoAuditoria::query()->where('auditoria_id', $auditoria->id)->delete());
        Storage::deleteDirectory($this->carpeta($auditoria));
    }

    /**
     * Las imágenes de las hojas de fotos, en orden de lectura: por hoja, y
     * dentro de cada una de arriba abajo y de izquierda a derecha.
     *
     * @return list<array{hoja:string, celda:?string, contenido:string, mime:string, ancho:?int, alto:?int}>
     */
    public function extraer(string $rutaArchivo): array
    {
        $lector = IOFactory::createReaderForFile($rutaArchivo);
        $hojas = array_values(array_filter($lector->listWorksheetNames($rutaArchivo), self::esHojaDeFotos(...)));

        if ($hojas === []) {
            return [];
        }

        // Solo las hojas de fotos: el resto del libro no hace falta y pesa.
        $lector->setLoadSheetsOnly($hojas);
        $libro = $lector->load($rutaArchivo);
        $imagenes = [];

        foreach ($libro->getAllSheets() as $hoja) {
            $dibujos = iterator_to_array($hoja->getDrawingCollection());
            usort($dibujos, static fn (BaseDrawing $a, BaseDrawing $b) => self::posicion($a) <=> self::posicion($b));

            foreach ($dibujos as $dibujo) {
                $imagen = $this->contenido($dibujo);

                if ($imagen === null) {
                    continue;
                }

                [$ancho, $alto, $mime] = $imagen['info'];

                if ($ancho !== null && max($ancho, $alto) < self::LADO_MINIMO) {
                    continue;
                }

                $imagenes[] = [
                    'hoja' => trim($hoja->getTitle()),
                    'celda' => $dibujo->getCoordinates() ?: null,
                    'contenido' => $imagen['contenido'],
                    'mime' => $mime,
                    'ancho' => $ancho,
                    'alto' => $alto,
                ];
            }
        }

        $libro->disconnectWorksheets();

        return $imagenes;
    }

    /** @return array{int, int} fila y columna de la celda donde está anclada */
    private static function posicion(BaseDrawing $dibujo): array
    {
        try {
            [$columna, $fila] = Coordinate::coordinateFromString($dibujo->getCoordinates());

            return [(int) $fila, Coordinate::columnIndexFromString($columna)];
        } catch (Throwable) {
            return [PHP_INT_MAX, PHP_INT_MAX];
        }
    }

    /** @return array{contenido:string, info:array{?int, ?int, string}}|null */
    private function contenido(BaseDrawing $dibujo): ?array
    {
        try {
            if ($dibujo instanceof MemoryDrawing) {
                // Las de un .xls llegan ya decodificadas en memoria.
                ob_start();
                imagepng($dibujo->getImageResource());
                $contenido = (string) ob_get_clean();
            } elseif ($dibujo instanceof Drawing) {
                $contenido = (string) file_get_contents($dibujo->getPath());
            } else {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $info = $contenido === '' ? false : getimagesizefromstring($contenido);
        $mime = is_array($info) ? $info['mime'] : null;

        if ($mime === null || ! isset(self::EXTENSIONES[$mime])) {
            return null;
        }

        return ['contenido' => $contenido, 'info' => [$info[0] ?: null, $info[1] ?: null, $mime]];
    }

    /** JPEG reducido para la cuadrícula; null si GD no puede con la imagen (se usa la original). */
    private function miniatura(string $contenido): ?string
    {
        try {
            $original = @imagecreatefromstring($contenido);

            if (! $original instanceof GdImage) {
                return null;
            }

            $ancho = imagesx($original);
            $alto = imagesy($original);
            $escala = min(1, self::LADO_MINIATURA / max($ancho, $alto));
            $nuevoAncho = max(1, (int) round($ancho * $escala));
            $nuevoAlto = max(1, (int) round($alto * $escala));

            $lienzo = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
            // Fondo blanco: un PNG con transparencia se vería negro en JPEG.
            imagefill($lienzo, 0, 0, (int) imagecolorallocate($lienzo, 255, 255, 255));
            imagecopyresampled($lienzo, $original, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

            ob_start();
            imagejpeg($lienzo, null, 82);

            return (string) ob_get_clean();
        } catch (Throwable) {
            return null;
        }
    }

    private function carpeta(Auditoria $auditoria): string
    {
        return 'fotos/'.$auditoria->id;
    }
}
