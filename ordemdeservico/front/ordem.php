<?php

/**
 * Plugin Ordem de Serviço - lista de todas as ordens de serviço (busca nativa do GLPI)
 */

Session::checkLoginUser();
if (!PluginOrdemdeservicoOrdem::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

Html::header(PluginOrdemdeservicoOrdem::getTypeName(2), $_SERVER['PHP_SELF'] ?? '', 'helpdesk', 'PluginOrdemdeservicoMenu');
echo PluginOrdemdeservicoConfig::assets();
Search::show('PluginOrdemdeservicoOrdem');
Html::footer();
