<?php

namespace App\Component;

/**
 * @author Stefano Perrini <stefano.perrini@bidoo.com> aka La Matrigna
 */
final class DateTimeUtc extends \DateTime
{
    public function __construct(string $datetime = 'now')
    {
        parent::__construct($datetime, new \DateTimeZone('UTC'));
    }

    #[\Override]
    public static function createFromFormat(string $format, string $datetime, ?\DateTimeZone $timezone = null): \DateTime|false
    {
        $timezone ??= new \DateTimeZone('UTC');
        $date = parent::createFromFormat($format, $datetime, $timezone);

        return $date ? new self($date->format('Y-m-d H:i:s')) : false;
    }
}
