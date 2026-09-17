<?php
declare(strict_types=1);
final class CombinationService {
    public function __construct(private PDO $db) {}
    /** Creates the cartesian product in the slot order and persists each combination. */
    public function generate(int $systemId, int $structureId): int {
        $stmt = $this->db->prepare('SELECT slots FROM combination_structures WHERE id = ? AND system_id = ?');
        $stmt->execute([$structureId, $systemId]); $slots = json_decode((string) $stmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        if (!$slots) return 0;
        $byType = $this->db->prepare('SELECT id, text_value FROM elements WHERE system_id = ? AND element_type_id = ? ORDER BY id');
        $choices = [];
        foreach ($slots as $typeId) { $byType->execute([$systemId, $typeId]); $choices[] = $byType->fetchAll(); }
        if (in_array([], $choices, true)) return 0;
        $save = $this->db->prepare('INSERT IGNORE INTO generated_combinations (system_id, structure_id, value_text, element_ids) VALUES (?, ?, ?, ?)');
        $count = 0;
        $walk = function(int $slot, array $picked) use (&$walk, $choices, $save, $systemId, $structureId, &$count): void {
            if ($slot === count($choices)) { $save->execute([$systemId, $structureId, implode(' ', array_column($picked, 'text_value')), json_encode(array_column($picked, 'id'))]); $count += $save->rowCount(); return; }
            foreach ($choices[$slot] as $element) { $walk($slot + 1, [...$picked, $element]); }
        };
        $walk(0, []); return $count;
    }
}
