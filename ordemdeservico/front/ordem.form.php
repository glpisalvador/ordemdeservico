<?php

/**
 * Plugin Ordem de Serviço - formulário da OS (processamento nativo: add, update, delete, restore, purge)
 */

Session::checkLoginUser();

$os = new PluginOrdemdeservicoOrdem();
$lista = PluginOrdemdeservicoConfig::url('ordem.php');

if (isset($_POST['add'])) {
    // A entidade da OS é a do item de origem (o direito é conferido nela)
    $origem = PluginOrdemdeservicoDocumento::item((string) ($_POST['itemtype'] ?? ''), (int) ($_POST['items_id'] ?? 0));
    $_POST['entities_id'] = $origem ? (int) $origem->fields['entities_id'] : (int) ($_SESSION['glpiactive_entity'] ?? 0);
    $os->check(-1, CREATE, $_POST);
    $id = $os->add($_POST);
    if ($id) {
        Session::addMessageAfterRedirect('Ordem de serviço ' . $os->fields['numero'] . ' gerada.', false, INFO);
        Html::redirect($os->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $os->check((int) $_POST['id'], UPDATE);
    $os->update($_POST);
    Html::back();
} elseif (isset($_POST['delete'])) {
    $os->check((int) $_POST['id'], DELETE);
    $os->delete($_POST);
    Html::redirect($lista);
} elseif (isset($_POST['restore'])) {
    $os->check((int) $_POST['id'], DELETE);
    $os->restore($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $os->check((int) $_POST['id'], PURGE);
    $os->delete($_POST, true);
    Html::redirect($lista);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    // OS nascem de um chamado, problema ou mudança: sem id, vai para a lista
    Html::redirect($lista);
}
$os->check($id, READ);

Html::header(PluginOrdemdeservicoOrdem::getTypeName(2), $_SERVER['PHP_SELF'] ?? '', 'helpdesk', 'PluginOrdemdeservicoMenu');
$os->display(['id' => $id]);
Html::footer();
