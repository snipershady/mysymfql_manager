<?php

namespace App\Dto;

/**
 * Rappresenta l'output di SHOW ENGINE INNODB STATUS in forma strutturata.
 *
 * Il testo grezzo viene suddiviso nelle sezioni standard di InnoDB
 * (BACKGROUND THREAD, SEMAPHORES, TRANSACTIONS, FILE I/O, ecc.) e reso
 * disponibile sia come mappa nome→contenuto che tramite proprietà tipizzate
 * per i valori più comuni.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
final readonly class InnodbStatus
{
    /**
     * @param \DateTimeImmutable|null $generatedAt Timestamp estratto dall'header del report
     * @param string                  $rawStatus   Testo grezzo integrale del campo Status
     * @param array<string, string>   $sections    Mappa nome-sezione → contenuto testuale
     */
    public function __construct(
        public ?\DateTimeImmutable $generatedAt,
        public string $rawStatus,
        public array $sections,
    ) {
    }

    /**
     * Costruisce il DTO dalla riga PDO::FETCH_ASSOC di SHOW ENGINE INNODB STATUS.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $raw = (string) ($row['Status'] ?? $row['status'] ?? '');

        return new self(
            generatedAt: self::parseTimestamp($raw),
            rawStatus: $raw,
            sections: self::parseSections($raw),
        );
    }

    /**
     * Restituisce il contenuto di una sezione per nome (case-insensitive).
     * Ritorna null se la sezione non è presente nel report.
     */
    public function getSection(string $name): ?string
    {
        $key = strtoupper(trim($name));

        return $this->sections[$key] ?? null;
    }

    // -------------------------------------------------------------------------
    // Parser interni
    // -------------------------------------------------------------------------

    /**
     * Estrae il timestamp dall'header del report.
     *
     * L'header ha la forma:
     *   =====================================
     *   2024-04-01 12:34:56 0x7f1a2b3c4d5e INNODB MONITOR OUTPUT
     *   =====================================
     */
    private static function parseTimestamp(string $raw): ?\DateTimeImmutable
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $raw, $matches)) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $matches[1]);

            return false !== $dt ? $dt : null;
        }

        return null;
    }

    /**
     * Suddivide il testo grezzo nelle sezioni InnoDB.
     *
     * Le sezioni sono delimitate da righe composte solo da trattini (`-{3,}`),
     * seguite dal nome della sezione, seguite da un'altra riga di trattini:
     *
     *   -----------------
     *   BACKGROUND THREAD
     *   -----------------
     *   <contenuto>
     *
     * @return array<string, string>
     */
    private static function parseSections(string $raw): array
    {
        $sections = [];
        $lines = explode("\n", $raw);
        $total = count($lines);

        $i = 0;
        while ($i < $total) {
            $line = rtrim($lines[$i]);

            // Riconosci una riga di soli trattini (almeno 3)
            if (preg_match('/^-{3,}$/', $line)) {
                $nameLine = isset($lines[$i + 1]) ? rtrim($lines[$i + 1]) : '';
                $closingLine = isset($lines[$i + 2]) ? rtrim($lines[$i + 2]) : '';

                // Verifica che la riga successiva sia il nome e quella dopo sia ancora trattini
                if ('' !== $nameLine && preg_match('/^-{3,}$/', $closingLine)) {
                    $sectionName = strtoupper($nameLine);
                    $i += 3; // salta le tre righe dell'intestazione

                    // Raccoglie il contenuto fino alla prossima intestazione o fine testo
                    $contentLines = [];
                    while ($i < $total) {
                        $current = rtrim($lines[$i]);

                        // Prossima intestazione: riga di trattini seguita da nome e trattini
                        if (
                            preg_match('/^-{3,}$/', $current)
                            && isset($lines[$i + 1], $lines[$i + 2])
                            && '' !== rtrim($lines[$i + 1])
                            && preg_match('/^-{3,}$/', rtrim($lines[$i + 2]))
                        ) {
                            break;
                        }

                        // Fine del report (riga di segni '=')
                        if (preg_match('/^={3,}$/', $current)) {
                            break;
                        }

                        $contentLines[] = $lines[$i];
                        ++$i;
                    }

                    $sections[$sectionName] = trim(implode("\n", $contentLines));
                    continue;
                }
            }

            ++$i;
        }

        return $sections;
    }
}
