<?php
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../bootstrap.php';
    }

    public function testInvalidIdFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        card_validate_id_and_text('not_valid$id', 'hello');
    }

    public function testOversizeText(): void
    {
        if (!defined('APP_CARD_MAX_LEN')) {
            $this->markTestSkipped('APP_CARD_MAX_LEN not defined');
        }
        $this->expectException(LengthException::class);
        $big = str_repeat('x', APP_CARD_MAX_LEN + 1);
        card_validate_id_and_text(str_repeat('a',16), $big);
    }

    public function testValidHexId(): void
    {
        card_validate_id_and_text(str_repeat('a',32), 'ok');
        $this->assertTrue(true);
    }
}

