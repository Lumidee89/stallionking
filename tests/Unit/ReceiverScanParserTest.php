<?php

namespace Tests\Unit;

use App\Support\ReceiverScanParser as P;
use PHPUnit\Framework\TestCase;

class ReceiverScanParserTest extends TestCase
{
    public function test_json_qr(): void
    {
        $r = P::parse('{"name":"Ngozi Eze","phone":"+234 813 447 2201","email":"NGOZI@Example.com","state":"abuja"}');
        $this->assertSame('json', $r['format']);
        $this->assertSame('08134472201', $r['fields']['phone']);
        $this->assertSame('ngozi@example.com', $r['fields']['email']);
        $this->assertSame('FCT', $r['fields']['state']);
    }

    public function test_vcard_qr(): void
    {
        $r = P::parse("BEGIN:VCARD\nFN:Emeka Obi\nTEL;TYPE=CELL:0816 903 1157\nADR:;;22 Aba Road;Port Harcourt;Rivers;;NG\nEND:VCARD");
        $this->assertSame('Emeka Obi', $r['fields']['name']);
        $this->assertSame('Port Harcourt', $r['fields']['city']);
    }

    public function test_ocr_free_text(): void
    {
        $r = P::parse("Grace Udo\n22 Aba Road GRA\nKaduna\nTel 0708 224 9910");
        $this->assertSame('Grace Udo', $r['fields']['name']);
        $this->assertSame('07082249910', $r['fields']['phone']);
        $this->assertSame('Kaduna', $r['fields']['state']);
    }

    public function test_garbage_and_oversize_never_throw(): void
    {
        $this->assertSame(0, P::parse("\x00\x01 12345")['found']);
        $this->assertIsArray(P::parse(str_repeat('a', 100000)));
        $this->assertSame(0, P::parse('{"phone":{"a":1}}')['found']);
    }

    public function test_waybill_with_sections_fills_sender_receiver_and_package(): void
    {
        $r = P::parse("STALLIONKING WAYBILL\nSender Details\nName: Amaka Traders\nPhone: 0803 555 2210\nCity: Lagos\n"
            . "Receiver Details\nName: Bello Musa\nPhone: +234 812 123 4567\nAddress: 22 Zoo Road\nCity: Kano\nState: Kano\n"
            . "Package\nDescription: Fabrics\nWeight: 2.5kg\nService: Express delivery");
        $this->assertSame(['name' => 'Amaka Traders', 'phone' => '08035552210', 'email' => '', 'city' => 'Lagos'], $r['sender']);
        $this->assertSame('Bello Musa', $r['fields']['name']);
        $this->assertSame('08121234567', $r['fields']['phone']);
        $this->assertSame('22 Zoo Road', $r['fields']['address']);
        $this->assertSame(['description' => 'Fabrics', 'weight_range' => '1-3', 'service_type' => 'Express'], $r['package']);
    }

    public function test_prefixed_labels_and_unlabelled_sections(): void
    {
        $r = P::parse("Sender's name: Ade Bello\nSender phone: 08031234567\nReceiver:\nGrace Udo\n14 Awolowo Road\nIkoyi, Lagos\n0708 224 9910");
        $this->assertSame('Ade Bello', $r['sender']['name']);
        $this->assertSame('08031234567', $r['sender']['phone']);
        $this->assertSame('Grace Udo', $r['fields']['name']);
        $this->assertSame('07082249910', $r['fields']['phone']); // the sender's phone never leaks into the receiver
        $this->assertSame('Lagos', $r['fields']['state']);
    }

    public function test_json_with_sender_and_package(): void
    {
        $r = P::parse('{"sender":{"name":"A Co","phone":"08031234567"},"receiver":{"name":"B"},"description":"Shoes","weight":"500g","service":"standard"}');
        $this->assertSame('A Co', $r['sender']['name']);
        $this->assertSame('B', $r['fields']['name']);
        $this->assertSame(['description' => 'Shoes', 'weight_range' => 'lt1', 'service_type' => 'Standard'], $r['package']);
    }

    public function test_weight_bands(): void
    {
        foreach (['Under 1kg' => 'lt1', '1kg – 3kg' => '1-3', '4 kg' => '3-5', '7.5kg' => '5-10', '20kg' => '10-20', 'Above 20kg' => 'gt20', '35 kgs' => 'gt20', 'gt20' => 'gt20', 'heavy' => ''] as $in => $band) {
            $this->assertSame($band, P::weightBand($in), $in);
        }
    }

    /** Real-world QR and photo formats that previously lost the receiver's name and address. */
    public function test_name_and_address_are_found_in_common_formats(): void
    {
        $cases = [
            'old tracker QR keys' => ['{"r_name":"Ade Bello","r_phone":"08031234567","r_address":"12 Allen Avenue","r_city":"Ikeja"}', 'Ade Bello', '12 Allen Avenue', 'Ikeja'],
            'camelCase JSON' => ['{"fullName":"Ade Bello","phone":"08031234567","streetAddress":"12 Allen Avenue","city":"Ikeja"}', 'Ade Bello', '12 Allen Avenue', 'Ikeja'],
            'first/last name JSON' => ['{"first_name":"Ade","last_name":"Bello","address_line1":"12 Allen Avenue, Ikeja, Lagos"}', 'Ade Bello', '12 Allen Avenue, Ikeja, Lagos', 'Ikeja'],
            'nested address JSON' => ['{"receiver":{"name":"Ade Bello","address":{"street":"12 Allen Avenue","city":"Ikeja"}}}', 'Ade Bello', '12 Allen Avenue', 'Ikeja'],
            'vCard with N only and iPhone ADR' => ["BEGIN:VCARD\nVERSION:3.0\nN:Bello;Ade;;;\nTEL;type=CELL:0803 123 4567\nitem1.ADR;type=HOME:;;12 Allen Avenue;Ikeja;Lagos;;Nigeria\nEND:VCARD", 'Ade Bello', '12 Allen Avenue', 'Ikeja'],
            'one-line text QR' => ['Ade Bello, 12 Allen Avenue, Ikeja, Lagos, 08031234567', 'Ade Bello', '12 Allen Avenue', 'Ikeja'],
            'semicolon text QR' => ['Name: Ade Bello; Phone: 08031234567; Address: 12 Allen Avenue, Ikeja', 'Ade Bello', '12 Allen Avenue, Ikeja', 'Ikeja'],
            'value on the next line' => ["Receiver\nName:\nAde Bello\nAddress:\n12 Allen Avenue\nIkeja, Lagos\nPhone: 0803 123 4567", 'Ade Bello', '12 Allen Avenue', 'Ikeja'],
            'OCR slips' => ["Customer Name; Ade Bello\nAdress: 12 Allen Avenue\nPhone No. 0803 123 4567", 'Ade Bello', '12 Allen Avenue', ''],
            'two fields on one line' => ["Name: Ade Bello      Phone: 0803 123 4567\nAddress: 12 Allen Avenue, Ikeja, Lagos", 'Ade Bello', '12 Allen Avenue, Ikeja, Lagos', 'Ikeja'],
            'no street word, OCR noise' => ["| Ade Bello\nPlot 5, Lekki Phase 1\nLagos\n0803 123 4567", 'Ade Bello', 'Plot 5, Lekki Phase 1', 'Lagos'],
            'title line PARCEL' => ["PARCEL\n\nNgozi Eze\n\nPlot 14, Gwarinpa Estate\nAbuja, FCT\n\n0813 447 2201", 'Ngozi Eze', 'Plot 14, Gwarinpa Estate', 'Abuja'],
            'name of receiver' => ["Name of Receiver: Ade Bello\nHome Address: 12 Allen Avenue", 'Ade Bello', '12 Allen Avenue', ''],
            'label without colon' => ["Name Ade Bello\nAddress 12 Allen Avenue", 'Ade Bello', '12 Allen Avenue', ''],
        ];
        foreach ($cases as $label => [$text, $name, $address, $city]) {
            $f = P::parse($text)['fields'];
            $this->assertSame($name, $f['name'], "$label: name");
            $this->assertSame($address, $f['address'], "$label: address");
            $this->assertSame($city, $f['city'], "$label: city");
        }
    }

    public function test_sender_details_never_leak_into_the_receiver(): void
    {
        $r = P::parse("Sender: Shop Ltd\nSender Phone: 08031111111\nSender Address: 1 Broad Street\nReceiver: Ade Bello\nReceiver Address: 12 Allen Avenue\nReceiver Phone: 08032222222");
        $this->assertSame('Ade Bello', $r['fields']['name']);
        $this->assertSame('12 Allen Avenue', $r['fields']['address']);
        $this->assertSame('08032222222', $r['fields']['phone']);
        $this->assertSame('Shop Ltd', $r['sender']['name']);
    }

    public function test_long_ocr_text_is_accepted(): void
    {
        $r = P::parse(str_repeat("Terms and conditions apply to every shipment.\n", 150) . "Receiver\nName: Ade Bello\nAddress: 12 Allen Avenue");
        $this->assertSame('Ade Bello', $r['fields']['name']);
    }
}
