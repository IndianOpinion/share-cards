<?php

/**
 * Plugin Name: Share cards: one card per day
 * Description: Example of a custom card type. A stable "today" page and that
 *              day's dated post share one key, so the card renders once.
 */

declare(strict_types=1);

use IndianOpinion\ShareCards\Subject;

add_filter('share_cards_types', static function (array $types): array {
    $types['day'] = [
        // Ids must also be plain digits with no leading zero: YYYYMMDD fits.
        'pattern' => '/^20\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])$/D',

        // Returns the subject for an id, or null for a 404.
        'resolve' => static function (string $id): ?Subject {
            $date = DateTimeImmutable::createFromFormat('!Ymd', $id, wp_timezone());
            if ($date === false || $date > new DateTimeImmutable('now', wp_timezone())) {
                return null;
            }
            return new Subject(
                'day',
                $id,
                sprintf('The day in brief: %s', wp_date('j F Y', $date->getTimestamp())),
                'Daily brief',
                ''
            );
        },

        // Returns the id for the current request, or null.
        'matchRequest' => static function (): ?string {
            if (is_page('today')) {
                return wp_date('Ymd');
            }
            if (is_singular('brief')) {
                return get_the_date('Ymd');
            }
            return null;
        },
    ];
    return $types;
});
