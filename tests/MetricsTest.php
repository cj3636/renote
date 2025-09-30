<?php
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../bootstrap.php';
        if (!function_exists('metrics_snapshot')) {
            $this->markTestSkipped('metrics_snapshot not available');
        }
        // Skip if Redis unreachable
        try { redis_client()->ping(); } catch (Throwable $e) { $this->markTestSkipped('Redis unavailable'); }
    }

    public function testMetricsIncrement(): void
    {
        $before = metrics_snapshot();
        $id = bin2hex(random_bytes(8));
        redis_upsert_card($id, 'metrics test body', 0, 'MTest');
        delete_card_redis_only($id);
        $after = metrics_snapshot();
        $this->assertTrue($after['saves'] >= $before['saves'] + 1, 'Saves counter should increment');
        $this->assertTrue($after['deletes'] >= $before['deletes'] + 1, 'Deletes counter should increment');
    }
}

