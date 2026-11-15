<?php

namespace Tests\Unit;

use App\Payments\PaymentResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PaymentResultTest extends TestCase
{
    public function test_existing_positional_and_named_calls_remain_compatible(): void
    {
        $result = new PaymentResult('pending', 'ORDER123', 'https://example.test/approve', 'code', 'message');
        $this->assertNull($result->captureId);
        $this->assertSame('ORDER123', $result->externalId);
        $this->assertNull((new PaymentResult('pending', errorCode: 'code'))->captureId);
    }

    public function test_capture_id_is_independent_of_order_id_and_accepts_length_boundary(): void
    {
        $result = new PaymentResult('succeeded', 'ORDER123', captureId: str_repeat('A', 255));
        $this->assertSame('ORDER123', $result->externalId);
        $this->assertSame(str_repeat('A', 255), $result->captureId);
    }

    public function test_blank_and_overlong_capture_ids_are_rejected(): void
    {
        foreach (['', '   ', "\t\n", str_repeat('A', 256)] as $id) {
            try {
                new PaymentResult('succeeded', 'ORDER123', captureId: $id);
                $this->fail('Invalid capture ID accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Invalid payment result metadata.', $exception->getMessage());
            }
        }
    }
}
