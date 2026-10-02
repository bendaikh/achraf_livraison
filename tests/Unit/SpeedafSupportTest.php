<?php

namespace Tests\Unit;

use App\Services\Speedaf\SpeedafAreaResolver;
use App\Services\Speedaf\SpeedafClient;
use App\Services\Speedaf\SpeedafShipmentService;
use App\Services\Speedaf\SpeedafStatusMap;
use PHPUnit\Framework\TestCase;

class SpeedafSupportTest extends TestCase
{
    /** Webhook sample of the PDF (§5.5), raw body as pushed by Speedaf. */
    public const WEBHOOK_BODY = '{"eventId":"CN000001_86254200001257_xxxxx","customerCode":"MA000025","mailNo":"86254200001257","eventType":"TRACK_UPDATE","tracks":[{"mailNo":"86254200001257","action":"1","subAction":"2002","actionName":"Parcel scanned","message":"Parcel scanned by site","msgEng":"Parcel scanned by site","msgLoc":"Parcel scanned by site","time":"2021-05-05 21:27:34","timezone":8,"pictureUrl":"https://opa.speedaf.com/manager/file/view/example.png"}]}';

    public function test_webhook_signature_follows_pdf_rule(): void
    {
        // HMAC-SHA256(secretKey, timestamp + "\n" + requestBody), computed independently (Python hmac).
        $expected = 'hmac-sha256=b41a3e5c2a0df61d0d4cdadec8a9593c982952ae27141db582beb5c7210bae8c';

        $this->assertSame($expected, SpeedafClient::webhookSignature('test-secret-key', '1718000000000', self::WEBHOOK_BODY));
        $this->assertTrue(SpeedafClient::verifyWebhookSignature('test-secret-key', '1718000000000', self::WEBHOOK_BODY, $expected));
        $this->assertTrue(SpeedafClient::verifyWebhookSignature('test-secret-key', 1718000000000, self::WEBHOOK_BODY, strtoupper(substr($expected, 12))));
    }

    public function test_webhook_signature_rejects_tampering(): void
    {
        $sig = SpeedafClient::webhookSignature('k', '1718000000000', self::WEBHOOK_BODY);

        $this->assertFalse(SpeedafClient::verifyWebhookSignature('other', '1718000000000', self::WEBHOOK_BODY, $sig));
        $this->assertFalse(SpeedafClient::verifyWebhookSignature('k', '1718000000001', self::WEBHOOK_BODY, $sig));
        // Reformatted JSON must not validate (the raw body is signed).
        $pretty = json_encode(json_decode(self::WEBHOOK_BODY, true), JSON_PRETTY_PRINT);
        $this->assertFalse(SpeedafClient::verifyWebhookSignature('k', '1718000000000', $pretty, $sig));
        $this->assertFalse(SpeedafClient::verifyWebhookSignature('k', '1718000000000', self::WEBHOOK_BODY, null));
        $this->assertFalse(SpeedafClient::verifyWebhookSignature('', '1718000000000', self::WEBHOOK_BODY, $sig));
    }

    public function test_request_body_is_wrapped_in_data(): void
    {
        $this->assertSame('{"data":{"mailNoList":["86254200001257"]}}', SpeedafClient::body(['mailNoList' => ['86254200001257']]));
        $this->assertSame('{"data":{"name":"Fès","url":"https://x/y"}}', SpeedafClient::body(['name' => 'Fès', 'url' => 'https://x/y']));
    }

    public function test_error_codes_are_translated_in_french(): void
    {
        $this->assertStringContainsString('App Code invalide', SpeedafClient::translateError('70401', 'Invalid AppCode'));
        $this->assertStringContainsString('timestamp', SpeedafClient::translateError('70502', 'x'));
        $this->assertStringContainsString('liste blanche', SpeedafClient::translateError('70602', ''));
        $msg = SpeedafClient::translateError('500', 'sendName: Send name is null!; acceptMobile: Accept mobile is null!');
        $this->assertStringContainsString('nom de l’expéditeur manquant', $msg);
        $this->assertStringContainsString('téléphone du destinataire manquant', $msg);
    }

    public function test_phone_normalisation(): void
    {
        $this->assertSame('0612345678', SpeedafShipmentService::normalizePhone('06 12 34 56 78'));
        $this->assertSame('0612345678', SpeedafShipmentService::normalizePhone('+212 6 12-34-56-78'));
        $this->assertSame('0612345678', SpeedafShipmentService::normalizePhone('00212612345678'));
        $this->assertSame('0612345678', SpeedafShipmentService::normalizePhone('612345678'));
        $this->assertSame('', SpeedafShipmentService::normalizePhone(null));
    }

    public function test_status_keys(): void
    {
        $this->assertSame('5', SpeedafStatusMap::keyFor('5', '5'));
        $this->assertSame('2', SpeedafStatusMap::keyFor('2', '2002'));
        $this->assertSame('IP05', SpeedafStatusMap::keyFor('IP05', 'IP05-02'));
        $this->assertSame('IP01', SpeedafStatusMap::keyFor(null, 'IP01-03'));
        $this->assertSame('-710', SpeedafStatusMap::keyFor('-710'));
        $this->assertSame('999', SpeedafStatusMap::keyFor('999'));
    }

    public function test_area_index_from_tree(): void
    {
        $tree = ['code' => 'MA', 'name' => 'Morocco', 'children' => [
            ['code' => 'MAR00024', 'name' => 'Casablanca - Settat', 'children' => [
                ['code' => 'C1', 'name' => 'Casablanca', 'children' => [['name' => 'Maarif'], ['name' => 'Anfa']]],
                ['code' => 'C2', 'name' => 'MOHAMMEDIA', 'children' => [['name' => 'Mohammedia']]],
            ]],
            ['code' => 'MAR00021', 'name' => 'Fès - Meknès', 'children' => [['code' => 'C3', 'name' => 'Fès', 'children' => []]]],
        ]];
        $index = SpeedafAreaResolver::buildIndex($tree);

        $this->assertSame('Casablanca - Settat', $index['mohammedia']['province']);
        $this->assertSame('MOHAMMEDIA', $index[SpeedafAreaResolver::normalize('Mohammédia')]['city']);
        $this->assertSame('Fès - Meknès', $index[SpeedafAreaResolver::normalize('fes')]['province']);
        $this->assertSame(['Maarif', 'Anfa'], $index['casablanca']['districts']);
    }
}
