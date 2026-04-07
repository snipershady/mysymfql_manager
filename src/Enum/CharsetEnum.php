<?php

namespace App\Enum;

/**
 * Description of CharsetEnum.
 *
 * Represents the main character sets (charset) supported by MySQL 8.4 and later.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
enum CharsetEnum: string
{
    /**
     * <p>Unicode (4-byte).
     *  The recommended character set for all new applications.
     *  Supports the full range of Unicode characters, including emoji and complex mathematical symbols.
     *  It is the default in MySQL 8.0+.
     * </p>.
     */
    case UTF8MB4 = 'utf8mb4';

    /**
     * <p>Unicode (3-byte).
     *  Legacy version of UTF-8 that supports only the Basic Multilingual Plane (BMP).
     *  Note: Deprecated in MySQL 8.0 in favour of utf8mb4.
     * </p>.
     */
    #[\Deprecated(message: 'use utfmb4 instead', since: '8.0')]
    case UTF8MB3 = 'utf8mb3';

    /**
     * <p>Alias for utf8mb3.
     *  In MySQL 8.4, 'utf8' is an alias for 'utf8mb3', but the behaviour will change in the future to point to 'utf8mb4'.
     * </p>.
     */
    case UTF8 = 'utf8';

    /**
     * <p>cp1252 West European (ISO 8859-1).
     *  Historic character set very common in Western Europe.
     * </p>.
     */
    case LATIN1 = 'latin1';

    /**
     * <p>US ASCII (7-bit).
     *  Supports only standard English characters (0-127).
     * </p>.
     */
    case ASCII = 'ascii';

    /**
     * <p>Binary (byte stream).
     *  Character set for binary data, does not perform case conversions or linguistic sorting.
     * </p>.
     */
    case BINARY = 'binary';

    /**
     * <p>UTF-16 Unicode.
     *  16-bit representation of Unicode characters.
     * </p>.
     */
    case UTF16 = 'utf16';

    /**
     * <p>UTF-16LE Unicode.
     *  UTF-16 representation in Little Endian format.
     * </p>.
     */
    case UTF16LE = 'utf16le';

    /**
     * <p>UTF-32 Unicode.
     *  32-bit (fixed-width) representation of Unicode characters.
     * </p>.
     */
    case UTF32 = 'utf32';

    /**
     * <p>UTF-8 for the Unicode character set for Central Europe.
     *  Common for languages such as Polish, Czech, and Hungarian.
     * </p>.
     */
    case LATIN2 = 'latin2';

    /**
     * <p>Character set for Cyrillic languages.
     * </p>.
     */
    case CP1251 = 'cp1251';

    /**
     * <p>Character set for Western European languages (Windows).
     *  Similar to latin1 but with proprietary extensions.
     * </p>.
     */
    case CP1250 = 'cp1250';

    /**
     * <p>Greek character set.
     * </p>.
     */
    case GREEK = 'greek';

    /**
     * <p>Hebrew character set.
     * </p>.
     */
    case HEBREW = 'hebrew';

    /**
     * <p>Arabic character set.
     * </p>.
     */
    case ARABIC = 'arabic';
}
