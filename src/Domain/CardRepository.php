<?php
namespace Renote\Domain;

/**
 * Thin repository wrapper delegating to existing global functions for now.
 * Facilitates future refactors without touching API/controller code.
 */
class CardRepository
{
    public function loadState(): array { return \load_state(); }
    public function upsert(string $id, string $text, int $order, string $name=''): int { return \redis_upsert_card($id,$text,$order,$name); }
    public function softDelete(string $id): void { \delete_card_redis_only($id); }
    public function deleteEverywhere(string $id): void { \delete_card_everywhere($id); }
    public function history(): array { return \db_orphans(); }
    public function metrics(): array { return \metrics_snapshot(); }
}

