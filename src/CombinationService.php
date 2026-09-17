<?php
declare(strict_types=1);
final class CombinationService {
    public function __construct(private PDO $db) {}

    /** Formats selected elements according to their spacing and casing settings. */
    public static function formatValue(array $picked): string {
        $value = '';
        foreach ($picked as $index => $element) {
            $separator = $index > 0 && ($element['spacing'] ?? 'with_space') !== 'without_space' ? ' ' : '';
            $value .= $separator . self::formatElementText($element, $index === 0);
        }
        return $value;
    }

    /** Keeps protected text unchanged; normalizes other text and capitalizes it only at the start. */
    private static function formatElementText(array $element, bool $isAtStart): string {
        $text = $element['text_value'];
        if (($element['letter_case'] ?? 'mixed_case') === 'initial_always_uppercase') return $text;

        $text = mb_strtolower($text, 'UTF-8');
        if (!$isAtStart || $text === '') return $text;
        return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
    }

    /** Creates the cartesian product in the slot order and persists each combination. */
    public function generate(int $systemId, int $structureId): int {
        $stmt = $this->db->prepare('SELECT slots FROM combination_structures WHERE id = ? AND system_id = ?');
        $stmt->execute([$structureId, $systemId]); $slots = json_decode((string) $stmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        if (!$slots) return 0;
        $byType = $this->db->prepare('SELECT elements.id, elements.text_value, element_types.spacing, element_types.letter_case FROM elements JOIN element_types ON element_types.id = elements.element_type_id WHERE elements.system_id = ? AND elements.element_type_id = ? ORDER BY elements.id');
        $choices = [];
        foreach ($slots as $typeId) { $byType->execute([$systemId, $typeId]); $choices[] = $byType->fetchAll(); }
        if (in_array([], $choices, true)) return 0;
        $save = $this->db->prepare('INSERT IGNORE INTO generated_combinations (system_id, structure_id, value_text, element_ids) VALUES (?, ?, ?, ?)');
        $count = 0;
        $walk = function(int $slot, array $picked) use (&$walk, $choices, $save, $systemId, $structureId, &$count): void {
            if ($slot === count($choices)) { $save->execute([$systemId, $structureId, self::formatValue($picked), json_encode(array_column($picked, 'id'))]); $count += $save->rowCount(); return; }
            foreach ($choices[$slot] as $element) { $walk($slot + 1, [...$picked, $element]); }
        };
        $walk(0, []); return $count;
    }
}
