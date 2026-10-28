<?php

namespace App\Support;

/**
 * Turns raw scanned content (QR payload or OCR text of a waybill / booking form / address label)
 * into booking-form fields. Pure PHP, no framework dependencies, so it is trivially unit-testable.
 *
 * Result groups: `fields` (receiver), `sender`, `package`.
 * QR formats: JSON (any common key style: r_name, fullName, streetAddress, first_name…), vCard
 * (incl. iPhone "item1.ADR" and N-only cards), MECARD, and plain or delimited text.
 * Documents: "Sender" / "Receiver" / "Package" headings and prefixed labels ("Sender phone: …")
 * route values to the right group. OCR slips are tolerated (";" for ":", "Adress", labels with
 * the value on the next line, two fields on one line). Unlabelled text falls back to detection:
 * phone, email, Nigerian state, then a best guess for name, address and city.
 */
final class ReceiverScanParser
{
    // A photographed A4 form easily produces several thousand characters of OCR text.
    public const MAX_INPUT = 20000;

    private const STATES = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno',
        'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT', 'Gombe', 'Imo',
        'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa',
        'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba',
        'Yobe', 'Zamfara',
    ];

    private const LABELS = [
        'name' => ['name', 'full name', 'fullname', 'names', 'receiver', 'recipient', 'consignee', 'to', 'deliver to', 'attn', 'attention', 'fn'],
        'phone' => ['phone', 'phone number', 'phone no', 'tel', 'tel no', 'telephone', 'telephone number', 'mobile', 'mobile number', 'mobile no', 'whatsapp', 'whatsapp number', 'contact', 'contact number', 'contact no', 'gsm', 'gsm no', 'cell', 'cell phone', 'msisdn'],
        'email' => ['email', 'e mail', 'mail', 'email address', 'e mail address'],
        'address' => ['address', 'delivery address', 'street', 'street address', 'addr', 'location', 'residence', 'destination', 'destination address', 'drop off', 'dropoff', 'drop off address', 'address line'],
        'city' => ['city', 'town', 'lga', 'area', 'locality', 'city town', 'destination city'],
        'state' => ['state', 'region', 'province'],
        'landmark' => ['landmark', 'nearest landmark', 'near', 'nearest bus stop', 'bus stop', 'closest landmark'],
        '_first' => ['first name', 'firstname', 'given name', 'forename'],
        '_last' => ['last name', 'lastname', 'surname', 'family name'],
    ];

    private const PACKAGE_LABELS = [
        'description' => ['description', 'item', 'items', 'item description', 'contents', 'content', 'goods', 'package', 'parcel', 'package description', 'parcel description', 'what is inside', 'nature of goods', 'desc'],
        'weight_range' => ['weight', 'wt', 'weight kg', 'parcel weight', 'package weight', 'gross weight', 'weight range'],
        'service_type' => ['service', 'service type', 'delivery type', 'shipping', 'shipping type', 'delivery service', 'shipping method'],
    ];

    // Dropped from a label before matching: "Customer Name" → "name", "Home Address" → "address".
    private const FILLER = ['of', 'the', 'customer', 'customers', 'client', 'clients', 'home', 'residential', 'house', 'office', 'shipping', 'delivery', 'line', 'your', 'his', 'her', 'buyer', 'person'];

    private const SENDER_WORDS = 'sender|shipper|consignor|from|s';
    private const RECEIVER_WORDS = 'receiver|recipient|consignee|r';

    private const WEIGHT_KEYS = ['lt1', '1-3', '3-5', '5-10', '10-20', 'gt20'];

    private const SECTION = '/^\s*(sender|shipper|consignor|from|receiver|recipient|consignee|deliver to|ship to|to|package|parcel|item|items|shipment|goods)(?:\'s|’s)?(?:\s+(?:details?|information|info))?\s*[:;]?\s*$/iu';

    private const STREET_WORDS = 'street|st|road|rd|avenue|ave|close|cl|crescent|cres|estate|est|lane|ln|way|drive|dr|layout|junction|jnc|market|plaza|phase|plot|block|blk|house|flat|apartment|apt|suite|no|gra|quarters|qtrs|extension|ext|bypass|expressway|highway|boulevard|blvd|court|ct|square|sq|terrace|off|along|opposite|opp|behind|beside';

    // Document words that are never a person's name.
    private const NOT_NAMES = ['receiver', 'recipient', 'sender', 'shipper', 'consignee', 'waybill', 'way bill', 'receipt', 'invoice', 'delivery', 'parcel', 'package', 'shipment', 'logistics', 'tracking', 'nigeria', 'details', 'information', 'booking form', 'stallionking', 'stallion king', 'express', 'standard', 'customer copy', 'office copy', 'thank you'];

    /** @return array{fields: array<string,string>, sender: array<string,string>, package: array<string,string>, format: string, found: int} */
    public static function parse(string $raw): array
    {
        $raw = self::clean($raw);
        $sender = self::emptySender();
        $package = self::emptyPackage();

        if ($raw === '') {
            return ['fields' => self::empty(), 'sender' => $sender, 'package' => $package, 'format' => 'empty', 'found' => 0];
        }

        [$fields, $sender, $package, $format] = match (true) {
            self::looksLikeJson($raw) => [...self::fromJson($raw), 'json'],
            stripos($raw, 'BEGIN:VCARD') !== false => [self::fromVCard($raw), $sender, $package, 'vcard'],
            stripos($raw, 'MECARD:') === 0 => [self::fromMeCard($raw), $sender, $package, 'mecard'],
            default => [...self::fromDocument($raw), 'text'],
        };

        $fields = self::normalise($fields);
        $sender = self::normaliseSender($sender);
        $package = self::normalisePackage($package);

        $count = fn (array $a) => count(array_filter($a, fn ($v) => $v !== ''));
        return [
            'fields' => $fields,
            'sender' => $sender,
            'package' => $package,
            'format' => $format,
            'found' => $count($fields) + $count($sender) + $count($package),
        ];
    }

    public static function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';
        $digits = ltrim($digits, '+') === $digits ? $digits : ltrim($digits, '+');
        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            $digits = '0' . substr($digits, 3);
        } elseif (strlen($digits) === 10 && preg_match('/^[789]/', $digits)) {
            $digits = '0' . $digits;
        }
        return preg_match('/^0[789]\d{9}$/', $digits) ? $digits : '';
    }

    /** "2.5kg", "1kg – 3kg", "Under 1kg", "800g" → one of the booking form's weight bands. */
    public static function weightBand(string $s): string
    {
        $s = strtolower(trim($s));
        if (in_array($s, self::WEIGHT_KEYS, true)) {
            return $s;
        }
        if (!preg_match_all('/(\d+(?:[.,]\d+)?)\s*(kgs?|kilo\w*|grams?|g)?\b/u', $s, $m, PREG_SET_ORDER)) {
            return '';
        }
        $nums = [];
        foreach ($m as $x) {
            $n = (float) str_replace(',', '.', $x[1]);
            $nums[] = in_array($x[2] ?? '', ['g', 'gram', 'grams'], true) ? $n / 1000 : $n;
        }
        $kg = count($nums) >= 2 && preg_match('/\d\s*(?:kgs?)?\s*(?:-|–|to)\s*\d/u', $s) ? ($nums[0] + $nums[1]) / 2 : $nums[0];

        if (preg_match('/\b(under|below|less than|up to)\b/', $s) && $kg <= 1) {
            return 'lt1';
        }
        if (preg_match('/\b(above|over|more than)\b/', $s) && $kg >= 20) {
            return 'gt20';
        }
        return match (true) {
            $kg < 1 => 'lt1',
            $kg < 3 => '1-3',
            $kg < 5 => '3-5',
            $kg < 10 => '5-10',
            $kg <= 20 => '10-20',
            default => 'gt20',
        };
    }

    // ------------------------------------------------------------------

    private static function empty(): array
    {
        return ['name' => '', 'phone' => '', 'email' => '', 'address' => '', 'city' => '', 'state' => '', 'landmark' => '', '_first' => '', '_last' => ''];
    }

    private static function emptySender(): array
    {
        return ['name' => '', 'phone' => '', 'email' => '', 'city' => '', '_first' => '', '_last' => ''];
    }

    private static function emptyPackage(): array
    {
        return array_fill_keys(array_keys(self::PACKAGE_LABELS), '');
    }

    private static function clean(string $s): string
    {
        $s = mb_substr($s, 0, self::MAX_INPUT);
        $s = preg_replace('/[^\P{C}\n\t]/u', '', $s) ?? ''; // strip control chars (keeps newlines and tabs)
        return trim(str_replace("\r", '', $s));
    }

    private static function looksLikeJson(string $s): bool
    {
        return ($s[0] === '{') && is_array(json_decode($s, true));
    }

    /** "receiverStreetAddress1", "R_Name", "Phone (WhatsApp)" → "receiver street address", "r name", "phone". */
    private static function normaliseLabel(string $label): string
    {
        $label = preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $label) ?? $label; // camelCase
        $label = preg_replace('/\(.*?\)|\d+/u', ' ', $label) ?? $label;
        $label = mb_strtolower(preg_replace('/[_\-.\/#*]+/u', ' ', $label) ?? $label);
        $label = str_replace(['’', "'s ", "'s"], ["'", ' ', ''], $label . ' ');
        return trim(preg_replace('/\s+/u', ' ', $label) ?? '');
    }

    private static function lookup(string $label, array $table, bool $fuzzy): ?string
    {
        foreach ($table as $field => $aliases) {
            if (in_array($label, $aliases, true)) {
                return $field;
            }
        }
        if ($fuzzy && mb_strlen($label) >= 4) {
            // OCR slips like "Narne", "Adress", "Phane" (one wrong letter; two for long labels).
            $limit = mb_strlen($label) >= 9 ? 2 : 1;
            foreach ($table as $field => $aliases) {
                foreach ($aliases as $alias) {
                    if (strlen($alias) >= 4 && levenshtein($label, $alias) <= $limit) {
                        return $field;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Works out where a label's value belongs: ['fields'|'sender'|'package', field].
     * A "sender"/"receiver" word (before or after, e.g. "Name of receiver") overrides the section.
     */
    private static function route(string $label, string $section, bool $fuzzy = true): ?array
    {
        $label = self::normaliseLabel($label);
        if ($label === '' || mb_strlen($label) > 40) {
            return null;
        }

        if ($field = self::lookup($label, self::PACKAGE_LABELS, false)) {
            return ['package', $field];
        }

        $group = $section === 'sender' ? 'sender' : 'fields';
        if (preg_match('/^(' . self::SENDER_WORDS . ')s?(?:\s+(.*)|$)/u', $label, $m) && !in_array($label, ['street', 'state', 'surname'], true)) {
            [$group, $label] = ['sender', trim($m[2] ?? '') ?: 'name'];
        } elseif (preg_match('/^(' . self::RECEIVER_WORDS . ')s?(?:\s+(.*)|$)/u', $label, $m)) {
            [$group, $label] = ['fields', trim($m[2] ?? '') ?: 'name'];
        } elseif (preg_match('/^(.+?)\s+(?:of\s+)?(?:the\s+)?(' . self::SENDER_WORDS . '|' . self::RECEIVER_WORDS . ')$/u', $label, $m) && strlen($m[2]) > 1) {
            $group = preg_match('/^(' . self::SENDER_WORDS . ')$/', $m[2]) ? 'sender' : 'fields';
            $label = $m[1];
        }

        $field = self::lookup($label, self::LABELS, false);
        if (!$field) {
            $words = array_values(array_diff(explode(' ', $label), self::FILLER));
            $field = $words ? self::lookup(implode(' ', $words), self::LABELS, $fuzzy) : null;
        }
        if (!$field) {
            return null;
        }
        if ($group === 'sender') {
            // A sender only has name, phone, email and city; their state stands in for a missing city.
            $field = match ($field) { 'state' => 'city', 'address', 'landmark' => null, default => $field };
            return $field ? ['sender', $field] : null;
        }
        return ['fields', $field];
    }

    private static function fromJson(string $raw): array
    {
        $data = json_decode($raw, true) ?: [];
        $out = ['fields' => self::empty(), 'sender' => self::emptySender(), 'package' => self::emptyPackage()];

        $put = function (array $values, string $section) use (&$out, &$put) {
            foreach ($values as $key => $value) {
                if (is_array($value) && in_array(self::normaliseLabel((string) $key), ['address', 'location', 'contact'], true)) {
                    $put($value, $section); // {"address": {"street": …, "city": …}}
                    continue;
                }
                if (!is_scalar($value) || !($to = self::route((string) $key, $section, false))) {
                    continue;
                }
                [$group, $field] = $to;
                if ($out[$group][$field] === '') {
                    $out[$group][$field] = trim((string) $value);
                }
            }
        };

        foreach (['sender', 'shipper', 'from'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $put($data[$k], 'sender');
            }
        }
        foreach (['package', 'parcel', 'item', 'shipment'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $put($data[$k], 'package');
            }
        }
        foreach (['receiver', 'recipient', 'consignee', 'to', 'customer', 'contact'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $put($data[$k], 'receiver');
            }
        }
        $put($data, 'receiver'); // top-level keys: receiver contact or package details

        return [$out['fields'], $out['sender'], $out['package']];
    }

    private static function fromVCard(string $raw): array
    {
        $out = self::empty();
        $raw = preg_replace('/\n[ \t]/', '', $raw) ?? $raw; // unfold continuation lines
        $unescape = fn (string $v) => trim(str_replace(['\\,', '\\;', '\\n', '\\N'], [',', ';', ', ', ', '], $v));

        foreach (explode("\n", $raw) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$prop, $value] = explode(':', $line, 2);
            $params = strtoupper($prop);
            $prop = strtoupper(explode(';', $prop)[0]);
            $prop = preg_replace('/^ITEM\d+\./', '', $prop); // iPhone groups: item1.ADR
            if (str_contains($params, 'QUOTED-PRINTABLE')) {
                $value = quoted_printable_decode($value);
            }

            if ($prop === 'FN' && $out['name'] === '') {
                $out['name'] = $unescape($value);
            } elseif ($prop === 'N') {
                // N: Family;Given;Middle;Prefix;Suffix
                $p = array_map($unescape, array_pad(explode(';', $value), 5, ''));
                $out['_first'] = trim($p[1] . ' ' . $p[2]);
                $out['_last'] = $p[0];
            } elseif ($prop === 'TEL' && $out['phone'] === '') {
                $out['phone'] = trim($value);
            } elseif ($prop === 'EMAIL' && $out['email'] === '') {
                $out['email'] = trim($value);
            } elseif ($prop === 'ADR' && $out['address'] === '') {
                // ADR: PO;ext;street;city;region;postcode;country
                $p = array_map($unescape, array_pad(explode(';', $value), 7, ''));
                $out['address'] = $p[2] !== '' ? $p[2] : implode(', ', array_filter([$p[0], $p[1]]));
                $out['city'] = $p[3];
                $out['state'] = $p[4];
            } elseif ($prop === 'LABEL' && $out['address'] === '') {
                $out['address'] = $unescape($value);
            }
        }
        return $out;
    }

    private static function fromMeCard(string $raw): array
    {
        $out = self::empty();
        foreach (preg_split('/(?<!\\\\);/', substr($raw, 7)) as $part) {
            if (!str_contains($part, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $part, 2);
            $k = strtoupper(trim($k));
            $v = str_replace(['\\;', '\\:', '\\,'], [';', ':', ','], $v);
            if ($k === 'N') {
                $bits = array_map('trim', explode(',', $v));
                $out['name'] = trim(implode(' ', array_reverse($bits)));
            } elseif ($k === 'TEL' && $out['phone'] === '') {
                $out['phone'] = $v;
            } elseif ($k === 'EMAIL' && $out['email'] === '') {
                $out['email'] = $v;
            } elseif ($k === 'ADR' && $out['address'] === '') {
                $out['address'] = trim(preg_replace('/\s*,\s*/', ', ', $v) ?? $v);
            }
        }
        return $out;
    }

    /** Splits OCR/QR text into logical lines: columns, ";"/"|" separated fields and one-line labels. */
    private static function lines(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\n+/', $raw) as $line) {
            // Two columns on one line (OCR keeps wide gaps) and "a; b | c" delimited fields.
            foreach (preg_split('/\t+|\s{3,}|\s*[;|]\s*(?=[\p{L}][\p{L}\'’ ]{1,30}\s*[:=])/u', $line) as $part) {
                // "Name: Ade Bello Phone: 0803…" — split before every further recognised label.
                $pieces = preg_split('/\s+(?=[\p{L}][\p{L}\'’]*(?:\s[\p{L}\'’]+){0,3}\s*:)/u', $part);
                $buf = '';
                foreach ($pieces as $piece) {
                    if (preg_match('/[:=]\s*\S/u', $buf) && preg_match('/^([\p{L}\'’ ]+?)\s*:/u', $piece, $m) && self::route($m[1], 'receiver', false)) {
                        $out[] = $buf;
                        $buf = $piece;
                    } else {
                        $buf = $buf === '' ? $piece : "$buf $piece";
                    }
                }
                $out[] = $buf;
            }
        }
        // Strip OCR noise at the edges of a line: "| Ade Bello", "• 12 Allen Ave —".
        $out = array_map(fn ($l) => trim(preg_replace('/^[^\p{L}\p{N}+(]+|[^\p{L}\p{N}).:;]+$/u', '', $l) ?? ''), $out);
        return array_values(array_filter($out, fn ($l) => $l !== ''));
    }

    /** OCR text of a waybill, booking form or address label, or a plain-text QR code. */
    private static function fromDocument(string $raw): array
    {
        $out = ['fields' => self::empty(), 'sender' => self::emptySender(), 'package' => self::emptyPackage()];
        // Unlabelled lines per section; text before any heading is "top".
        $loose = ['top' => [], 'sender' => [], 'receiver' => [], 'package' => []];
        $section = 'top';
        $hasSections = false;
        $lines = self::lines($raw);

        // A one-line QR like "Ade Bello, 12 Allen Avenue, Ikeja, Lagos, 0803…" is a list of parts.
        if (count($lines) === 1 && substr_count($lines[0], ',') >= 2 && !preg_match('/[:=]/', $lines[0])) {
            $lines = self::commaParts($lines[0]);
        }

        $pending = null; // a label whose value is on the next line ("Address:" ⏎ "12 Allen Avenue")
        $last = null;    // the last labelled field, so wrapped address lines can continue it

        foreach ($lines as $index => $line) {
            if (preg_match(self::SECTION, $line, $m)) {
                $kind = match (strtolower($m[1])) {
                    'package', 'parcel', 'item', 'items', 'shipment', 'goods' => 'package',
                    default => 'other',
                };
                if ($kind === 'package' && $index === 0) {
                    continue; // "PARCEL" / "SHIPMENT" as the document's title, not a section
                }
                $section = match (strtolower($m[1])) {
                    'sender', 'shipper', 'consignor', 'from' => 'sender',
                    'package', 'parcel', 'item', 'items', 'shipment', 'goods' => 'package',
                    default => 'receiver',
                };
                $hasSections = true;
                $pending = $last = null;
                continue;
            }

            $label = null;
            if (preg_match('/^([\p{L}\'’][\p{L}\'’()\-_.\/ ]{0,38}?)\s*[:;=]\s*(.*)$/u', $line, $m)
                || preg_match('/^([A-Za-z ]{2,24})\s+[-–]\s+(.+)$/u', $line, $m)) {
                $label = [$m[1], trim($m[2])];
            } elseif (preg_match('/^(full name|name|address|delivery address|phone(?: number| no)?|mobile(?: number)?|tel|telephone|e-?mail|nearest landmark|(?:receiver|recipient|sender)(?:\'s)? (?:name|phone|address|city|email))\.?\s+(.+)$/iu', $line, $m)) {
                $label = [$m[1], trim($m[2])]; // OCR dropped the colon: "Name Ade Bello"
            }

            if ($label && ($to = self::route($label[0], $section))) {
                [$group, $field] = $to;
                $pending = $label[1] === '' ? $to : null;
                if ($label[1] !== '' && $out[$group][$field] === '') {
                    $out[$group][$field] = $label[1];
                }
                $last = $to;
                continue;
            }

            if ($pending) {
                [$group, $field] = $pending;
                if ($out[$group][$field] === '') {
                    $out[$group][$field] = $line;
                }
                $last = $pending;
                $pending = null;
                continue;
            }

            // A wrapped address: the next lines carry on the street, or give the city/state.
            if ($last && $last[1] === 'address' && $out[$last[0]]['address'] !== '' && !self::isContactLine($line)) {
                $g = $last[0];
                if (self::isPlace($line)) {
                    self::placeInto($out[$g], $line);
                    continue;
                }
                if (mb_strlen($out[$g]['address']) < 200 && (preg_match('/\d/', $line) || preg_match('/\b(' . self::STREET_WORDS . ')\b/i', $line))) {
                    $out[$g]['address'] .= ', ' . $line;
                    continue;
                }
            }
            $last = null;

            $loose[$section][] = $line;
        }

        // Receiver text: their own section; with no headings (or no receiver heading) the top text.
        $receiverLines = !$hasSections || ($loose['receiver'] === [] && !self::hasAny($out['fields']))
            ? array_merge($loose['top'], $loose['receiver'])
            : $loose['receiver'];
        $out['fields'] = self::guessContact($out['fields'], $receiverLines, true);
        if ($loose['sender']) {
            $out['sender'] = array_intersect_key(
                self::guessContact($out['sender'] + ['address' => '', 'state' => '', 'landmark' => ''], $loose['sender'], false),
                self::emptySender(),
            );
        }

        $pkg = &$out['package'];
        foreach ($loose['package'] as $line) {
            if ($pkg['weight_range'] === '' && preg_match('/\d\s*(kgs?|kilo\w*|grams?|g)\b/iu', $line)) {
                $pkg['weight_range'] = $line;
            } elseif ($pkg['service_type'] === '' && preg_match('/\b(express|standard)\b/i', $line) && str_word_count($line) <= 3) {
                $pkg['service_type'] = $line;
            } elseif ($pkg['description'] === '') {
                $pkg['description'] = $line;
            }
        }
        // An explicit "2.5kg" anywhere still tells us the weight.
        if ($pkg['weight_range'] === '' && preg_match('/\b\d+(?:[.,]\d+)?\s*kgs?\b/iu', $raw, $m)) {
            $pkg['weight_range'] = $m[0];
        }

        return [$out['fields'], $out['sender'], $out['package']];
    }

    /** "Ade Bello, 12 Allen Avenue, Ikeja, Lagos, 0803…" → name, address (with its street parts), place, phone. */
    private static function commaParts(string $line): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $line)), fn ($p) => $p !== ''));
        $lines = [];
        $address = null;
        foreach ($parts as $p) {
            if ($address !== null && !self::isPlace($p) && !self::isContactLine($p)) {
                $lines[$address] .= ', ' . $p; // more of the street: "Plot 5, Lekki Phase 1"
                continue;
            }
            if ($address === null && self::looksLikeAddress($p)) {
                $address = count($lines);
            }
            $lines[] = $p;
        }
        return $lines;
    }

    private static function hasAny(array $fields): bool
    {
        return (bool) array_filter($fields, fn ($v) => $v !== '');
    }

    private static function isContactLine(string $line): bool
    {
        return self::normalisePhone($line) !== '' || (bool) preg_match('/@|\b0[789]\d[\s\-]?\d{3}[\s\-]?\d{4}\b|^\+?234/u', $line);
    }

    /** "Ikeja", "Ikeja, Lagos", "Lagos State", "FCT" — a town and/or state, no street. */
    private static function isPlace(string $line): bool
    {
        return (bool) preg_match('/^[\p{L}\'\.\- ]+(?:,\s*[\p{L}\'\.\- ]+){0,2}$/u', $line)
            && count(preg_split('/[\s,]+/', trim($line))) <= 5
            && !preg_match('/\b(' . self::STREET_WORDS . ')\b/i', $line);
    }

    /** Puts "Ikeja, Lagos" into city/state where they are still empty. */
    private static function placeInto(array &$f, string $line): void
    {
        foreach (array_map('trim', explode(',', $line)) as $part) {
            $part = trim(preg_replace('/\bstate\b/i', '', $part) ?? $part);
            if ($part === '' || strtolower($part) === 'nigeria') {
                continue;
            }
            $state = self::detectState($part);
            if ($state !== '' && strcasecmp($state, $part) === 0 || ($state === 'FCT' && preg_match('/^(fct|abuja)$/i', $part) && $f['city'] !== '')) {
                if ($f['state'] === '') {
                    $f['state'] = $state;
                }
            } elseif ($f['city'] === '') {
                $f['city'] = $part;
            }
        }
        if ($f['city'] === '' && $f['state'] !== '') {
            $f['city'] = $f['state'] === 'FCT' ? 'Abuja' : $f['state'];
        }
    }

    private static function looksLikeAddress(string $line): bool
    {
        if (self::isContactLine($line) || preg_match('/^\d+(?:[.,]\d+)?\s*(kg|g)\b/i', $line)) {
            return false;
        }
        return (preg_match('/\d/', $line) && preg_match('/\b(' . self::STREET_WORDS . ')\b/i', $line))
            || (bool) preg_match('/^(no\.?\s*\d|\d+[a-z]?\s*,?\s+\p{L}{3,}|plot\s|block\s|blk\s|house\s|flat\s|suite\s|km\s*\d)/iu', $line);
    }

    private static function looksLikeName(string $line): bool
    {
        if (!preg_match('/^[\p{L}\'\.\- ]{3,60}$/u', $line) || count(preg_split('/\s+/', trim($line))) > 5) {
            return false;
        }
        $lower = mb_strtolower($line);
        foreach (self::NOT_NAMES as $word) {
            if (str_contains($lower, $word)) {
                return false;
            }
        }
        return self::detectState($line) === '';
    }

    /** Fill gaps from unlabelled lines: email, phone, state, then best-guess name, address and city. */
    private static function guessContact(array $out, array $lines, bool $withAddress): array
    {
        $blob = implode("\n", $lines);

        if ($out['email'] === '' && preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $blob, $m)) {
            $out['email'] = $m[0];
        }

        if (self::normalisePhone($out['phone']) === '') {
            $out['phone'] = '';
            if (preg_match_all('/(?:\+?234|0)[\s\-().]*[789][\s\-().]*\d(?:[\s\-().]*\d){8}/', $blob, $all)) {
                foreach ($all[0] as $candidate) {
                    if (self::normalisePhone($candidate) !== '') {
                        $out['phone'] = $candidate;
                        break;
                    }
                }
            }
        }

        if ($out['name'] === '' && trim($out['_first'] . ' ' . $out['_last']) === '') {
            foreach ($lines as $line) {
                if (self::looksLikeName($line)) {
                    $out['name'] = $line;
                    break;
                }
            }
        }

        if ($withAddress && $out['address'] === '') {
            foreach ($lines as $i => $line) {
                if (self::looksLikeAddress($line)) {
                    $out['address'] = $line;
                    // The lines right after an address usually give the town and state.
                    for ($j = $i + 1; $j < count($lines) && $j <= $i + 2 && self::isPlace($lines[$j]); $j++) {
                        self::placeInto($out, $lines[$j]);
                    }
                    break;
                }
            }
        }

        if ($out['state'] === '') {
            $out['state'] = self::detectState($withAddress && $out['address'] !== '' ? $out['address'] . "\n" . $blob : $blob);
        }
        if (!$withAddress && $out['city'] === '' && $out['state'] !== '') {
            $out['city'] = $out['state'];
        }

        return $out;
    }

    private static function detectState(string $text): string
    {
        $text = ' ' . strtolower($text) . ' ';
        if (preg_match('/\b(fct|abuja)\b/', $text)) {
            return 'FCT';
        }
        foreach (self::STATES as $state) {
            if (preg_match('/\b' . preg_quote(strtolower($state), '/') . '\b/', $text)) {
                return $state;
            }
        }
        return '';
    }

    private static function cleanEmail(string $email): string
    {
        $email = strtolower(trim(str_replace(' ', '', $email)));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private static function cleanName(array $f): string
    {
        $name = $f['name'] !== '' ? $f['name'] : trim($f['_first'] . ' ' . $f['_last']);
        $name = preg_replace('/\s+/u', ' ', trim($name, " \t,.;:-|")) ?? '';
        return mb_substr($name, 0, 120);
    }

    private static function normalise(array $f): array
    {
        $out = [
            'name' => self::cleanName($f),
            'phone' => self::normalisePhone($f['phone']),
            'email' => self::cleanEmail($f['email']),
            'address' => mb_substr(preg_replace('/\s+/u', ' ', trim($f['address'], " \t,;:-|")) ?? '', 0, 500),
            'city' => mb_substr(trim($f['city'], " \t,.;:-|"), 0, 80),
            'state' => '',
            'landmark' => mb_substr(trim($f['landmark'], " \t,;:-|"), 0, 150),
        ];
        $state = trim($f['state']);
        $out['state'] = $state === '' ? '' : (self::detectState($state) ?: mb_substr($state, 0, 40));

        // "12 Allen Avenue, Ikeja, Lagos": take the town (and state) from the end of the address.
        if ($out['city'] === '' && str_contains($out['address'], ',')) {
            $parts = array_map('trim', explode(',', $out['address']));
            while ($parts && in_array(strtolower(end($parts)), ['nigeria', 'ng'], true)) {
                array_pop($parts);
            }
            $tail = array_pop($parts);
            if ($tail !== null && $parts && self::detectState($tail) !== '' && preg_match('/^[\p{L} .]+$/u', $tail)) {
                $out['state'] = $out['state'] ?: self::detectState($tail);
                $tail = count($parts) > 1 && self::isPlace(end($parts)) ? end($parts) : (self::detectState($tail) === 'FCT' ? 'Abuja' : $tail);
            }
            if ($tail !== null && $parts && self::isPlace($tail)) {
                $out['city'] = mb_substr(trim(preg_replace('/\bstate\b/i', '', $tail) ?? $tail), 0, 80);
            }
        }
        return $out;
    }

    private static function normaliseSender(array $s): array
    {
        return [
            'name' => self::cleanName($s),
            'phone' => self::normalisePhone($s['phone']),
            'email' => self::cleanEmail($s['email']),
            'city' => mb_substr(trim($s['city'], " \t,.;:-|"), 0, 80),
        ];
    }

    private static function normalisePackage(array $p): array
    {
        $service = strtolower($p['service_type']);
        return [
            'description' => mb_substr(preg_replace('/\s+/u', ' ', trim($p['description'])) ?? '', 0, 200),
            'weight_range' => self::weightBand($p['weight_range']),
            'service_type' => match (true) {
                str_contains($service, 'express') => 'Express',
                (bool) preg_match('/standard|regular|normal/', $service) => 'Standard',
                default => '',
            },
        ];
    }
}
