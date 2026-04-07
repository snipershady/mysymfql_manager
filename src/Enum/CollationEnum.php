<?php

namespace App\Enum;

/**
 * Description of CollationEnum.
 *
 * Represents the main collations supported by MySQL 8.4 and later.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
enum CollationEnum: string
{
    /**
     * <p>0900: Based on the Unicode 9.0 standard algorithm.
     *  This guarantees a much smarter and more accurate sort order compared to the old collations (such as utf8mb4_unicode_ci or utf8mb4_general_ci),
     *  correctly handling complex languages and new emoji.
     *  Accent Insensitive, Case Insensitive. Default in MySQL 8.0+.
     * </p>.
     */
    case UTF8MB4_0900_AI_CI = 'utf8mb4_0900_ai_ci';

    /**
     * <p>0900: Based on the Unicode 9.0 standard algorithm.
     *  This guarantees an accurate sort order, correctly handling complex languages and emoji.
     *  Accent Sensitive, Case Sensitive.
     * </p>.
     */
    case UTF8MB4_0900_AS_CS = 'utf8mb4_0900_as_cs';

    /**
     * <p>0900: Based on the Unicode 9.0 standard algorithm.
     *  Binary sort order based on Unicode code point values.
     *  Ideal for exact byte-by-byte comparisons in a Unicode context.
     * </p>.
     */
    case UTF8MB4_0900_BIN = 'utf8mb4_0900_bin';

    /**
     * <p>Based on Unicode 5.2.0.
     *  Standard pre-8.0 collation for full multilingual support.
     *  Case Insensitive.
     * </p>.
     */
    case UTF8MB4_UNICODE_CI = 'utf8mb4_unicode_ci';

    /**
     * <p>Based on Unicode 5.2.0.
     *  Includes specific improvements and a more refined sort order compared to the base unicode_ci version.
     *  Case Insensitive.
     * </p>.
     */
    case UTF8MB4_UNICODE_520_CI = 'utf8mb4_unicode_520_ci';

    /**
     * <p>Simplified legacy collation for utf8mb4.
     *  Very fast in comparison operations but less precise in linguistic sort order compared to Unicode versions.
     *  Case Insensitive.
     * </p>.
     */
    case UTF8MB4_GENERAL_CI = 'utf8mb4_general_ci';

    /**
     * <p>Legacy binary sort order for the utf8mb4 charset.
     *  Directly compares the numeric byte values.
     * </p>.
     */
    case UTF8MB4_BIN = 'utf8mb4_bin';

    /**
     * <p>Historic MySQL default for the latin1 charset (West European).
     *  Based on Swedish/Western European sort order rules.
     *  Case Insensitive.
     * </p>.
     */
    case LATIN1_SWEDISH_CI = 'latin1_swedish_ci';

    /**
     * <p>Latin1 charset.
     *  Standard multilingual sort order rules for Western Europe.
     *  Case Insensitive.
     * </p>.
     */
    case LATIN1_GENERAL_CI = 'latin1_general_ci';

    /**
     * <p>Latin1 charset.
     *  Standard multilingual sort order rules, Case Sensitive.
     * </p>.
     */
    case LATIN1_GENERAL_CS = 'latin1_general_cs';

    /**
     * <p>Latin1 charset.
     *  Binary sort order based on extended ASCII byte values.
     * </p>.
     */
    case LATIN1_BIN = 'latin1_bin';

    /**
     * <p>ASCII charset (7-bit).
     *  Simple comparison rules for standard US-ASCII characters.
     *  Case Insensitive.
     * </p>.
     */
    case ASCII_GENERAL_CI = 'ascii_general_ci';

    /**
     * <p>ASCII charset.
     *  Binary sort order based on byte values 0-127.
     * </p>.
     */
    case ASCII_BIN = 'ascii_bin';
}
