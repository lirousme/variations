<?php
declare(strict_types=1);

require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/CombinationService.php';

const PAGE_SIZE = 10;
const PAGES = ['overview', 'types', 'elements', 'structures', 'combinations'];

$db = Database::connect();
$base = rtrim(getenv('APP_BASE_PATH') ?: dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$cssVersion = (string) filemtime(__DIR__ . '/assets/app.css');

function go(string $url): never { header('Location: ' . $url); exit; }
function id(string $key): int { return filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT) ?: 0; }
function selectedIds(string $key): array { return array_values(array_unique(array_filter(array_map('intval', $_POST[$key] ?? [])))); }
function pageNumber(): int { return max(1, filter_input(INPUT_GET, 'page_number', FILTER_VALIDATE_INT) ?: 1); }
function postedPageNumber(): int { return max(1, filter_input(INPUT_POST, 'page_number', FILTER_VALIDATE_INT) ?: 1); }
function url(string $page, int $systemId = 0, array $parameters = []): string {
    global $base;
    $query = array_filter(['system' => $systemId ?: null, 'page' => $page, ...$parameters], static fn($value) => $value !== null && $value !== '');
    return $base . '/?' . http_build_query($query);
}
function queryPage(PDO $db, string $table, int $systemId, int $currentPage): array {
    $tables = ['element_types', 'elements', 'combination_structures', 'generated_combinations'];
    if (!in_array($table, $tables, true)) throw new InvalidArgumentException('Tabela não permitida.');
    $count = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE system_id = ?");
    $count->execute([$systemId]);
    $total = (int) $count->fetchColumn();
    $lastPage = max(1, (int) ceil($total / PAGE_SIZE));
    $currentPage = min($currentPage, $lastPage);
    $items = $db->prepare("SELECT * FROM {$table} WHERE system_id = ? ORDER BY id DESC LIMIT " . PAGE_SIZE . ' OFFSET ' . (($currentPage - 1) * PAGE_SIZE));
    $items->execute([$systemId]);
    return [$items->fetchAll(), $total, $currentPage, $lastPage];
}
function pagination(int $currentPage, int $lastPage, string $page, int $systemId, string $pageParameter = 'page_number'): string {
    if ($lastPage < 2) return '';
    $previous = $currentPage > 1 ? '<a href="' . htmlspecialchars(url($page, $systemId, [$pageParameter => $currentPage - 1])) . '">← Anterior</a>' : '<span>← Anterior</span>';
    $next = $currentPage < $lastPage ? '<a href="' . htmlspecialchars(url($page, $systemId, [$pageParameter => $currentPage + 1])) . '">Próxima →</a>' : '<span>Próxima →</span>';
    return '<nav class="pagination">' . $previous . '<b>Página ' . $currentPage . ' de ' . $lastPage . '</b>' . $next . '</nav>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $systemId = id('system_id');
    $redirectPage = in_array($_POST['return_page'] ?? '', PAGES, true) ? $_POST['return_page'] : 'overview';

    if ($action === 'system') {
        $db->prepare('INSERT INTO systems(name) VALUES(?)')->execute([trim($_POST['name'])]);
        go(url('overview', (int) $db->lastInsertId()));
    }
    if ($action === 'update_system') {
        $targetId = id('item_id');
        $name = trim($_POST['name'] ?? '');
        if ($targetId && $name !== '') $db->prepare('UPDATE systems SET name = ? WHERE id = ?')->execute([$name, $targetId]);
        go(url('overview', $targetId));
    }
    if ($action === 'delete_system') {
        $db->prepare('DELETE FROM systems WHERE id = ?')->execute([id('item_id')]);
        go(url('overview'));
    }
    if ($action === 'type') {
        $spacing = ($_POST['spacing'] ?? '') === 'without_space' ? 'without_space' : 'with_space';
        $letterCase = ($_POST['letter_case'] ?? '') === 'initial_always_uppercase' ? 'initial_always_uppercase' : 'mixed_case';
        $db->prepare('INSERT INTO element_types(system_id,name,spacing,letter_case) VALUES(?,?,?,?)')->execute([$systemId, trim($_POST['name']), $spacing, $letterCase]);
    }
    if ($action === 'update_type') {
        $spacing = ($_POST['spacing'] ?? '') === 'without_space' ? 'without_space' : 'with_space';
        $letterCase = ($_POST['letter_case'] ?? '') === 'initial_always_uppercase' ? 'initial_always_uppercase' : 'mixed_case';
        $db->prepare('UPDATE element_types SET name = ?, spacing = ?, letter_case = ? WHERE id = ? AND system_id = ?')->execute([trim($_POST['name']), $spacing, $letterCase, id('item_id'), $systemId]);
    }
    if ($action === 'delete_type') {
        $targetId = id('item_id');
        try {
            $db->beginTransaction();
            $structures = $db->prepare('SELECT id, slots FROM combination_structures WHERE system_id = ?');
            $structures->execute([$systemId]);
            $removeStructure = $db->prepare('DELETE FROM combination_structures WHERE id = ? AND system_id = ?');
            foreach ($structures->fetchAll() as $structure) if (in_array($targetId, array_map('intval', json_decode($structure['slots'], true) ?: []), true)) $removeStructure->execute([$structure['id'], $systemId]);
            $db->prepare('DELETE FROM element_type_assignments WHERE element_type_id = ?')->execute([$targetId]);
            $db->prepare('DELETE FROM element_types WHERE id = ? AND system_id = ?')->execute([$targetId, $systemId]);
            $db->commit();
        } catch (PDOException $exception) { if ($db->inTransaction()) $db->rollBack(); throw $exception; }
    }
    if ($action === 'element') {
        $types = array_values(array_unique(array_filter(array_map('intval', $_POST['element_type_ids'] ?? []))));
        if ($types) {
            $valid = $db->prepare('SELECT COUNT(*) FROM element_types WHERE system_id = ? AND id IN (' . implode(',', array_fill(0, count($types), '?')) . ')');
            $valid->execute([$systemId, ...$types]);
            if ((int) $valid->fetchColumn() !== count($types)) go(url('elements', $systemId));
            try {
                $db->beginTransaction();
                $element = $db->prepare('INSERT IGNORE INTO elements(system_id,text_value) VALUES(?,?)');
                $existing = $db->prepare('SELECT id FROM elements WHERE system_id = ? AND text_value_hash = UNHEX(SHA2(?, 256))');
                $assign = $db->prepare('INSERT IGNORE INTO element_type_assignments(element_id,element_type_id) VALUES(?,?)');
                $newAssignments = 0;
                $texts = array_filter(array_map('trim', explode(';', is_string($_POST['text_value'] ?? null) ? $_POST['text_value'] : '')), static fn($text) => $text !== '');
                foreach ($texts as $text) {
                    $element->execute([$systemId, $text]);
                    if ($element->rowCount()) {
                        $elementId = (int) $db->lastInsertId();
                    } else {
                        $existing->execute([$systemId, $text]);
                        $elementId = (int) $existing->fetchColumn();
                    }
                    foreach ($types as $type) { $assign->execute([$elementId, $type]); $newAssignments += $assign->rowCount(); }
                }
                $db->commit();
                if (!$newAssignments) go(url('elements', $systemId, ['duplicate_element' => 1]));
            } catch (PDOException $exception) {
                if ($db->inTransaction()) $db->rollBack();
                throw $exception;
            }
        }
    }
    if ($action === 'update_element') {
        $types = selectedIds('element_type_ids');
        $targetId = id('item_id');
        if ($types && trim($_POST['text_value'] ?? '') !== '') {
            $valid = $db->prepare('SELECT COUNT(*) FROM element_types WHERE system_id = ? AND id IN (' . implode(',', array_fill(0, count($types), '?')) . ')');
            $valid->execute([$systemId, ...$types]);
            if ((int) $valid->fetchColumn() === count($types)) {
                try {
                    $db->beginTransaction();
                    $db->prepare('UPDATE elements SET text_value = ? WHERE id = ? AND system_id = ?')->execute([trim($_POST['text_value']), $targetId, $systemId]);
                    $db->prepare('DELETE FROM element_type_assignments WHERE element_id = ?')->execute([$targetId]);
                    $assign = $db->prepare('INSERT INTO element_type_assignments(element_id,element_type_id) VALUES(?,?)');
                    foreach ($types as $typeId) $assign->execute([$targetId, $typeId]);
                    $db->commit();
                } catch (PDOException $exception) { if ($db->inTransaction()) $db->rollBack(); throw $exception; }
            }
        }
    }
    if ($action === 'delete_element') $db->prepare('DELETE FROM elements WHERE id = ? AND system_id = ?')->execute([id('item_id'), $systemId]);
    if ($action === 'structure') {
        $slots = array_values(array_filter(array_map('intval', $_POST['slots'] ?? [])));
        if ($slots) {
            $valid = $db->prepare('SELECT COUNT(*) FROM element_types WHERE system_id = ? AND id IN (' . implode(',', array_fill(0, count($slots), '?')) . ')');
            $valid->execute([$systemId, ...$slots]);
            if ((int) $valid->fetchColumn() === count(array_unique($slots))) $db->prepare('INSERT INTO combination_structures(system_id,name,slots) VALUES(?,?,?)')->execute([$systemId, trim($_POST['name']), json_encode($slots)]);
        }
    }
    if ($action === 'update_structure') {
        $slots = array_values(array_filter(array_map('intval', $_POST['slots'] ?? [])));
        if ($slots) {
            $valid = $db->prepare('SELECT COUNT(*) FROM element_types WHERE system_id = ? AND id IN (' . implode(',', array_fill(0, count($slots), '?')) . ')');
            $valid->execute([$systemId, ...$slots]);
            if ((int) $valid->fetchColumn() === count(array_unique($slots))) $db->prepare('UPDATE combination_structures SET name = ?, slots = ? WHERE id = ? AND system_id = ?')->execute([trim($_POST['name']), json_encode($slots), id('item_id'), $systemId]);
        }
    }
    if ($action === 'delete_structure') $db->prepare('DELETE FROM combination_structures WHERE id = ? AND system_id = ?')->execute([id('item_id'), $systemId]);
    if ($action === 'generate') {
        $new = (new CombinationService($db))->generate($systemId, id('structure_id'));
        go(url('combinations', $systemId, ['created' => $new]));
    }
    if ($action === 'lexical_chunk') {
        $db->prepare('UPDATE generated_combinations SET lexical_chunked = 1 WHERE id = ? AND system_id = ?')->execute([id('combination_id'), $systemId]);
        go(url('combinations', $systemId, ['page_number' => postedPageNumber()]));
    }
    if ($action === 'update_combination') {
        $db->prepare('UPDATE generated_combinations SET value_text = ?, lexical_chunked = ? WHERE id = ? AND system_id = ?')->execute([trim($_POST['value_text']), isset($_POST['lexical_chunked']) ? 1 : 0, id('item_id'), $systemId]);
    }
    if ($action === 'delete_combination') $db->prepare('DELETE FROM generated_combinations WHERE id = ? AND system_id = ?')->execute([id('item_id'), $systemId]);
    go(url($redirectPage, $systemId));
}

$requestedSystemId = filter_input(INPUT_GET, 'system', FILTER_VALIDATE_INT) ?: 0;
$system = null;
if ($requestedSystemId) {
    $systemQuery = $db->prepare('SELECT * FROM systems WHERE id = ?');
    $systemQuery->execute([$requestedSystemId]);
    $system = $systemQuery->fetch();
}
if (!$system) $system = $db->query('SELECT * FROM systems ORDER BY id LIMIT 1')->fetch();
$systemId = (int) ($system['id'] ?? 0);
$currentPage = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'overview';
if (!in_array($currentPage, PAGES, true)) $currentPage = 'overview';

if ($currentPage === 'types' && $systemId && isset($_GET['copy_type'])) {
    $typeId = filter_input(INPUT_GET, 'copy_type', FILTER_VALIDATE_INT) ?: 0;
    $elementsForType = $db->prepare('SELECT elements.text_value FROM elements JOIN element_type_assignments ON element_type_assignments.element_id = elements.id JOIN element_types ON element_types.id = element_type_assignments.element_type_id WHERE elements.system_id = ? AND element_types.system_id = ? AND element_type_assignments.element_type_id = ? ORDER BY elements.id');
    $elementsForType->execute([$systemId, $systemId, $typeId]);
    header('Content-Type: text/plain; charset=utf-8');
    echo implode(';', array_column($elementsForType->fetchAll(), 'text_value'));
    exit;
}

$systemsPage = max(1, filter_input(INPUT_GET, 'systems_page', FILTER_VALIDATE_INT) ?: 1);
$systemsTotal = (int) $db->query('SELECT COUNT(*) FROM systems')->fetchColumn();
$systemsLastPage = max(1, (int) ceil($systemsTotal / PAGE_SIZE));
$systemsPage = min($systemsPage, $systemsLastPage);
$systems = $db->query('SELECT * FROM systems ORDER BY name, id LIMIT ' . PAGE_SIZE . ' OFFSET ' . (($systemsPage - 1) * PAGE_SIZE))->fetchAll();

$totals = ['types' => 0, 'elements' => 0, 'structures' => 0, 'combinations' => 0];
$items = [];
$listPage = 1;
$listLastPage = 1;
if ($systemId) {
    foreach (['types' => 'element_types', 'elements' => 'elements', 'structures' => 'combination_structures', 'combinations' => 'generated_combinations'] as $key => $table) {
        $statement = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE system_id = ?");
        $statement->execute([$systemId]);
        $totals[$key] = (int) $statement->fetchColumn();
    }
    $tableForPage = ['types' => 'element_types', 'elements' => 'elements', 'structures' => 'combination_structures', 'combinations' => 'generated_combinations'][$currentPage] ?? null;
    if ($tableForPage) [$items, , $listPage, $listLastPage] = queryPage($db, $tableForPage, $systemId, pageNumber());
}

$visibleTypeIds = [];
if ($currentPage === 'structures') foreach ($items as $structure) foreach (json_decode($structure['slots'], true) ?: [] as $typeId) $visibleTypeIds[] = $typeId;
$visibleTypeIds = array_values(array_unique(array_map('intval', $visibleTypeIds)));
$typeNames = [];
if ($visibleTypeIds) {
    $labels = $db->prepare('SELECT id, name FROM element_types WHERE system_id = ? AND id IN (' . implode(',', array_fill(0, count($visibleTypeIds), '?')) . ')');
    $labels->execute([$systemId, ...$visibleTypeIds]);
    $typeNames = array_column($labels->fetchAll(), 'name', 'id');
}
$elementTypes = [];
if ($currentPage === 'elements' && $items) {
    $elementIds = array_column($items, 'id');
    $assignments = $db->prepare('SELECT element_type_assignments.element_id, element_types.name FROM element_type_assignments JOIN element_types ON element_types.id = element_type_assignments.element_type_id WHERE element_types.system_id = ? AND element_type_assignments.element_id IN (' . implode(',', array_fill(0, count($elementIds), '?')) . ') ORDER BY element_types.name, element_types.id');
    $assignments->execute([$systemId, ...$elementIds]);
    foreach ($assignments->fetchAll() as $assignment) $elementTypes[$assignment['element_id']][] = $assignment['name'];
}
$availableTypes = [];
if ($systemId && in_array($currentPage, ['elements', 'structures'], true)) {
    $availableTypesQuery = $db->prepare('SELECT id, name FROM element_types WHERE system_id = ? ORDER BY name, id');
    $availableTypesQuery->execute([$systemId]);
    $availableTypes = $availableTypesQuery->fetchAll();
}
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lexical Chunks</title><link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/assets/app.css?v=<?= htmlspecialchars($cssVersion) ?>"></head><body>
<aside><div class="brand">◈ <span>Lexical<br><b>Chunks</b></span></div><nav>
<?php foreach (['overview' => 'Visão geral', 'types' => 'Tipos', 'elements' => 'Elementos', 'structures' => 'Estruturas', 'combinations' => 'Combinações'] as $page => $label): ?><a class="<?= $currentPage === $page ? 'active' : '' ?>" href="<?= htmlspecialchars(url($page, $systemId)) ?>"><?= $label ?></a><?php endforeach; ?>
</nav><form method="post" class="new-system"><input type="hidden" name="action" value="system"><input name="name" required placeholder="Novo sistema"><button>+ Criar sistema</button></form><div class="system-list"><span class="eyebrow">SISTEMAS</span><?php foreach ($systems as $listed): ?><div class="system-item <?= $systemId === (int) $listed['id'] ? 'current' : '' ?>"><a href="<?= htmlspecialchars(url($currentPage, (int) $listed['id'])) ?>"><?= htmlspecialchars($listed['name']) ?></a><button type="button" class="settings" aria-label="Configurar <?= htmlspecialchars($listed['name']) ?>" onclick="document.getElementById('system-<?= $listed['id'] ?>').showModal()">⚙</button></div><dialog id="system-<?= $listed['id'] ?>" class="modal"><form method="dialog"><button class="close" aria-label="Fechar">×</button></form><h2>Configurar sistema</h2><form method="post"><input type="hidden" name="action" value="update_system"><input type="hidden" name="item_id" value="<?= $listed['id'] ?>"><input name="name" required value="<?= htmlspecialchars($listed['name']) ?>"><button>Salvar alterações</button></form><form method="post" class="delete-form"><input type="hidden" name="action" value="delete_system"><input type="hidden" name="item_id" value="<?= $listed['id'] ?>"><button onclick="return confirm('Excluir este sistema e todos os seus dados?')">Excluir sistema</button></form></dialog><?php endforeach; ?></div><?= pagination($systemsPage, $systemsLastPage, $currentPage, $systemId, 'systems_page') ?></aside>
<main><?php if (!$systemId): ?><section class="empty"><p>Comece criando um sistema.</p></section><?php else: ?><header><div><span class="eyebrow">SISTEMA ATIVO</span><h1><?= htmlspecialchars($system['name']) ?></h1></div><a href="<?= htmlspecialchars(url('structures', $systemId)) ?>" class="button">Criar estrutura</a></header>
<?php if (isset($_GET['created'])): ?><div class="notice"><?= intval($_GET['created']) ?> combinações novas geradas.</div><?php endif; ?>
<?php if (isset($_GET['duplicate_element'])): ?><div class="notice">Este elemento já está associado a todos os tipos selecionados.</div><?php endif; ?>
<?php if ($currentPage === 'overview'): ?><section class="cards"><article><strong><?= $totals['types'] ?></strong><span>Tipos</span></article><article><strong><?= $totals['elements'] ?></strong><span>Elementos</span></article><article><strong><?= $totals['structures'] ?></strong><span>Estruturas</span></article><article><strong><?= $totals['combinations'] ?></strong><span>Combinações</span></article></section><section class="panel overview"><h2>Dados organizados em páginas</h2><p>Use o menu para cadastrar e consultar cada categoria separadamente. Cada listagem mostra no máximo 10 registros por página.</p></section>
<?php elseif ($currentPage === 'types'): ?><section class="panel"><h2>Tipos de elemento</h2><form method="post"><input type="hidden" name="action" value="type"><input type="hidden" name="return_page" value="types"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input name="name" required placeholder="Ex.: Verbo"><select name="spacing" aria-label="Espaçamento do tipo"><option value="with_space" selected>COM espaço</option><option value="without_space">SEM espaço</option></select><select name="letter_case" aria-label="Capitalização do tipo"><option value="mixed_case" selected>Maiúscula e minúscula</option><option value="initial_always_uppercase">Inicial sempre maiúscula</option></select><button>Adicionar</button></form><ul><?php foreach ($items as $type): ?><li><small>ID <?= $type['id'] ?> · <?= ($type['spacing'] ?? 'with_space') === 'without_space' ? 'SEM espaço' : 'COM espaço' ?> · <?= ($type['letter_case'] ?? 'mixed_case') === 'initial_always_uppercase' ? 'Inicial sempre maiúscula' : 'Maiúscula e minúscula' ?></small><i></i><?= htmlspecialchars($type['name']) ?><button type="button" class="settings" onclick="document.getElementById('type-<?= $type['id'] ?>').showModal()">⚙ Configurar</button><button type="button" class="settings copy-type" data-copy-url="<?= htmlspecialchars(url('types', $systemId, ['copy_type' => $type['id']])) ?>" aria-label="Copiar elementos do tipo <?= htmlspecialchars($type['name']) ?>">Copiar</button><dialog id="type-<?= $type['id'] ?>" class="modal"><form method="dialog"><button class="close" aria-label="Fechar">×</button></form><h2>Configurar tipo</h2><form method="post"><input type="hidden" name="action" value="update_type"><input type="hidden" name="return_page" value="types"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input type="hidden" name="item_id" value="<?= $type['id'] ?>"><input name="name" required value="<?= htmlspecialchars($type['name']) ?>"><select name="spacing"><option value="with_space"<?= ($type['spacing'] ?? '') === 'with_space' ? ' selected' : '' ?>>COM espaço</option><option value="without_space"<?= ($type['spacing'] ?? '') === 'without_space' ? ' selected' : '' ?>>SEM espaço</option></select><select name="letter_case"><option value="mixed_case"<?= ($type['letter_case'] ?? '') === 'mixed_case' ? ' selected' : '' ?>>Maiúscula e minúscula</option><option value="initial_always_uppercase"<?= ($type['letter_case'] ?? '') === 'initial_always_uppercase' ? ' selected' : '' ?>>Inicial sempre maiúscula</option></select><button>Salvar alterações</button></form><form method="post" class="delete-form"><input type="hidden" name="action" value="delete_type"><input type="hidden" name="return_page" value="types"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input type="hidden" name="item_id" value="<?= $type['id'] ?>"><button onclick="return confirm('Excluir este tipo e as estruturas que o usam?')">Excluir tipo</button></form></dialog></li><?php endforeach; ?></ul><?= pagination($listPage, $listLastPage, 'types', $systemId) ?></section>
<?php elseif ($currentPage === 'elements'): ?><section class="panel"><h2>Elementos</h2><p>Selecione um ou mais tipos pertencentes a este sistema. Para cadastrar vários elementos, separe os textos por ponto e vírgula.</p><form method="post"><input type="hidden" name="action" value="element"><input type="hidden" name="return_page" value="elements"><input type="hidden" name="system_id" value="<?= $systemId ?>"><select name="element_type_ids[]" multiple required aria-label="Tipos do elemento"<?= $availableTypes ? '' : ' disabled' ?>><?php foreach ($availableTypes as $type): ?><option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option><?php endforeach; ?></select><input name="text_value" required placeholder="Texto do elemento; outro elemento"><button<?= $availableTypes ? '' : ' disabled' ?>>Salvar</button></form><ul><?php foreach ($items as $element): ?><li><small><?= htmlspecialchars(implode(' · ', $elementTypes[$element['id']] ?? ['Tipo removido'])) ?></small><?= htmlspecialchars($element['text_value']) ?><button type="button" class="settings" onclick="document.getElementById('element-<?= $element['id'] ?>').showModal()">⚙ Configurar</button><dialog id="element-<?= $element['id'] ?>" class="modal"><form method="dialog"><button class="close" aria-label="Fechar">×</button></form><h2>Configurar elemento</h2><form method="post"><input type="hidden" name="action" value="update_element"><input type="hidden" name="return_page" value="elements"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input type="hidden" name="item_id" value="<?= $element['id'] ?>"><select name="element_type_ids[]" multiple required><?php foreach ($availableTypes as $availableType): ?><option value="<?= $availableType['id'] ?>"<?= in_array($availableType['name'], $elementTypes[$element['id']] ?? [], true) ? ' selected' : '' ?>><?= htmlspecialchars($availableType['name']) ?></option><?php endforeach; ?></select><input name="text_value" required value="<?= htmlspecialchars($element['text_value']) ?>"><button>Salvar alterações</button></form><form method="post" class="delete-form"><input type="hidden" name="action" value="delete_element"><input type="hidden" name="return_page" value="elements"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input type="hidden" name="item_id" value="<?= $element['id'] ?>"><button onclick="return confirm('Excluir este elemento?')">Excluir elemento</button></form></dialog></li><?php endforeach; ?></ul><?= pagination($listPage, $listLastPage, 'elements', $systemId) ?></section>
<?php elseif ($currentPage === 'structures'): ?><section class="panel"><h2>Estruturas ordenadas</h2><p>Selecione os tipos na sequência desejada. Cada posição usa somente elementos do tipo escolhido neste sistema.</p><form method="post" class="structure"><input type="hidden" name="action" value="structure"><input type="hidden" name="return_page" value="structures"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input name="name" required placeholder="Nome da estrutura"><div id="slots"><select name="slots[]" required<?= $availableTypes ? '' : ' disabled' ?>><option value="" selected disabled><?= $availableTypes ? 'Selecione o tipo' : 'Cadastre um tipo primeiro' ?></option><?php foreach ($availableTypes as $type): ?><option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option><?php endforeach; ?></select></div><button type="button" class="secondary" onclick="addSlot()"<?= $availableTypes ? '' : ' disabled' ?>>+ Posição</button><button<?= $availableTypes ? '' : ' disabled' ?>>Salvar estrutura</button></form><div class="structures"><?php foreach ($items as $structure): $slotLabels = array_map(fn($value) => $typeNames[$value] ?? 'Tipo removido', json_decode($structure['slots'], true) ?: []); ?><article><b><?= htmlspecialchars($structure['name']) ?></b><span><?= htmlspecialchars(implode(' → ', $slotLabels)) ?></span><form method="post"><input type="hidden" name="action" value="generate"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input type="hidden" name="structure_id" value="<?= $structure['id'] ?>"><button>Gerar</button></form><button type="button" class="settings" onclick="document.getElementById('structure-<?= $structure['id'] ?>').showModal()">⚙ Configurar</button><dialog id="structure-<?= $structure['id'] ?>" class="modal"><form method="dialog"><button class="close" aria-label="Fechar">×</button></form><h2>Configurar estrutura</h2><form method="post" class="structure"><input type="hidden" name="action" value="update_structure"><input type="hidden" name="return_page" value="structures"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input type="hidden" name="item_id" value="<?= $structure['id'] ?>"><input name="name" required value="<?= htmlspecialchars($structure['name']) ?>"><div class="modal-slots"><?php foreach (json_decode($structure['slots'], true) ?: [] as $slot): ?><select name="slots[]" required><?php foreach ($availableTypes as $availableType): ?><option value="<?= $availableType['id'] ?>"<?= (int) $slot === (int) $availableType['id'] ? ' selected' : '' ?>><?= htmlspecialchars($availableType['name']) ?></option><?php endforeach; ?></select><?php endforeach; ?></div><button>Salvar alterações</button></form><form method="post" class="delete-form"><input type="hidden" name="action" value="delete_structure"><input type="hidden" name="return_page" value="structures"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input type="hidden" name="item_id" value="<?= $structure['id'] ?>"><button onclick="return confirm('Excluir esta estrutura e suas combinações?')">Excluir estrutura</button></form></dialog></article><?php endforeach; ?></div><?= pagination($listPage, $listLastPage, 'structures', $systemId) ?></section>
<?php else: ?><section class="panel"><h2>Combinações geradas</h2><div class="results"><?php foreach ($items as $combination): ?><form method="post"><input type="hidden" name="action" value="lexical_chunk"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input type="hidden" name="combination_id" value="<?= $combination['id'] ?>"><input type="hidden" name="page_number" value="<?= $listPage ?>"><button type="submit" class="combination<?= (int) $combination['lexical_chunked'] === 1 ? ' lexical-chunked' : '' ?>"<?= (int) $combination['lexical_chunked'] === 1 ? ' aria-pressed="true"' : ' aria-pressed="false"' ?>><?= htmlspecialchars($combination['value_text']) ?></button></form><button type="button" class="settings" onclick="document.getElementById('combination-<?= $combination['id'] ?>').showModal()">⚙ Configurar</button><dialog id="combination-<?= $combination['id'] ?>" class="modal"><form method="dialog"><button class="close" aria-label="Fechar">×</button></form><h2>Configurar combinação</h2><form method="post"><input type="hidden" name="action" value="update_combination"><input type="hidden" name="return_page" value="combinations"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input type="hidden" name="item_id" value="<?= $combination['id'] ?>"><input name="value_text" required value="<?= htmlspecialchars($combination['value_text']) ?>"><label class="checkbox"><input type="checkbox" name="lexical_chunked" value="1"<?= (int) $combination['lexical_chunked'] === 1 ? ' checked' : '' ?>> Marcar como lexical chunk</label><button>Salvar alterações</button></form><form method="post" class="delete-form"><input type="hidden" name="action" value="delete_combination"><input type="hidden" name="return_page" value="combinations"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input type="hidden" name="item_id" value="<?= $combination['id'] ?>"><button onclick="return confirm('Excluir esta combinação?')">Excluir combinação</button></form></dialog><?php endforeach; ?></div><?= pagination($listPage, $listLastPage, 'combinations', $systemId) ?></section><?php endif; ?>
<?php endif; ?></main><template id="slot-template"><select name="slots[]" required><option value="" selected disabled>Selecione o tipo</option><?php foreach ($availableTypes as $type): ?><option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option><?php endforeach; ?></select></template><script>function addSlot(){document.querySelector('#slots').append(document.querySelector('#slot-template').content.cloneNode(true))}async function copyTypeElements(button){const originalLabel=button.textContent;try{const response=await fetch(button.dataset.copyUrl);if(!response.ok)throw new Error('Não foi possível obter os elementos.');const text=await response.text();if(navigator.clipboard&&window.isSecureContext)await navigator.clipboard.writeText(text);else{const textarea=document.createElement('textarea');textarea.value=text;textarea.style.position='fixed';textarea.style.opacity='0';document.body.append(textarea);textarea.select();const copied=document.execCommand('copy');textarea.remove();if(!copied)throw new Error('Não foi possível copiar os elementos.')}button.textContent='Copiado!';setTimeout(()=>button.textContent=originalLabel,1600)}catch(error){button.textContent='Falhou';setTimeout(()=>button.textContent=originalLabel,1600)}}document.querySelectorAll('.copy-type').forEach(button=>button.addEventListener('click',()=>copyTypeElements(button)))</script></body></html>
