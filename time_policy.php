<?php

declare(strict_types=1);

function app_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam'));
}

function inspection_visit_time(string $local, string $offset = ''): array
{
    $utc = new DateTimeZone('UTC');
    $zone = new DateTimeZone('Europe/Amsterdam');
    $naive = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, $utc);
    if ($naive === false || $naive->format('Y-m-d\TH:i') !== $local) {
        throw new InvalidArgumentException('Enter a valid visit date and time.');
    }
    // Enumerate possible offsets instead of PHP's silent DST normalization.
    $timestamp = $naive->getTimestamp();
    $candidates = [];
    foreach ($zone->getTransitions($timestamp - 172800, $timestamp + 172800) as $transition) {
        $instant = (new DateTimeImmutable('@' . ($timestamp - $transition['offset'])))->setTimezone($zone);
        if ($instant->format('Y-m-d\TH:i') === $local) $candidates[$instant->format('P')] = $instant;
    }
    if ($candidates === []) throw new InvalidArgumentException('That Amsterdam time does not exist because the clock moves forward.');
    if ($offset !== '' && !isset($candidates[$offset])) throw new InvalidArgumentException('The selected UTC offset does not match this Amsterdam time.');
    if (count($candidates) > 1 && $offset === '') {
        throw new InvalidArgumentException('That Amsterdam time occurs twice. Choose +02:00 (summer) or +01:00 (winter).');
    }
    $instant = $offset === '' ? reset($candidates) : $candidates[$offset];
    return ['visited_at' => $instant->format('Y-m-d H:i:s'),
        'visited_at_utc' => $instant->setTimezone($utc)->format('Y-m-d H:i:s'),
        'visited_timezone' => 'Europe/Amsterdam', 'visited_offset' => $instant->format('P')];
}

function display_record_time(?string $wall, ?string $utc, ?string $zone): string
{
    if ($wall === null) return '';
    if ($utc !== null && $zone === 'Europe/Amsterdam') {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i:s P') . ' Europe/Amsterdam';
    }
    return $wall . ($zone === null ? ' (timezone unrecorded)' : ' ' . $zone);
}
