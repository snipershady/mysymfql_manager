<?php

namespace App\Enum;

/**
 * Description of CharsetEnum.
 *
 * Rappresenta i principali set di caratteri (charset) supportati da MySQL 8.4 e superiori.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
enum CharsetEnum: string
{
    /**
     * <p>Unicode (4-byte).
     *  Il set di caratteri raccomandato per tutte le nuove applicazioni.
     *  Supporta l'intera gamma di caratteri Unicode, inclusi emoji e simboli matematici complessi.
     *  È il default in MySQL 8.0+.
     * </p>.
     */
    case UTF8MB4 = 'utf8mb4';

    /**
     * <p>Unicode (3-byte).
     *  Versione legacy di UTF-8 che supporta solo il Basic Multilingual Plane (BMP).
     *  Nota: Deprecato in MySQL 8.0 in favore di utf8mb4.
     * </p>.
     */
    #[\Deprecated(message: 'use utfmb4 instead', since: '8.0')]
    case UTF8MB3 = 'utf8mb3';

    /**
     * <p>Alias per utf8mb3.
     *  In MySQL 8.4, 'utf8' è un alias per 'utf8mb3', ma il comportamento cambierà in futuro per puntare a 'utf8mb4'.
     * </p>.
     */
    case UTF8 = 'utf8';

    /**
     * <p>cp1252 West European (ISO 8859-1).
     *  Set di caratteri storico molto comune in Europa occidentale.
     * </p>.
     */
    case LATIN1 = 'latin1';

    /**
     * <p>US ASCII (7-bit).
     *  Supporta solo i caratteri standard inglesi (0-127).
     * </p>.
     */
    case ASCII = 'ascii';

    /**
     * <p>Binary (byte stream).
     *  Set di caratteri per dati binari, non esegue conversioni di case o ordinamenti linguistici.
     * </p>.
     */
    case BINARY = 'binary';

    /**
     * <p>UTF-16 Unicode.
     *  Rappresentazione a 16-bit dei caratteri Unicode.
     * </p>.
     */
    case UTF16 = 'utf16';

    /**
     * <p>UTF-16LE Unicode.
     *  Rappresentazione UTF-16 in formato Little Endian.
     * </p>.
     */
    case UTF16LE = 'utf16le';

    /**
     * <p>UTF-32 Unicode.
     *  Rappresentazione a 32-bit (larghezza fissa) dei caratteri Unicode.
     * </p>.
     */
    case UTF32 = 'utf32';

    /**
     * <p>UTF-8 per set di caratteri Unicode per l'Europa centrale.
     *  Comune per lingue come polacco, ceco, ungherese.
     * </p>.
     */
    case LATIN2 = 'latin2';

    /**
     * <p>Set di caratteri per lingue cirilliche.
     * </p>.
     */
    case CP1251 = 'cp1251';

    /**
     * <p>Set di caratteri per lingue dell'Europa occidentale (Windows).
     *  Simile a latin1 ma con estensioni proprietarie.
     * </p>.
     */
    case CP1250 = 'cp1250';

    /**
     * <p>Set di caratteri greco.
     * </p>.
     */
    case GREEK = 'greek';

    /**
     * <p>Set di caratteri ebraico.
     * </p>.
     */
    case HEBREW = 'hebrew';

    /**
     * <p>Set di caratteri arabo.
     * </p>.
     */
    case ARABIC = 'arabic';
}
