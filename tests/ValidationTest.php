<?php
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../src/Support/Bootstrap.php';
    }

    public function testInvalidIdFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        card_validate_id_and_text('not_valid$id', 'hello');
    }

    public function testOversizeText(): void
    {
        // Use the same effective limit as the validator: when undefined or <=0, default to 262144
        $max = (defined('APP_CARD_MAX_LEN') && APP_CARD_MAX_LEN > 0) ? APP_CARD_MAX_LEN : 262144;
        $this->expectException(LengthException::class);
        $big = str_repeat('x', $max + 1);
        card_validate_id_and_text(str_repeat('a',16), $big);
    }

    public function testValidHexId(): void
    {
        card_validate_id_and_text(str_repeat('a',32), 'ok');
        $this->assertTrue(true);
    }
}

