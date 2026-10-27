<?php

namespace App\Support;

/**
 * Turns raw scanned content (QR payload or OCR text of a waybill / booking form)
 * into booking-form fields. Pure PHP, no framework dependencies, so it is trivially unit-testable.
 *
 * Result groups: `fields` (receiver), `sender`, `package`.
 * Supported QR formats: JSON, vCard, MECARD, "Label: value" lines (receiver only for vCard/MECARD).
 * Documents: "Sender" / "Receiver" / "Package" section headings and prefixed labels
 * ("Sender phone: …") route values to the right group. Text with no sections is treated
 * as the receiver's details, then pattern detection (phone, email, Nigerian state)
 * fills the gaps with a best guess for name and address.
 */
final class ReceiverScanParser
{
    public const MAX_INPUT = 4000;

    private const STATES = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno',
        'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT', 'Gombe', 'Imo',
        'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa',
        'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba',
        'Yobe', 'Zamfara',
    ];

    private const LABELS = [
        'name' => ['name', 'full name', 'fullname', 'receiver', 'receiver name', 'recipient', 'recipient name', 'to', 'deliver to', 'attn', 'fn'],
        'phone' => ['phone', 'phone number', 'phone no', 'tel', 'telephone', 'mobile', 'mobile number', 'whatsapp', 'contact', 'gsm', 'cell'],
        'email' => ['email', 'e-mail', 'e mail', 'mail', 'email address'],
        'address' => ['address', 'delivery address', 'street', 'street address', 'addr', 'location'],
        'city' => ['city', 'town', 'lga'],
        'state' => ['state'],
        'landmark' => ['landmark', 'nearest landmark', 'near', 'nearest bus stop', 'bus stop'],
    ];

    private const PACKAGE_LABELS = [
        'description' => ['description', 'item', 'items', 'item description', 'contents', 'content', 'goods', 'package', 'parcel', 'package description', 'parcel description', 'what is inside', 'nature of goods'],
        'weight_range' => ['weight', 'wt', 'weight kg', 'weight (kg)', 'parcel weight', 'package weight', 'gross weight'],
        'service_type' => ['service', 'service type', 'delivery type', 'shipping', 'shipping type', 'delivery service'],
    ];

    private const WEIGHT_KEYS = ['lt1', '1-3', '3-5', '5-10', '10-20', 'gt20'];

    private const SECTION = '/^\s*(sender|shipper|consignor|from|receiver|recipient|consignee|deliver to|ship to|to|package|parcel|item|items|shipment|goods)(?:\'s|’s)?(?:\s+(?:details?|information|info))?\s*:?\s*$/iu';

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
        return array_fill_keys(array_keys(self::LABELS), '');
    }

    private static function emptySender(): array
    {
        return ['name' => '', 'phone' => '', 'email' => '', 'city' => ''];
    }

    private static function emptyPackage(): array
    {
        return array_fill_keys(array_keys(self::PACKAGE_LABELS), '');
    }

    private static function clean(string $s): string
    {
        $s = mb_substr($s, 0, self::MAX_INPUT);
        $s = preg_replace('/[^\P{C}\n\t]/u', '', $s) ?? ''; // strip control chars
        return trim(str_replace("\r", '', $s));
    }

    private static function looksLikeJson(string $s): bool
    {
        return ($s[0] === '{') && is_array(json_decode($s, true));
    }

    /**
     * Works out where a label's value belongs: ['fields'|'sender'|'package', field].
     * A "sender"/"receiver" prefix overrides the section the label appears in.
     */
    private static function route(string $label, string $section): ?array
    {
        $label = strtolower(trim(preg_replace('/[_\-]+/', ' ', $label) ?? ''));
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');

        foreach (self::PACKAGE_LABELS as $field => $aliases) {
            if (in_array($label, $aliases, true)) {
                return ['package', $field];
            }
        }

        $group = $section === 'sender' ? 'sender' : 'fields';
        if (preg_match('/^(sender|shipper|consignor|from)(?:\'s|’s|s)?\s*(.*)$/u', $label, $m)) {
            [$group, $label] = ['sender', $m[2] ?: 'name'];
        } elseif (preg_match('/^(receiver|recipient|consignee)(?:\'s|’s|s)?\s*(.*)$/u', $label, $m)) {
            [$group, $label] = ['fields', $m[2] ?: 'name'];
        }

        foreach (self::LABELS as $field => $aliases) {
            if (in_array($label, $aliases, true)) {
                if ($group === 'sender') {
                    // A sender only has name, phone, email and city; their state stands in for a missing city.
                    $field = match ($field) { 'state' => 'city', 'address', 'landmark' => null, default => $field };
                    return $field ? ['sender', $field] : null;
                }
                return ['fields', $field];
            }
        }
        return null;
    }

    private static function fromJson(string $raw): array
    {
        $data = json_decode($raw, true) ?: [];
        $out = ['fields' => self::empty(), 'sender' => self::emptySender(), 'package' => self::emptyPackage()];

        $put = function (array $values, string $section) use (&$out) {
            foreach ($values as $key => $value) {
                if (!is_scalar($value) || !($to = self::route((string) $key, $section))) {
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
        foreach (['receiver', 'recipient', 'consignee', 'to'] as $k) {
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
        foreach (explode("\n", $raw) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$prop, $value] = explode(':', $line, 2);
            $prop = strtoupper(explode(';', $prop)[0]);
            $value = trim($value);
            if ($prop === 'FN' && $out['name'] === '') {
                $out['name'] = $value;
            } elseif ($prop === 'TEL' && $out['phone'] === '') {
                $out['phone'] = $value;
            } elseif ($prop === 'EMAIL' && $out['email'] === '') {
                $out['email'] = $value;
            } elseif ($prop === 'ADR' && $out['address'] === '') {
                // ADR: PO;ext;street;city;region;postcode;country
                $p = array_pad(explode(';', $value), 7, '');
                $out['address'] = trim($p[2]);
                $out['city'] = trim($p[3]);
                $out['state'] = trim($p[4]);
            }
        }
        return $out;
    }

    private static function fromMeCard(string $raw): array
    {
        $out = self::empty();
        foreach (explode(';', substr($raw, 7)) as $part) {
            if (!str_contains($part, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $part, 2);
            $k = strtoupper(trim($k));
            if ($k === 'N') {
                $bits = array_map('trim', explode(',', $v));
                $out['name'] = trim(implode(' ', array_reverse($bits)));
            } elseif ($k === 'TEL' && $out['phone'] === '') {
                $out['phone'] = $v;
            } elseif ($k === 'EMAIL' && $out['email'] === '') {
                $out['email'] = $v;
            } elseif ($k === 'ADR' && $out['address'] === '') {
                $out['address'] = trim(str_replace(',', ', ', $v));
            }
        }
        return $out;
    }

    /** OCR text of a waybill, booking form or address label. */
    private static function fromDocument(string $raw): array
    {
        $out = ['fields' => self::empty(), 'sender' => self::emptySender(), 'package' => self::emptyPackage()];
        // Unlabelled lines per section; text before any heading is "top".
        $loose = ['top' => [], 'sender' => [], 'receiver' => [], 'package' => []];
        $section = 'top';
        $hasSections = false;

        foreach (preg_split('/\n+/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match(self::SECTION, $line, $m)) {
                $section = match (strtolower($m[1])) {
                    'sender', 'shipper', 'consignor', 'from' => 'sender',
                    'package', 'parcel', 'item', 'items', 'shipment', 'goods' => 'package',
                    default => 'receiver',
                };
                $hasSections = true;
                continue;
            }
            if (preg_match('/^([\p{L}\'’ ][\p{L}\'’()\-_ ]{1,30})\s*[:=]\s*(.+)$/u', $line, $m)
                || preg_match('/^([A-Za-z ]{2,24})\s+-\s+(.+)$/u', $line, $m)) {
                if ($to = self::route($m[1], $section)) {
                    [$group, $field] = $to;
                    if ($out[$group][$field] === '') {
                        $out[$group][$field] = trim($m[2]);
                    }
                    continue;
                }
            }
            $loose[$section][] = $line;
        }

        // With no headings the whole text is the receiver's (an address label).
        $receiverLines = $hasSections ? $loose['receiver'] : $loose['top'];
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

    /** Fill gaps from unlabelled lines: email, phone, state, then best-guess name and address. */
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

        if ($out['state'] === '') {
            $out['state'] = self::detectState($blob);
        }
        if (!$withAddress && $out['city'] === '' && $out['state'] !== '') {
            $out['city'] = $out['state'];
        }

        // Best-guess name: first unlabelled line with letters only and 2-4 words.
        if ($out['name'] === '') {
            foreach ($lines as $line) {
                if (preg_match('/^[\p{L}\'\.\- ]{3,60}$/u', $line) && count(preg_split('/\s+/', $line)) <= 4
                    && self::detectState($line) === '') {
                    $out['name'] = $line;
                    break;
                }
            }
        }

        // Best-guess address: unlabelled line containing a digit and a street word.
        if ($withAddress && $out['address'] === '') {
            foreach ($lines as $line) {
                if (preg_match('/\d/', $line) && preg_match('/\b(street|st|road|rd|avenue|ave|close|crescent|estate|lane|way|drive|layout|junction|market|plaza)\b/i', $line)
                    && self::normalisePhone($line) === '') {
                    $out['address'] = $line;
                    break;
                }
            }
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
        $email = strtolower(trim($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private static function normalise(array $f): array
    {
        $f['name'] = mb_substr(preg_replace('/\s+/u', ' ', trim($f['name'])) ?? '', 0, 120);
        $f['phone'] = self::normalisePhone($f['phone']);
        $f['email'] = self::cleanEmail($f['email']);
        $f['address'] = mb_substr(preg_replace('/\s+/u', ' ', trim($f['address'])) ?? '', 0, 500);
        $f['city'] = mb_substr(trim($f['city']), 0, 80);
        $f['landmark'] = mb_substr(trim($f['landmark']), 0, 150);
        $state = trim($f['state']);
        $f['state'] = $state === '' ? '' : (self::detectState($state) ?: mb_substr($state, 0, 40));
        return $f;
    }

    private static function normaliseSender(array $s): array
    {
        return [
            'name' => mb_substr(preg_replace('/\s+/u', ' ', trim($s['name'])) ?? '', 0, 120),
            'phone' => self::normalisePhone($s['phone']),
            'email' => self::cleanEmail($s['email']),
            'city' => mb_substr(trim($s['city']), 0, 80),
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
