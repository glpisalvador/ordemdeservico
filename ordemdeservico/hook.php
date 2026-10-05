<?php

/**
 * Plugin Ordem de Serviço - instalação e desinstalação
 */

function plugin_ordemdeservico_install(): bool
{
    global $DB;

    require_once __DIR__ . '/inc/config.class.php';
    $opcoes = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // Configurações chave/valor (a 1.x já usava esta tabela, só com allowed_profiles)
    $perfisAntigos = [];
    if ($DB->tableExists('glpi_plugin_ordemdeservico_configs')) {
        foreach ($DB->request(['FROM' => 'glpi_plugin_ordemdeservico_configs', 'WHERE' => ['name' => 'allowed_profiles']]) as $r) {
            $perfisAntigos = array_values(array_filter(array_map('intval', json_decode((string) $r['value'], true) ?: [])));
        }
    }
    if (!$DB->tableExists('glpi_plugin_ordemdeservico_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_ordemdeservico_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` text NULL,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $opcoes");
    }
    foreach (PluginOrdemdeservicoConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => 'glpi_plugin_ordemdeservico_configs', 'WHERE' => ['name' => $nome], 'LIMIT' => 1])) === 0) {
            $DB->insert('glpi_plugin_ordemdeservico_configs', ['name' => $nome, 'value' => is_array($valor) ? json_encode($valor) : (string) $valor]);
        }
    }

    // Ordens de serviço geradas (documento congelado + assinaturas + link público)
    if (!$DB->tableExists('glpi_plugin_ordemdeservico_documentos')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_ordemdeservico_documentos` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `numero` varchar(40) NULL DEFAULT NULL,
            `name` varchar(255) NOT NULL DEFAULT '',
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `is_recursive` tinyint(1) NOT NULL DEFAULT 0,
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` int unsigned NOT NULL DEFAULT 0,
            `status` varchar(20) NOT NULL DEFAULT 'emitida',
            `secoes` text NULL,
            `privados` tinyint(1) NOT NULL DEFAULT 0,
            `conteudo` longtext NULL,
            `observacoes` longtext NULL,
            `solicitante_nome` varchar(255) NOT NULL DEFAULT '',
            `solicitante_email` varchar(255) NOT NULL DEFAULT '',
            `tecnico_nome` varchar(255) NOT NULL DEFAULT '',
            `assinatura_solicitante_nome` varchar(255) NOT NULL DEFAULT '',
            `assinatura_solicitante_data` timestamp NULL DEFAULT NULL,
            `assinatura_solicitante_origem` varchar(20) NOT NULL DEFAULT '',
            `assinatura_tecnico_nome` varchar(255) NOT NULL DEFAULT '',
            `assinatura_tecnico_data` timestamp NULL DEFAULT NULL,
            `token` varchar(64) NULL DEFAULT NULL,
            `token_validade` timestamp NULL DEFAULT NULL,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `numero` (`numero`),
            UNIQUE KEY `token` (`token`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `entities_id` (`entities_id`),
            KEY `status` (`status`),
            KEY `users_id` (`users_id`),
            KEY `is_deleted` (`is_deleted`),
            KEY `date_creation` (`date_creation`)
        ) $opcoes");
    }

    // Envios por e-mail de cada OS
    if (!$DB->tableExists('glpi_plugin_ordemdeservico_envios')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_ordemdeservico_envios` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_ordemdeservico_documentos_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `destinatarios` text NULL,
            `assunto` varchar(255) NOT NULL DEFAULT '',
            `com_pdf` tinyint(1) NOT NULL DEFAULT 0,
            `com_link` tinyint(1) NOT NULL DEFAULT 0,
            `sucesso` tinyint(1) NOT NULL DEFAULT 0,
            `erro` text NULL,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `documento` (`plugin_ordemdeservico_documentos_id`),
            KEY `date_creation` (`date_creation`)
        ) $opcoes");
    }

    // A tabela da 1.x (glpi_plugin_ordemdeservico_ordens) fica intacta: regra do projeto, nada é apagado

    // Direito nativo: tudo para quem administra a configuração; ler, gerar e assinar para técnicos
    $direito = PluginOrdemdeservicoConfig::DIREITO;
    if (count($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $direito], 'LIMIT' => 1])) === 0) {
        ProfileRight::addProfileRights([$direito]);
        $admins = [];
        foreach ($DB->request(['SELECT' => ['profiles_id', 'rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $r) {
            if (((int) $r['rights'] & UPDATE) === UPDATE) {
                $admins[] = (int) $r['profiles_id'];
            }
        }
        $tecnicos = [];
        foreach ($DB->request([
            'SELECT'     => ['pr.profiles_id', 'pr.rights'],
            'FROM'       => 'glpi_profilerights AS pr',
            'INNER JOIN' => ['glpi_profiles AS p' => ['ON' => ['pr' => 'profiles_id', 'p' => 'id']]],
            'WHERE'      => ['pr.name' => 'ticket', 'p.interface' => 'central'],
        ]) as $r) {
            if (((int) $r['rights'] & UPDATE) === UPDATE && !in_array((int) $r['profiles_id'], $admins, true)) {
                $tecnicos[] = (int) $r['profiles_id'];
            }
        }
        // Perfis liberados na 1.x continuam com acesso
        $tecnicos = array_values(array_unique(array_merge($tecnicos, array_diff($perfisAntigos, $admins))));
        if ($admins) {
            $DB->update('glpi_profilerights', ['rights' => READ | CREATE | UPDATE | DELETE | PURGE], ['name' => $direito, 'profiles_id' => $admins]);
        }
        if ($tecnicos) {
            $DB->update('glpi_profilerights', ['rights' => READ | CREATE | UPDATE], ['name' => $direito, 'profiles_id' => $tecnicos]);
        }
        // Sessão de quem está instalando passa a enxergar o direito sem novo login
        if (isset($_SESSION['glpiactiveprofile']['id'])) {
            foreach ($DB->request(['SELECT' => ['rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $direito, 'profiles_id' => (int) $_SESSION['glpiactiveprofile']['id']]]) as $r) {
                $_SESSION['glpiactiveprofile'][$direito] = (int) $r['rights'];
            }
        }
    }

    // Colunas padrão da lista de OS (busca nativa): título, status, origem, solicitante, técnico, emissão, entidade
    if (countElementsInTable('glpi_displaypreferences', ['itemtype' => 'PluginOrdemdeservicoOrdem', 'users_id' => 0]) === 0) {
        foreach ([3, 4, 6, 7, 9, 121, 80] as $rank => $num) {
            $DB->insert('glpi_displaypreferences', ['itemtype' => 'PluginOrdemdeservicoOrdem', 'num' => $num, 'rank' => $rank + 1, 'users_id' => 0, 'interface' => 'central']);
        }
    }

    PluginOrdemdeservicoConfig::pasta('assinaturas');
    $protecao = GLPI_PLUGIN_DOC_DIR . '/ordemdeservico/.htaccess';
    if (!is_file($protecao)) {
        @file_put_contents($protecao, "Order Deny,Allow\nDeny from all\n");
    }

    return true;
}

function plugin_ordemdeservico_uninstall(): bool
{
    // Regra do projeto: tabelas, direitos e arquivos ficam (reinstalar recupera todas as OS)
    return true;
}
