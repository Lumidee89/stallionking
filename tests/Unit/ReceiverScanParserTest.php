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
}
