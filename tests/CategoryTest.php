<?php
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../src/Support/Bootstrap.php';
        if (!function_exists('renote_test_can_connect_redis') || !renote_test_can_connect_redis()) {
            $this->markTestSkipped('Redis unavailable');
        }
    }

    public function testCardDefaultsToRootCategory(): void
    {
        $id = bin2hex(random_bytes(8));
        redis_upsert_card($id, 'body', 0, 'Name', null);
        $state = load_state();
        $card = null;
        foreach ($state['cards'] as $c) { if ($c['id'] === $id) { $card = $c; break; } }
        $this->assertNotNull($card);
        $this->assertSame('root', $card['category_id']);
    }

    public function testCategoryCreateAndDelete(): void
    {
        $catId = bin2hex(random_bytes(4));
        redis_upsert_category($catId, 'TestCat', 1);
        $state = load_state();
        $found = array_filter($state['categories'] ?? [], fn($c) => $c['id'] === $catId);
        $this->assertNotEmpty($found);
        $deleted = delete_category($catId);
        $this->assertTrue($deleted);
    }
}
