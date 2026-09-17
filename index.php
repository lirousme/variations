<?php
declare(strict_types=1);
require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/CombinationService.php';

const PAGE_SIZE = 50;
$db = Database::connect();
$base = rtrim(getenv('APP_BASE_PATH') ?: dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
function go(string $url): never { header('Location: ' . $url); exit; }
function id(string $key): int { return filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT) ?: 0; }
function page(string $key): int { return max(1, filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT) ?: 1); }
function queryPage(PDO $db, string $table, int $systemId, int $currentPage): array {
    $count = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE system_id = ?"); $count->execute([$systemId]); $total = (int) $count->fetchColumn();
    $lastPage = max(1, (int) ceil($total / PAGE_SIZE)); $currentPage = min($currentPage, $lastPage);
    $items = $db->prepare("SELECT * FROM {$table} WHERE system_id = ? ORDER BY id DESC LIMIT " . PAGE_SIZE . ' OFFSET ' . (($currentPage - 1) * PAGE_SIZE));
    $items->execute([$systemId]); return [$items->fetchAll(), $total, $currentPage, $lastPage];
}
function pagination(string $key, int $currentPage, int $lastPage, int $systemId): string {
    if ($lastPage < 2) return '';
    $href = static fn(int $number): string => '?system=' . $systemId . '&' . $key . '=' . $number;
    $previous = $currentPage > 1 ? '<a href="' . $href($currentPage - 1) . '">← Anterior</a>' : '<span>← Anterior</span>';
    $next = $currentPage < $lastPage ? '<a href="' . $href($currentPage + 1) . '">Próxima →</a>' : '<span>Próxima →</span>';
    return '<nav class="pagination">' . $previous . '<b>Página ' . $currentPage . ' de ' . $lastPage . '</b>' . $next . '</nav>';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? ''; $systemId = id('system_id');
    if ($action === 'system') { $db->prepare('INSERT INTO systems(name) VALUES(?)')->execute([trim($_POST['name'])]); $systemId = (int) $db->lastInsertId(); }
    if ($action === 'type') $db->prepare('INSERT INTO element_types(system_id,name) VALUES(?,?)')->execute([$systemId, trim($_POST['name'])]);
    if ($action === 'element') { $type = id('element_type_id'); $valid = $db->prepare('SELECT 1 FROM element_types WHERE id=? AND system_id=?'); $valid->execute([$type, $systemId]); if ($valid->fetchColumn()) $db->prepare('INSERT INTO elements(system_id,element_type_id,text_value) VALUES(?,?,?)')->execute([$systemId, $type, trim($_POST['text_value'])]); }
    if ($action === 'structure') { $slots = array_values(array_filter(array_map('intval', $_POST['slots'] ?? []))); $valid = $db->prepare('SELECT COUNT(*) FROM element_types WHERE system_id=? AND id IN (' . implode(',', array_fill(0, count($slots), '?')) . ')'); if ($slots && ($valid->execute([$systemId, ...$slots]) || true) && (int) $valid->fetchColumn() === count(array_unique($slots))) $db->prepare('INSERT INTO combination_structures(system_id,name,slots) VALUES(?,?,?)')->execute([$systemId, trim($_POST['name']), json_encode($slots)]); }
    if ($action === 'generate') { $new = (new CombinationService($db))->generate($systemId, id('structure_id')); go("{$base}/?system={$systemId}&created={$new}"); }
    go("{$base}/?system={$systemId}");
}
$requestedSystemId = filter_input(INPUT_GET, 'system', FILTER_VALIDATE_INT) ?: 0; $system = null;
if ($requestedSystemId) { $systemQuery = $db->prepare('SELECT * FROM systems WHERE id = ?'); $systemQuery->execute([$requestedSystemId]); $system = $systemQuery->fetch(); }
if (!$system) $system = $db->query('SELECT * FROM systems ORDER BY id LIMIT 1')->fetch();
$systemId = (int) ($system['id'] ?? 0); $systemPage = page('systems_page'); $systemCount = (int) $db->query('SELECT COUNT(*) FROM systems')->fetchColumn(); $systemLastPage = max(1, (int) ceil($systemCount / PAGE_SIZE)); $systemPage = min($systemPage, $systemLastPage);
$systems = $db->query('SELECT * FROM systems ORDER BY name, id LIMIT ' . PAGE_SIZE . ' OFFSET ' . (($systemPage - 1) * PAGE_SIZE))->fetchAll();
[$types, $typeTotal, $typePage, $typeLastPage] = $systemId ? queryPage($db, 'element_types', $systemId, page('types_page')) : [[], 0, 1, 1];
[$elements, $elementTotal, $elementPage, $elementLastPage] = $systemId ? queryPage($db, 'elements', $systemId, page('elements_page')) : [[], 0, 1, 1];
[$structures, $structureTotal, $structurePage, $structureLastPage] = $systemId ? queryPage($db, 'combination_structures', $systemId, page('structures_page')) : [[], 0, 1, 1];
[$combinations, $combinationTotal, $combinationPage, $combinationLastPage] = $systemId ? queryPage($db, 'generated_combinations', $systemId, page('combinations_page')) : [[], 0, 1, 1];
// Resolve labels only for the records displayed on this page. Loading every type
// would put millions of option nodes in the browser and defeat pagination.
$visibleTypeIds = array_column($elements, 'element_type_id');
foreach ($structures as $structure) foreach (json_decode($structure['slots'], true) ?: [] as $typeId) $visibleTypeIds[] = $typeId;
$visibleTypeIds = array_values(array_unique(array_map('intval', $visibleTypeIds)));
$typeNames = [];
if ($visibleTypeIds) {
    $labels = $db->prepare('SELECT id, name FROM element_types WHERE system_id = ? AND id IN (' . implode(',', array_fill(0, count($visibleTypeIds), '?')) . ')');
    $labels->execute([$systemId, ...$visibleTypeIds]);
    $typeNames = array_column($labels->fetchAll(), 'name', 'id');
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lexical Chunks</title><link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/assets/app.css"></head><body>
<aside><div class="brand">◈ <span>Lexical<br><b>Chunks</b></span></div><nav><a class="active" href="#visao">Visão geral</a><a href="#estruturas">Estruturas</a><a href="#resultados">Combinações</a></nav><form method="post" class="new-system"><input type="hidden" name="action" value="system"><input name="name" required placeholder="Novo sistema"><button>+ Criar sistema</button></form><div class="system-list"><span class="eyebrow">SISTEMAS</span><?php foreach ($systems as $listed): ?><a class="<?= $systemId === (int) $listed['id'] ? 'current' : '' ?>" href="?system=<?= $listed['id'] ?>"><?= htmlspecialchars($listed['name']) ?></a><?php endforeach; ?></div><?= pagination('systems_page', $systemPage, $systemLastPage, $systemId) ?></aside>
<main><?php if (!$systemId): ?><section class="empty"><p>Comece criando um sistema.</p></section><?php else: ?><header><div><span class="eyebrow">SISTEMA ATIVO</span><h1><?= htmlspecialchars($system['name']) ?></h1></div><a href="#estruturas" class="button">Criar estrutura</a></header><?php if (isset($_GET['created'])): ?><div class="notice"><?= intval($_GET['created']) ?> combinações novas geradas.</div><?php endif; ?><section id="visao" class="cards"><article><strong><?= $typeTotal ?></strong><span>Tipos</span></article><article><strong><?= $elementTotal ?></strong><span>Elementos</span></article><article><strong><?= $structureTotal ?></strong><span>Estruturas</span></article><article><strong><?= $combinationTotal ?></strong><span>Combinações</span></article></section>
<section class="grid"><article class="panel"><h2>Tipos de elemento</h2><form method="post"><input type="hidden" name="action" value="type"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input name="name" required placeholder="Ex.: Verbo"><button>Adicionar</button></form><ul><?php foreach ($types as $type): ?><li><small>ID <?= $type['id'] ?></small><i></i><?= htmlspecialchars($type['name']) ?></li><?php endforeach; ?></ul><?= pagination('types_page', $typePage, $typeLastPage, $systemId) ?></article><article class="panel"><h2>Elementos</h2><form method="post"><input type="hidden" name="action" value="element"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input name="element_type_id" type="number" min="1" required placeholder="ID do tipo"><input name="text_value" required placeholder="Texto do elemento"><button>Salvar</button></form><ul><?php foreach ($elements as $element): ?><li><small><?= htmlspecialchars($typeNames[$element['element_type_id']] ?? 'Tipo removido') ?></small><?= htmlspecialchars($element['text_value']) ?></li><?php endforeach; ?></ul><?= pagination('elements_page', $elementPage, $elementLastPage, $systemId) ?></article></section>
<section id="estruturas" class="panel"><h2>Estruturas ordenadas</h2><p>Informe os IDs dos tipos na sequência desejada. Cada posição usa somente elementos do tipo escolhido neste sistema.</p><form method="post" class="structure"><input type="hidden" name="action" value="structure"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input name="name" required placeholder="Nome da estrutura"><div id="slots"><input name="slots[]" type="number" min="1" required placeholder="ID do tipo"></div><button type="button" class="secondary" onclick="addSlot()">+ Posição</button><button>Salvar estrutura</button></form><div class="structures"><?php foreach ($structures as $structure): $slotLabels = array_map(fn($value) => $typeNames[$value] ?? 'Tipo removido', json_decode($structure['slots'], true)); ?><article><b><?= htmlspecialchars($structure['name']) ?></b><span><?= htmlspecialchars(implode(' → ', $slotLabels)) ?></span><form method="post"><input type="hidden" name="action" value="generate"><input type="hidden" name="system_id" value="<?= $systemId ?>"><input type="hidden" name="structure_id" value="<?= $structure['id'] ?>"><button>Gerar</button></form></article><?php endforeach; ?></div><?= pagination('structures_page', $structurePage, $structureLastPage, $systemId) ?></section>
<section id="resultados" class="panel"><h2>Combinações geradas</h2><div class="results"><?php foreach ($combinations as $combination): ?><code><?= htmlspecialchars($combination['value_text']) ?></code><?php endforeach; ?></div><?= pagination('combinations_page', $combinationPage, $combinationLastPage, $systemId) ?></section><?php endif; ?></main><template id="slot-template"><input name="slots[]" type="number" min="1" required placeholder="ID do tipo"></template><script>function addSlot(){document.querySelector('#slots').append(document.querySelector('#slot-template').content.cloneNode(true))}</script></body></html>
