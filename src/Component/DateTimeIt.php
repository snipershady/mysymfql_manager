<?php

namespace App\Component;

/**
 * @author Stefano Perrini <stefano.perrini@bidoo.com> aka La Matrigna
 */
final class DateTimeIt extends \DateTime
{
    public function __construct(string $datetime = 'now', ?\DateTimeZone $timezone = null)
    {
        parent::__construct($datetime, $timezone ?? new \DateTimeZone('Europe/Rome'));
    }

    #[\Override]
    public static function createFromFormat(string $format, string $datetime, ?\DateTimeZone $timezone = null): \DateTime|false
    {
        $timezone ??= new \DateTimeZone('Europe/Rome');
        $date = parent::createFromFormat($format, $datetime, $timezone);

        return $date ? new self($date->format('Y-m-d H:i:s'), $timezone) : false;
    }
}
