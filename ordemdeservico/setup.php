<?php

/**
 * Plugin Ordem de Serviço - GLPI 11 e 12
 * Gera ordens de serviço a partir de chamados, problemas e mudanças: documento congelado no momento da
 * geração, assinaturas em tela (técnico e solicitante) ou por link público, PDF, impressão, envio por
 * e-mail com registro, anexo ao item e uma lista com todas as OS (busca nativa do GLPI).
 */

define('PLUGIN_ORDEMDESERVICO_VERSION', '2.0.0');
define('PLUGIN_ORDEMDESERVICO_MIN_GLPI', '11.0.0');
define('PLUGIN_ORDEMDESERVICO_MAX_GLPI', '12.99.99');

function plugin_init_ordemdeservico(): void
{
    global $PLUGIN_HOOKS, $CFG_GLPI;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['ordemdeservico'] = true;

    // Página pública de assinatura (link enviado ao solicitante): sem login e sem sessão
    $publico = '#^/front/assinar\.php$#';
    if (class_exists('\Glpi\Http\Firewall')) {
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('ordemdeservico', $publico, \Glpi\Http\Firewall::STRATEGY_NO_CHECK);
    }
    if (class_exists('\Glpi\Http\SessionManager')) {
        \Glpi\Http\SessionManager::registerPluginStatelessPath('ordemdeservico', $publico);
    }

    $plugin = new Plugin();
    if (!$plugin->isActivated('ordemdeservico')) {
        return;
    }

    // Tabela com nome em português (o GLPI deduziria "..._ordems")
    $CFG_GLPI['glpitablesitemtype']['PluginOrdemdeservicoOrdem'] = 'glpi_plugin_ordemdeservico_documentos';
    $CFG_GLPI['glpiitemtypetables']['glpi_plugin_ordemdeservico_documentos'] = 'PluginOrdemdeservicoOrdem';

    // document_types: aba nativa "Documentos" e imagens coladas nas observações
    Plugin::registerClass('PluginOrdemdeservicoOrdem', ['document_types' => true]);
    Plugin::registerClass('PluginOrdemdeservicoItem', ['addtabon' => ['Ticket', 'Problem', 'Change']]);
    Plugin::registerClass('PluginOrdemdeservicoProfile', ['addtabon' => ['Profile']]);
    Plugin::registerClass('PluginOrdemdeservicoMenu');

    $PLUGIN_HOOKS['config_page']['ordemdeservico'] = 'front/config.form.php';
    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS['menu_toadd']['ordemdeservico'] = ['helpdesk' => 'PluginOrdemdeservicoMenu'];
    }
}

function plugin_version_ordemdeservico(): array
{
    return [
        'name'         => 'Ordem de Serviço',
        'version'      => PLUGIN_ORDEMDESERVICO_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_ORDEMDESERVICO_MIN_GLPI,
                'max' => PLUGIN_ORDEMDESERVICO_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_ordemdeservico_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_ORDEMDESERVICO_MIN_GLPI, '>=');
}

function plugin_ordemdeservico_check_config($verbose = false): bool
{
    return true;
}
