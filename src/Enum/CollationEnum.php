<?php

namespace App\Enum;

/**
 * Description of CollationEnum.
 *
 * Rappresenta le principali collation supportate da MySQL 8.4 e superiori.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
enum CollationEnum: string
{
    /**
     * <p>0900: Si basa sull'algoritmo standard Unicode 9.0.
     *  Questo garantisce un ordinamento molto più intelligente e accurato rispetto alle vecchie collation (come utf8mb4_unicode_ci o utf8mb4_general_ci),
     *  gestendo correttamente anche le lingue complesse e i nuovi emoji.
     *  Accent Insensitive, Case Insensitive. Default di MySQL 8.0+.
     * </p>.
     */
    case UTF8MB4_0900_AI_CI = 'utf8mb4_0900_ai_ci';

    /**
     * <p>0900: Basata sull'algoritmo standard Unicode 9.0.
     *  Questo garantisce un ordinamento accurato gestendo correttamente lingue complesse ed emoji.
     *  Accent Sensitive, Case Sensitive.
     * </p>.
     */
    case UTF8MB4_0900_AS_CS = 'utf8mb4_0900_as_cs';

    /**
     * <p>0900: Basata sull'algoritmo standard Unicode 9.0.
     *  Ordinamento binario basato sui valori dei punti di codice Unicode.
     *  Ideale per confronti esatti byte per byte in ambito Unicode.
     * </p>.
     */
    case UTF8MB4_0900_BIN = 'utf8mb4_0900_bin';

    /**
     * <p>Basata su Unicode 5.2.0.
     *  Collation standard pre-8.0 per il supporto multilingua completo.
     *  Case Insensitive.
     * </p>.
     */
    case UTF8MB4_UNICODE_CI = 'utf8mb4_unicode_ci';

    /**
     * <p>Basata su Unicode 5.2.0.
     *  Include miglioramenti specifici e una gestione dell'ordinamento più raffinata rispetto alla versione unicode_ci base.
     *  Case Insensitive.
     * </p>.
     */
    case UTF8MB4_UNICODE_520_CI = 'utf8mb4_unicode_520_ci';

    /**
     * <p>Collation legacy semplificata per utf8mb4.
     *  Molto veloce nelle operazioni di confronto ma meno precisa nell'ordinamento linguistico rispetto alle versioni Unicode.
     *  Case Insensitive.
     * </p>.
     */
    case UTF8MB4_GENERAL_CI = 'utf8mb4_general_ci';

    /**
     * <p>Ordinamento binario legacy per il charset utf8mb4.
     *  Confronta direttamente i valori numerici dei byte.
     * </p>.
     */
    case UTF8MB4_BIN = 'utf8mb4_bin';

    /**
     * <p>Default storico di MySQL per il charset latin1 (West European).
     *  Basato su regole di ordinamento svedesi/europee occidentali.
     *  Case Insensitive.
     * </p>.
     */
    case LATIN1_SWEDISH_CI = 'latin1_swedish_ci';

    /**
     * <p>Charset Latin1.
     *  Regole di ordinamento multilingua standard per l'Europa occidentale.
     *  Case Insensitive.
     * </p>.
     */
    case LATIN1_GENERAL_CI = 'latin1_general_ci';

    /**
     * <p>Charset Latin1.
     *  Regole di ordinamento multilingua standard, Case Sensitive.
     * </p>.
     */
    case LATIN1_GENERAL_CS = 'latin1_general_cs';

    /**
     * <p>Charset Latin1.
     *  Ordinamento binario basato sui valori dei byte ASCII estesi.
     * </p>.
     */
    case LATIN1_BIN = 'latin1_bin';

    /**
     * <p>Charset ASCII (7-bit).
     *  Regole di confronto semplici per caratteri standard US-ASCII.
     *  Case Insensitive.
     * </p>.
     */
    case ASCII_GENERAL_CI = 'ascii_general_ci';

    /**
     * <p>Charset ASCII.
     *  Ordinamento binario basato sui valori dei byte 0-127.
     * </p>.
     */
    case ASCII_BIN = 'ascii_bin';
}
