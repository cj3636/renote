<?php
use PHPUnit\Framework\TestCase;

final class StateTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../bootstrap.php';
        try { redis_client()->ping(); } catch (Throwable $e) { $this->markTestSkipped('Redis unavailable'); }
    }

    public function testLoadStateIncludesName(): void
    {
        $id = bin2hex(random_bytes(8));
        $name = 'TitleTest';
        redis_upsert_card($id, 'Body for title test', 0, $name);
        $state = load_state();
        $found = null;
        foreach ($state['cards'] as $c) { if (isset($c['id']) && $c['id'] === $id) { $found = $c; break; } }
        if ($found === null) {
            throw new Exception('Inserted card should be present in state');
        }
        $hasName = is_array($found) && array_key_exists('name', $found);
        if (!$hasName) {
            throw new Exception('Card should include name field');
        }
        if ($found['name'] !== $name) {
            throw new Exception('Card name should round-trip');
        }
    }
}
