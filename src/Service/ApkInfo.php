<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Legge versionCode e versionName direttamente dall'APK.
 *
 * Prima quei due numeri si scrivevano a mano nel form di /app, e bastava
 * sbagliarne uno per mettere in ginocchio tutti i telefoni: l'app confronta
 * il proprio versionCode con quello dichiarato qui, quindi dichiarandone uno
 * piu' alto di quello vero ogni telefono si aggiorna, si riavvia, si ritrova
 * ancora "vecchio" e richiede di aggiornare — all'infinito. Con
 * l'aggiornamento obbligatorio non c'e' nemmeno il modo di chiudere la
 * finestra. Il numero non deve essere una cosa che si digita.
 *
 * Un APK e' uno zip, e dentro ha AndroidManifest.xml in XML binario di
 * Android (AXML), non in testo. Qui c'e' il minimo per tirarne fuori i due
 * attributi che servono, senza dipendere da aapt sul server.
 *
 * Formato (platform/frameworks/base, ResourceTypes.h):
 *   ogni pezzo comincia con  u16 tipo, u16 dimensioneTestata, u32 dimensione
 *   0x0001 elenco delle stringhe
 *   0x0180 mappa: per ogni stringa, l'id di risorsa dell'attributo
 *   0x0102 apertura di un tag, con i suoi attributi in coda
 */
final class ApkInfo
{
    /** id di risorsa di android:versionCode e android:versionName */
    private const ATTR_VERSION_CODE = 0x0101021B;
    private const ATTR_VERSION_NAME = 0x0101021C;

    private const TIPO_STRINGHE  = 0x0001;
    private const TIPO_MAPPA     = 0x0180;
    private const TIPO_APRI_TAG  = 0x0102;

    /** valore di tipo stringa: il dato e' un indice nell'elenco */
    private const DATO_STRINGA = 0x03;

    /**
     * @return array{version_code:int, version_name:string}|null
     *         null se il file non e' leggibile o non e' un APK
     */
    public static function leggi(string $percorsoApk): ?array
    {
        if (!class_exists(\ZipArchive::class)) {
            error_log('[ApkInfo] estensione zip non disponibile');
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($percorsoApk) !== true) {
            return null;
        }

        $axml = $zip->getFromName('AndroidManifest.xml');
        $zip->close();

        if (!is_string($axml) || strlen($axml) < 8) {
            return null;
        }

        try {
            return self::analizza($axml);
        } catch (\Throwable $e) {
            error_log('[ApkInfo] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return array{version_code:int, version_name:string}|null
     */
    private static function analizza(string $b): ?array
    {
        $stringhe = [];
        $mappa    = [];
        $lunghezza = strlen($b);

        // si salta la testata del file e si scorrono i pezzi
        $pos = 8;
        while ($pos + 8 <= $lunghezza) {
            $tipo      = self::u16($b, $pos);
            $dimPezzo  = self::u32($b, $pos + 4);

            // un pezzo di dimensione zero manderebbe il ciclo all'infinito
            if ($dimPezzo < 8 || $pos + $dimPezzo > $lunghezza) {
                break;
            }

            if ($tipo === self::TIPO_STRINGHE) {
                $stringhe = self::leggiStringhe($b, $pos, $dimPezzo);
            } elseif ($tipo === self::TIPO_MAPPA) {
                $quante = (int)(($dimPezzo - 8) / 4);
                for ($i = 0; $i < $quante; $i++) {
                    $mappa[$i] = self::u32($b, $pos + 8 + $i * 4);
                }
            } elseif ($tipo === self::TIPO_APRI_TAG) {
                $trovato = self::leggiTag($b, $pos, $stringhe, $mappa);
                if ($trovato !== null) {
                    return $trovato;
                }
            }

            $pos += $dimPezzo;
        }

        return null;
    }

    /**
     * Il tag <manifest>: se e' lui, si prendono i due attributi e si smette.
     *
     * @param string[]        $stringhe
     * @param array<int,int>  $mappa
     * @return array{version_code:int, version_name:string}|null
     */
    private static function leggiTag(string $b, int $pos, array $stringhe, array $mappa): ?array
    {
        $nomeIdx = self::u32($b, $pos + 20);
        if (($stringhe[$nomeIdx] ?? '') !== 'manifest') {
            return null;
        }

        $inizioAttr = self::u16($b, $pos + 24);
        $dimAttr    = self::u16($b, $pos + 26);
        $quanti     = self::u16($b, $pos + 28);

        if ($dimAttr <= 0) {
            return null;
        }

        $code = 0;
        $name = '';

        for ($i = 0; $i < $quanti; $i++) {
            // attributeStart si conta dall'inizio di attrExt (pos + 16), non
            // dall'inizio del pezzo: sbagliarlo fa leggere 16 byte piu' in qua
            // e non si trova nessun attributo
            $a = $pos + 16 + $inizioAttr + $i * $dimAttr;

            $nome      = self::u32($b, $a + 4);
            $grezzo    = self::u32($b, $a + 8);
            $tipoDato  = ord($b[$a + 15] ?? "\0");
            $dato      = self::u32($b, $a + 16);

            // l'attributo si riconosce dall'id di risorsa, non dal nome:
            // nel manifesto compilato i nomi possono essere vuoti
            $idRisorsa = $mappa[$nome] ?? 0;

            if ($idRisorsa === self::ATTR_VERSION_CODE) {
                $code = $dato;
            } elseif ($idRisorsa === self::ATTR_VERSION_NAME) {
                $name = $tipoDato === self::DATO_STRINGA
                    ? ($stringhe[$dato] ?? '')
                    : ($stringhe[$grezzo] ?? '');
            }
        }

        if ($code <= 0) {
            return null;
        }

        return ['version_code' => $code, 'version_name' => $name];
    }

    /**
     * Elenco delle stringhe. Puo' essere in UTF-8 o in UTF-16, lo dice un
     * bit nei flag.
     *
     * @return string[]
     */
    private static function leggiStringhe(string $b, int $pos, int $dim): array
    {
        $quante      = self::u32($b, $pos + 8);
        $flag        = self::u32($b, $pos + 16);
        $inizioDati  = self::u32($b, $pos + 20);
        $utf8        = ($flag & 0x100) !== 0;

        $out = [];
        for ($i = 0; $i < $quante; $i++) {
            $scarto = self::u32($b, $pos + 28 + $i * 4);
            $p      = $pos + $inizioDati + $scarto;
            if ($p >= $pos + $dim) {
                $out[$i] = '';
                continue;
            }

            if ($utf8) {
                // due lunghezze di fila (caratteri, poi byte); una lunghezza
                // oltre 127 occupa due byte invece di uno
                [$_, $p] = self::lunghezza8($b, $p);
                [$byte, $p] = self::lunghezza8($b, $p);
                $out[$i] = substr($b, $p, $byte);
            } else {
                [$caratteri, $p] = self::lunghezza16($b, $p);
                $grezzo = substr($b, $p, $caratteri * 2);
                $conv = @iconv('UTF-16LE', 'UTF-8//IGNORE', $grezzo);
                $out[$i] = $conv === false ? '' : $conv;
            }
        }
        return $out;
    }

    /** @return array{0:int,1:int} lunghezza e posizione dopo di essa */
    private static function lunghezza8(string $b, int $p): array
    {
        $n = ord($b[$p] ?? "\0");
        if ($n & 0x80) {
            $n = (($n & 0x7F) << 8) | ord($b[$p + 1] ?? "\0");
            return [$n, $p + 2];
        }
        return [$n, $p + 1];
    }

    /** @return array{0:int,1:int} */
    private static function lunghezza16(string $b, int $p): array
    {
        $n = self::u16($b, $p);
        if ($n & 0x8000) {
            $n = (($n & 0x7FFF) << 16) | self::u16($b, $p + 2);
            return [$n, $p + 4];
        }
        return [$n, $p + 2];
    }

    private static function u16(string $b, int $p): int
    {
        $v = unpack('v', substr($b, $p, 2));
        return $v === false ? 0 : (int)$v[1];
    }

    private static function u32(string $b, int $p): int
    {
        $v = unpack('V', substr($b, $p, 4));
        return $v === false ? 0 : (int)$v[1];
    }
}
