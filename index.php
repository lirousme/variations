<?php
declare(strict_types=1);

require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/CombinationService.php';

const PAGE_SIZE = 10;
const PAGES = ['overview', 'types', 'elements', 'structures', 'combinations'];

$db = Database::connect();
$base = rtrim(getenv('APP_BASE_PATH') ?: dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

function go(string $url): never { header('Location: ' . $url); exit; }
function id(string $key): int { return filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT) ?: 0; }
function pageNumber(): int { return max(1, filter_input(INPUT_GET, 'page_number', FILTER_VALIDATE_INT) ?: 1); }
function isDuplicateKey(PDOException $exception): bool { return ($exception->errorInfo[1] ?? null) === 1062; }
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
    if ($action === 'type') {
        $spacing = ($_POST['spacing'] ?? '') === 'without_space' ? 'without_space' : 'with_space';
        $letterCase = ($_POST['letter_case'] ?? '') === 'initial_always_uppercase' ? 'initial_always_uppercase' : 'mixed_case';
        $db->prepare('INSERT INTO element_types(system_id,name,spacing,letter_case) VALUES(?,?,?,?)')->execute([$systemId, trim($_POST['name']), $spacing, $letterCase]);
    }
    if ($action === 'element') {
        $type = id('element_type_id');
        $valid = $db->prepare('SELECT 1 FROM element_types WHERE id = ? AND system_id = ?');
        $valid->execute([$type, $systemId]);
        if ($valid->fetchColumn()) {
            try {
                $db->prepare('INSERT INTO elements(system_id,element_type_id,text_value) VALUES(?,?,?)')->execute([$systemId, $type, trim($_POST['text_value'])]);
            } catch (PDOException $exception) {
                if (isDuplicateKey($exception)) go(url('elements', $systemId, ['duplicate_element' => 1]));
                throw $exception;
            }
        }
    }
    if ($action === 'structure') {
        $slots = array_values(array_filter(array_map('intval', $_POST['slots'] ?? [])));
        if ($slots) {
            $valid = $db->prepare('SELECT COUNT(*) FROM element_types WHERE system_id = ? AND id IN (' . implode(',', array_fill(0, count($slots), '?')) . ')');
            $valid->execute([$systemId, ...$slots]);
            if ((int) $valid->fetchColumn() === count(array_unique($slots))) $db->prepare('INSERT INTO combination_structures(system_id,name,slots) VALUES(?,?,?)')->execute([$systemId, trim($_POST['name']), json_encode($slots)]);
        }
    }
    if ($action === 'generate') {
        $new = (new CombinationService($db))->generate($systemId, id('structure_id'));
        go(url('combinations', $systemId, ['created' => $new]));
    }
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

$visibleTypeIds = $currentPage === 'elements' ? array_column($items, 'element_type_id') : [];
if ($currentPage === 'structures') foreach ($items as $structure) foreach (json_decode($structure['slots'], true) ?: [] as $typeId) $visibleTypeIds[] = $typeId;
$visibleTypeIds = array_values(array_unique(array_map('intval', $visibleTypeIds)));
$typeNames = [];
if ($visibleTypeIds) {
    $labels = $db->prepare('SELECT id, name FROM element_types WHERE system_id = ? AND id IN (' . implode(',', array_fill(0, count($visibleTypeIds), '?')) . ')');
    $labels->execute([$systemId, ...$visibleTypeIds]);
    $typeNames = array_column($labels->fetchAll(), 'name', 'id');
}
$availableTypes = [];
if ($systemId && in_array($currentPage, ['elements', 'structures'], true)) {
    $availableTypesQuery = $db->prepare('SELECT id, name FROM element_types WHERE system_id = ? ORDER BY name, id');
    $availableTypesQuery->execute([$systemId]);
    $availableTypes = $availableTypesQuery->fetchAll();
}
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lexical Chunks</title><link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/assets/app.css"></head><body>
<aside><div class="brand">◈ <span>Lexical<br><b>Chunks</b></span></div><nav>
<?php foreach (['overview' => 'Visão geral', 'types' => 'Tipos', 'elements' => 'Elementos', 'structures' => 'Estruturas', 'combinations' => 'Combinações'] as $page => $label): ?><a class="<?= $currentPage === $page ? 'active' : '' ?>" href="<?= htmlspecialchars(url($page, $systemId)) ?>"><?= $label ?></a><?php endforeach; ?>
</nav><form method="post" class="new-system"><input type="hidden" name="action" value="system"><input name="name" required placeholder="Novo sistema"><button>+ Criar sistema</button></form><div class="system-list"><span class="eyebrow">SISTEMAS</span><?php foreach ($systems as $listed): ?><a class="<?= $systemId === (int) $listed['id'] ? 'current' : '' ?>" href="<?= htmlspecialchars(url($currentPage, (int) $listed['id'])) ?>"><?= htmlspecialchars($listed['name']) ?></a><?php endforeach; ?></div><?= pagination($systemsPage, $systemsLastPage, $currentPage, $systemId, 'systems_page') ?></aside>
<main><?php if (!$systemId): ?><section class="empty"><p>Comece criando um sistema.</p></section><?php else: ?><header><div><span class="eyebrow">SISTEMA ATIVO</span><h1><?= htmlspecialchars($system['name']) ?></h1></div><a href="<?= htmlspecialchars(url('structures', $systemId)) ?>" class="button">Criar estrutura</a></header>
<?php if (isset($_GET['created'])): ?><div class="notice"><?= intval($_GET['created']) ?> combinações novas geradas.</div><?php endif; ?>
<?php if (isset($_GET['duplicate_element'])): ?><div class="notice">Já existe um elemento com este tipo e texto neste sistema.</div><?php endif; ?>
<?php if ($currentPage === 'overview'): ?><section class="cards"><article><strong><?= $totals['types'] ?></strong><span>Tipos</span></article><article><strong><?= $totals['elements'] ?></strong><span>Elementos</span></article><article><strong><?= $totals['structures'] ?></strong><span>Estruturas</span></article><article><strong><?= $totals['combinations'] ?></strong><span>Combinações</span></article></section><section class="panel overview"><h2>Dados organizados em páginas</h2><p>Use o menu para cadastrar e consultar cada categoria separadamente. Cada listagem mostra no máximo 10 registros por página.</p></section>
<?php elseif ($currentPage === 'types'): ?><section class="panel"><h2>Tipos de elemento</h2><form method="post"><input type="hidden" name="action" value="type"><input type="hidden" name="return_page" value="types"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input name="name" required placeholder="Ex.: Verbo"><select name="spacing" aria-label="Espaçamento do tipo"><option value="with_space" selected>COM espaço</option><option value="without_space">SEM espaço</option></select><select name="letter_case" aria-label="Capitalização do tipo"><option value="mixed_case" selected>Maiúscula e minúscula</option><option value="initial_always_uppercase">Inicial sempre maiúscula</option></select><button>Adicionar</button></form><ul><?php foreach ($items as $type): ?><li><small>ID <?= $type['id'] ?> · <?= ($type['spacing'] ?? 'with_space') === 'without_space' ? 'SEM espaço' : 'COM espaço' ?> · <?= ($type['letter_case'] ?? 'mixed_case') === 'initial_always_uppercase' ? 'Inicial sempre maiúscula' : 'Maiúscula e minúscula' ?></small><i></i><?= htmlspecialchars($type['name']) ?></li><?php endforeach; ?></ul><?= pagination($listPage, $listLastPage, 'types', $systemId) ?></section>
<?php elseif ($currentPage === 'elements'): ?><section class="panel"><h2>Elementos</h2><p>Selecione um tipo pertencente a este sistema.</p><form method="post"><input type="hidden" name="action" value="element"><input type="hidden" name="return_page" value="elements"><input type="hidden" name="system_id" value="<?= $systemId ?>"><select name="element_type_id" required<?= $availableTypes ? '' : ' disabled' ?>><option value="" selected disabled><?= $availableTypes ? 'Selecione o tipo' : 'Cadastre um tipo primeiro' ?></option><?php foreach ($availableTypes as $type): ?><option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option><?php endforeach; ?></select><input name="text_value" required placeholder="Texto do elemento"><button<?= $availableTypes ? '' : ' disabled' ?>>Salvar</button></form><ul><?php foreach ($items as $element): ?><li><small><?= htmlspecialchars($typeNames[$element['element_type_id']] ?? 'Tipo removido') ?></small><?= htmlspecialchars($element['text_value']) ?></li><?php endforeach; ?></ul><?= pagination($listPage, $listLastPage, 'elements', $systemId) ?></section>
<?php elseif ($currentPage === 'structures'): ?><section class="panel"><h2>Estruturas ordenadas</h2><p>Selecione os tipos na sequência desejada. Cada posição usa somente elementos do tipo escolhido neste sistema.</p><form method="post" class="structure"><input type="hidden" name="action" value="structure"><input type="hidden" name="return_page" value="structures"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input name="name" required placeholder="Nome da estrutura"><div id="slots"><select name="slots[]" required<?= $availableTypes ? '' : ' disabled' ?>><option value="" selected disabled><?= $availableTypes ? 'Selecione o tipo' : 'Cadastre um tipo primeiro' ?></option><?php foreach ($availableTypes as $type): ?><option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option><?php endforeach; ?></select></div><button type="button" class="secondary" onclick="addSlot()"<?= $availableTypes ? '' : ' disabled' ?>>+ Posição</button><button<?= $availableTypes ? '' : ' disabled' ?>>Salvar estrutura</button></form><div class="structures"><?php foreach ($items as $structure): $slotLabels = array_map(fn($value) => $typeNames[$value] ?? 'Tipo removido', json_decode($structure['slots'], true) ?: []); ?><article><b><?= htmlspecialchars($structure['name']) ?></b><span><?= htmlspecialchars(implode(' → ', $slotLabels)) ?></span><form method="post"><input type="hidden" name="action" value="generate"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input type="hidden" name="structure_id" value="<?= $structure['id'] ?>"><button>Gerar</button></form></article><?php endforeach; ?></div><?= pagination($listPage, $listLastPage, 'structures', $systemId) ?></section>
<?php else: ?><section class="panel"><h2>Combinações geradas</h2><div class="results"><?php foreach ($items as $combination): ?><code><?= htmlspecialchars($combination['value_text']) ?></code><?php endforeach; ?></div><?= pagination($listPage, $listLastPage, 'combinations', $systemId) ?></section><?php endif; ?>
<?php endif; ?></main><template id="slot-template"><select name="slots[]" required><option value="" selected disabled>Selecione o tipo</option><?php foreach ($availableTypes as $type): ?><option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option><?php endforeach; ?></select></template><script>function addSlot(){document.querySelector('#slots').append(document.querySelector('#slot-template').content.cloneNode(true))}</script></body></html>
