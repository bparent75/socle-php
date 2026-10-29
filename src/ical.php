<?php
/**
 * socle-php — export iCal (RFC 5545).
 *
 * Attend de l'application hôte : rien (socle_param() habille le PRODID si MARQUE_NOM existe).
 *
 * Pourquoi ici : deux générateurs coexistaient, et l'un pliait les longues lignes avec
 * chunk_split(), qui peut couper un caractère UTF-8 en deux — un accent au mauvais
 * endroit rendait le flux illisible. Le pliage correct (reculer jusqu'à une frontière
 * de caractère) et l'échappement des virgules et points-virgules sont figés ici.
 *
 * Les heures sont LOCALES (Europe/Paris), déclarées par un bloc VTIMEZONE : un
 * « mercredi 18h » reste à 18h au changement d'heure, ce qu'un horaire UTC figé ne
 * garantit pas. Le flux est en lecture seule ; servir l'URL signée et révocable
 * (signature_revocable()) plutôt qu'une URL devinable.
 */
declare(strict_types=1);

if (!function_exists('ical_texte')) {
    /** Échappement d'une valeur de texte iCal : antislashs, retours à la ligne, virgules, points-virgules. */
    function ical_texte(string $texte): string
    {
        return str_replace(["\\", "\r\n", "\n", "\r", ',', ';'], ["\\\\", '\n', '\n', '', '\,', '\;'], $texte);
    }
}

if (!function_exists('ical_ligne')) {
    /** Pliage d'une ligne à 74 octets (RFC 5545 §3.1), sans couper un caractère UTF-8 en deux. */
    function ical_ligne(string $ligne): string
    {
        $sortie = '';
        while (strlen($ligne) > 74) {
            $coupe = 74;
            while ($coupe > 1 && (ord($ligne[$coupe]) & 0xC0) === 0x80) {
                $coupe--;
            }
            $sortie .= substr($ligne, 0, $coupe) . "\r\n ";
            $ligne = substr($ligne, $coupe);
        }
        return $sortie . $ligne . "\r\n";
    }
}

if (!function_exists('ical_calendrier')) {
    /**
     * Un calendrier iCal complet, prêt à servir avec Content-Type: text/calendar.
     *
     * Chaque événement est un tableau :
     *   uid          obligatoire, stable d'un relevé à l'autre (« evenement-12@example.org ») ;
     *   debut, fin   « AAAA-MM-JJ HH:MM » en heure de Paris ;
     *   resume       le titre ;
     *   description, lieu                              facultatifs ;
     *   statut       « confirme » (défaut), « provisoire » ou « annule ».
     *
     * $options : nom (X-WR-CALNAME), prodid, refresh (durée RFC 5545, « PT6H » par
     * défaut, '' pour l'omettre).
     */
    function ical_calendrier(iterable $evenements, array $options = []): string
    {
        $nom = trim((string)($options['nom'] ?? socle_param('MARQUE_NOM', '')));
        $prodid = (string)($options['prodid'] ?? ('-//' . (socle_param('MARQUE_NOM', '') ?: 'socle-php') . '//FR'));
        $refresh = (string)($options['refresh'] ?? 'PT6H');
        $horodatage = gmdate('Ymd\THis\Z');
        $vers_ical = fn(string $local): string => preg_replace('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/',
                                                               '${1}${2}${3}T${4}${5}00', trim($local)) ?? '';
        $s = ical_ligne('BEGIN:VCALENDAR')
           . ical_ligne('VERSION:2.0')
           . ical_ligne('PRODID:' . ical_texte($prodid))
           . ical_ligne('CALSCALE:GREGORIAN')
           . ical_ligne('METHOD:PUBLISH')
           . ($nom !== '' ? ical_ligne('X-WR-CALNAME:' . ical_texte($nom)) : '')
           . ical_ligne('X-WR-TIMEZONE:Europe/Paris')
           . ($refresh !== '' ? ical_ligne('REFRESH-INTERVAL;VALUE=DURATION:' . $refresh) : '')
           . ical_ligne('BEGIN:VTIMEZONE')
           . ical_ligne('TZID:Europe/Paris')
           . ical_ligne('BEGIN:DAYLIGHT')
           . ical_ligne('TZOFFSETFROM:+0100')
           . ical_ligne('TZOFFSETTO:+0200')
           . ical_ligne('TZNAME:CEST')
           . ical_ligne('DTSTART:19700329T020000')
           . ical_ligne('RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU')
           . ical_ligne('END:DAYLIGHT')
           . ical_ligne('BEGIN:STANDARD')
           . ical_ligne('TZOFFSETFROM:+0200')
           . ical_ligne('TZOFFSETTO:+0100')
           . ical_ligne('TZNAME:CET')
           . ical_ligne('DTSTART:19701025T030000')
           . ical_ligne('RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU')
           . ical_ligne('END:STANDARD')
           . ical_ligne('END:VTIMEZONE');
        foreach ($evenements as $e) {
            $statut = (string)($e['statut'] ?? 'confirme');
            $s .= ical_ligne('BEGIN:VEVENT')
                . ical_ligne('UID:' . ical_texte((string)$e['uid']))
                . ical_ligne('DTSTAMP:' . $horodatage)
                . ical_ligne('DTSTART;TZID=Europe/Paris:' . $vers_ical((string)$e['debut']))
                . ical_ligne('DTEND;TZID=Europe/Paris:' . $vers_ical((string)$e['fin']))
                . ical_ligne('SUMMARY:' . ical_texte((string)($e['resume'] ?? '')));
            if (trim((string)($e['description'] ?? '')) !== '') {
                $s .= ical_ligne('DESCRIPTION:' . ical_texte((string)$e['description']));
            }
            if (trim((string)($e['lieu'] ?? '')) !== '') {
                $s .= ical_ligne('LOCATION:' . ical_texte((string)$e['lieu']));
            }
            $s .= ical_ligne('STATUS:' . match ($statut) {
                    'annule' => 'CANCELLED', 'provisoire' => 'TENTATIVE', default => 'CONFIRMED',
                })
                . ical_ligne('END:VEVENT');
        }
        return $s . ical_ligne('END:VCALENDAR');
    }
}
