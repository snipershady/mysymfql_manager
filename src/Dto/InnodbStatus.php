<?php

namespace App\Dto;

/**
 * Represents the output of SHOW ENGINE INNODB STATUS in structured form.
 *
 * The raw text is split into the standard InnoDB sections
 * (BACKGROUND THREAD, SEMAPHORES, TRANSACTIONS, FILE I/O, etc.) and made
 * available both as a name→content map and via typed properties
 * for the most common values.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
final readonly class InnodbStatus
{
    /**
     * @param \DateTimeImmutable|null $generatedAt Timestamp extracted from the report header
     * @param string                  $rawStatus   Full raw text of the Status field
     * @param array<string, string>   $sections    Section name → text content map
     */
    public function __construct(
        public ?\DateTimeImmutable $generatedAt,
        public string $rawStatus,
        public array $sections,
    ) {
    }

    /**
     * Builds the DTO from the PDO::FETCH_ASSOC row of SHOW ENGINE INNODB STATUS.
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
     * Returns the content of a section by name (case-insensitive).
     * Returns null if the section is not present in the report.
     */
    public function getSection(string $name): ?string
    {
        $key = strtoupper(trim($name));

        return $this->sections[$key] ?? null;
    }

    // -------------------------------------------------------------------------
    // Internal parsers
    // -------------------------------------------------------------------------

    /**
     * Extracts the timestamp from the report header.
     *
     * The header has the form:
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
     * Splits the raw text into InnoDB sections.
     *
     * Sections are delimited by lines composed only of dashes (`-{3,}`),
     * followed by the section name, followed by another line of dashes:
     *
     *   -----------------
     *   BACKGROUND THREAD
     *   -----------------
     *   <content>
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

            // Recognise a line composed only of dashes (at least 3)
            if (preg_match('/^-{3,}$/', $line)) {
                $nameLine = isset($lines[$i + 1]) ? rtrim($lines[$i + 1]) : '';
                $closingLine = isset($lines[$i + 2]) ? rtrim($lines[$i + 2]) : '';

                // Verify that the next line is the name and the one after is dashes again
                if ('' !== $nameLine && preg_match('/^-{3,}$/', $closingLine)) {
                    $sectionName = strtoupper($nameLine);
                    $i += 3; // skip the three header lines

                    // Collect content until the next header or end of text
                    $contentLines = [];
                    while ($i < $total) {
                        $current = rtrim($lines[$i]);

                        // Next header: line of dashes followed by name and dashes
                        if (
                            preg_match('/^-{3,}$/', $current)
                            && isset($lines[$i + 1], $lines[$i + 2])
                            && '' !== rtrim($lines[$i + 1])
                            && preg_match('/^-{3,}$/', rtrim($lines[$i + 2]))
                        ) {
                            break;
                        }

                        // End of report (line of '=' signs)
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
