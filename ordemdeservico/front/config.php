<?php

/**
 * Plugin Ordem de Serviço - atalho para a configuração
 */

Session::checkLoginUser();
Html::redirect(PluginOrdemdeservicoConfig::url('config.form.php'));
