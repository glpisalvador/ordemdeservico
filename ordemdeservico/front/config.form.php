<?php

/**
 * Plugin Ordem de Serviço - configuração (marketplace e menu). Cada aba é um formulário com POST para
 * esta mesma página; o fluxo segue para o Html::header depois de salvar.
 */

Session::checkLoginUser();

global $DB, $CFG_GLPI;
$C =PluginOrdemdeservicoConfig::class;
$e = [$C, 'e'];

if (!$C::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$abas = [
    'documento'  => ['ti ti-file-text', 'Documento'],
    'assinatura' => ['ti ti-writing-sign', 'Assinatura'],
    'email'      => ['ti ti-mail', 'E-mail'],
    'acesso'     => ['ti ti-shield-lock', 'Acesso'],
];
$aba = (string) ($_POST['aba'] ?? $_GET['aba'] ?? 'documento');
if (!isset($abas[$aba])) {
    $aba = 'documento';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $texto = fn(string $campo, int $max) => mb_substr(trim(strip_tags((string) ($_POST[$campo] ?? ''))), 0, $max);
    switch ((string) ($_POST['save_action'] ?? '')) {
        case 'salvar_documento':
            $C::setConfig('empresa', $texto('empresa', 255));
            $C::setConfig('cabecalho', (string) ($_POST['cabecalho'] ?? ''));
            $C::setConfig('rodape', (string) ($_POST['rodape'] ?? ''));
            $C::setConfig('prefixo', (string) preg_replace('/[^A-Za-z0-9]+/', '', $texto('prefixo', 10)) ?: 'OS');
            $C::setConfig('modelo_titulo', $texto('modelo_titulo', 255) ?: $C::padroes()['modelo_titulo']);
            $C::setArrayConfig('itens', array_values(array_intersect(array_keys($C::ITENS), array_map('strval', (array) ($_POST['itens'] ?? [])))));
            $C::setArrayConfig('secoes', array_values(array_intersect(array_keys($C::SECOES), array_map('strval', (array) ($_POST['secoes'] ?? [])))));
            $C::setConfig('privados', empty($_POST['privados']) ? '0' : '1');
            $C::setConfig('termo', (string) ($_POST['termo'] ?? ''));
            $erroLogo = '';
            if (!empty($_POST['remover_logo'])) {
                $C::removerLogo();
            } elseif (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $erroLogo = $C::salvarLogo($_FILES['logo']);
            }
            if ($erroLogo !== '') {
                Session::addMessageAfterRedirect($erroLogo, false, ERROR);
            }
            Session::addMessageAfterRedirect('Configuração do documento salva.', false, INFO);
            break;

        case 'salvar_assinatura':
            $C::setConfig('assinatura_remota', empty($_POST['assinatura_remota']) ? '0' : '1');
            $C::setConfig('validade_dias', (string) max(1, min(90, (int) ($_POST['validade_dias'] ?? 7))));
            Session::addMessageAfterRedirect('Configuração de assinatura salva.', false, INFO);
            break;

        case 'salvar_email':
            $remetente = $texto('remetente_email', 255);
            if ($remetente !== '' && !filter_var($remetente, FILTER_VALIDATE_EMAIL)) {
                Session::addMessageAfterRedirect('E-mail do remetente inválido: não foi salvo.', false, ERROR);
            } else {
                $C::setConfig('remetente_email', $remetente);
            }
            $C::setConfig('remetente_nome', $texto('remetente_nome', 255));
            $C::setConfig('email_assunto', $texto('email_assunto', 255) ?: $C::padroes()['email_assunto']);
            $C::setConfig('email_mensagem', (string) ($_POST['email_mensagem'] ?? ''));
            Session::addMessageAfterRedirect('Configuração de e-mail salva.', false, INFO);
            break;
    }
}

Html::header('Ordem de Serviço', $_SERVER['PHP_SELF'] ?? '', 'helpdesk', 'PluginOrdemdeservicoMenu');
echo $C::assets();

$card = fn(string $icone, string $titulo, string $corpo) => '<div class="card ordemdeservico-card"><div class="card-header"><h5><i class="' . $icone . '"></i> ' . $e($titulo) . '</h5></div><div class="card-body">' . $corpo . '</div></div>';
$campo = fn(string $rotulo, string $controle, string $dica = '', string $para = '') => '<div class="ordemdeservico-campo"><label' . ($para !== '' ? ' for="' . $para . '"' : '') . '>' . $e($rotulo) . '</label>' . $controle . ($dica !== '' ? '<small>' . $dica . '</small>' : '') . '</div>';
$explicacao = fn(string $t) => '<p class="ordemdeservico-ajuda"><i class="ti ti-info-circle"></i> ' . $t . '</p>';
$inicio = fn(string $acao, string $extra = '') => '<form method="post" action="' . $e($C::url('config.form.php')) . '"' . $extra . '><input type="hidden" name="save_action" value="' . $acao . '"><input type="hidden" name="aba" value="' . $aba . '" data-ordemdeservico-aba-atual>';
$fim = '<div class="ordemdeservico-rodape-form"><span></span><button type="submit" class="btn btn-sm ordemdeservico-btn-principal"><i class="ti ti-device-floppy"></i><span>Salvar</span></button></div>' . Html::closeForm(false);
$rico = fn(string $nome, string $valor, int $linhas = 5): string => (string) Html::textarea([
    'name'            => $nome,
    'value'           => $valor,
    'enable_richtext' => true,
    'cols'            => 100,
    'rows'            => $linhas,
    'display'         => false,
]);
$variaveis = '<div class="ordemdeservico-variaveis">';
foreach ($C::VARIAVEIS as $v => $d) {
    $variaveis .= '<span><code>' . $e($v) . '</code> ' . $e($d) . '</span>';
}
$variaveis .= '</div>';

echo '<div class="ordemdeservico-pagina ordemdeservico-config" data-ordemdeservico-config>';
echo '<ul class="nav nav-tabs ordemdeservico-abas">';
foreach ($abas as $k => [$icone, $rotulo]) {
    echo '<li class="nav-item"><a href="#" class="nav-link' . ($k === $aba ? ' active' : '') . '" data-aba="' . $k . '"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</a></li>';
}
echo '</ul>';

// ------------------------------------------------------------------ Documento
echo '<div data-aba-painel="documento"' . ($aba === 'documento' ? '' : ' hidden') . '>';
echo $inicio('salvar_documento', ' enctype="multipart/form-data"');
$logo = $C::logoDataUri();
$itens = '';
foreach ($C::ITENS as $t => $rotulo) {
    $itens .= '<label class="ordemdeservico-opcao"><input type="checkbox" class="ordemdeservico-check" name="itens[]" value="' . $e($t) . '"' . (in_array($t, $C::itensHabilitados(), true) ? ' checked' : '') . '> ' . $e($rotulo) . '</label>';
}
$secoes = '';
foreach ($C::SECOES as $s => $rotulo) {
    $secoes .= '<label class="ordemdeservico-opcao"><input type="checkbox" class="ordemdeservico-check" name="secoes[]" value="' . $e($s) . '"' . (in_array($s, $C::secoesPadrao(), true) ? ' checked' : '') . '> ' . $e($rotulo) . '</label>';
}
echo '<div class="ordemdeservico-grade-config">';
echo $card('ti ti-building', 'Identificação', $explicacao('Aparece no cabeçalho de toda ordem de serviço, no PDF, no e-mail e na página de assinatura.')
    . $campo('Nome da empresa', '<input type="text" id="ordemdeservico-cfg-empresa" class="form-control form-control-sm" name="empresa" maxlength="255" value="' . $e($C::getConfig('empresa')) . '">', '', 'ordemdeservico-cfg-empresa')
    . $campo('Logotipo', ($logo !== '' ? '<div class="ordemdeservico-logo-previa"><img src="' . $logo . '" alt="Logotipo"><label class="ordemdeservico-opcao"><input type="checkbox" class="ordemdeservico-check" name="remover_logo" value="1"> Remover</label></div>' : '')
        . '<input type="file" class="form-control form-control-sm" name="logo" accept="image/png,image/jpeg,image/gif,image/webp">', 'PNG, JPG, GIF ou WEBP até 1 MB. Fica embutido no documento.')
    . $campo('Linhas do cabeçalho', $rico('cabecalho', (string) $C::getConfig('cabecalho'), 3), 'Endereço, CNPJ, telefone, site...')
    . $campo('Rodapé', $rico('rodape', (string) $C::getConfig('rodape'), 3)));
echo $card('ti ti-adjustments', 'Geração', $campo('Gerar OS a partir de', '<div class="ordemdeservico-opcoes">' . $itens . '<input type="hidden" name="itens[]" value=""></div>')
    . $campo('Prefixo do número', '<input type="text" id="ordemdeservico-cfg-prefixo" class="form-control form-control-sm ordemdeservico-curto" name="prefixo" maxlength="10" value="' . $e($C::getConfig('prefixo')) . '">', 'Letras e números. Exemplo: ' . $e($C::getConfig('prefixo')) . '-' . date('Y') . '-00001', 'ordemdeservico-cfg-prefixo')
    . $campo('Modelo do título', '<input type="text" id="ordemdeservico-cfg-titulo" class="form-control form-control-sm" name="modelo_titulo" maxlength="255" value="' . $e($C::getConfig('modelo_titulo')) . '">', 'Aceita {item}, {item_id}, {item_titulo}, {entidade}, {empresa} e {numero}.', 'ordemdeservico-cfg-titulo')
    . $campo('Seções marcadas por padrão', '<div class="ordemdeservico-opcoes">' . $secoes . '<input type="hidden" name="secoes[]" value=""></div>', 'Quem gera a OS pode mudar as seções na hora.')
    . '<label class="ordemdeservico-opcao"><input type="checkbox" class="ordemdeservico-check" name="privados" value="1"' . ((string) $C::getConfig('privados') === '1' ? ' checked' : '') . '> Marcar "incluir itens privados" por padrão</label>');
echo '</div>';
echo $card('ti ti-file-certificate', 'Termo de aceite', $explicacao('Texto exibido acima das assinaturas.') . $rico('termo', (string) $C::getConfig('termo'), 4));
echo $fim . '</div>';

// ------------------------------------------------------------------ Assinatura
echo '<div data-aba-painel="assinatura"' . ($aba === 'assinatura' ? '' : ' hidden') . '>';
echo $inicio('salvar_assinatura');
echo $card('ti ti-link', 'Assinatura pelo link', $explicacao('O solicitante recebe um link (por e-mail ou copiado na OS), confere o documento e assina pelo celular ou computador, sem login. A página fica em <code>' . $e($C::urlAbsoluta('assinar.php')) . '</code>.')
    . '<div class="form-check form-switch ordemdeservico-switch"><input class="form-check-input" type="checkbox" role="switch" id="ordemdeservico-cfg-remota" name="assinatura_remota" value="1"' . ((string) $C::getConfig('assinatura_remota') === '1' ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="ordemdeservico-cfg-remota">Permitir assinatura pelo link</label></div>'
    . $campo('Validade do link (dias)', '<input type="number" id="ordemdeservico-cfg-validade" class="form-control form-control-sm ordemdeservico-curto" name="validade_dias" min="1" max="90" value="' . (int) $C::getConfig('validade_dias') . '">', 'Depois disso, gere um novo link na OS.', 'ordemdeservico-cfg-validade')
    . $explicacao('O endereço usado nos links vem da URL da aplicação em Configurar > Geral (hoje: <code>' . $e($CFG_GLPI['url_base'] ?? '') . '</code>).'));
echo $fim . '</div>';

// ------------------------------------------------------------------ E-mail
echo '<div data-aba-painel="email"' . ($aba === 'email' ? '' : ' hidden') . '>';
echo $inicio('salvar_email');
$remetente = PluginOrdemdeservicoEnvio::remetente();
echo '<div class="ordemdeservico-grade-config">';
echo $card('ti ti-user', 'Remetente', ($remetente === null ? '<div class="ordemdeservico-alerta ordemdeservico-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>Nem o plugin nem o GLPI têm remetente configurado: os envios vão falhar.</span></div>'
        : $explicacao('Em uso: <strong>' . $e($remetente[1] !== '' ? $remetente[1] . ' <' . $remetente[0] . '>' : $remetente[0]) . '</strong>'))
    . $campo('E-mail do remetente', '<input type="email" id="ordemdeservico-cfg-remetente" class="form-control form-control-sm" name="remetente_email" maxlength="255" value="' . $e($C::getConfig('remetente_email')) . '" placeholder="Vazio: o das notificações do GLPI">', '', 'ordemdeservico-cfg-remetente')
    . $campo('Nome do remetente', '<input type="text" id="ordemdeservico-cfg-remetente-nome" class="form-control form-control-sm" name="remetente_nome" maxlength="255" value="' . $e($C::getConfig('remetente_nome')) . '" placeholder="Vazio: o das notificações do GLPI">', '', 'ordemdeservico-cfg-remetente-nome'));
echo $card('ti ti-braces', 'Variáveis', $explicacao('Podem ser usadas no assunto e na mensagem.') . $variaveis);
echo '</div>';
echo $card('ti ti-mail', 'Modelo do e-mail', $campo('Assunto', '<input type="text" id="ordemdeservico-cfg-assunto" class="form-control form-control-sm" name="email_assunto" maxlength="255" value="' . $e($C::getConfig('email_assunto')) . '">', '', 'ordemdeservico-cfg-assunto')
    . $campo('Mensagem', $rico('email_mensagem', (string) $C::getConfig('email_mensagem'), 8), 'Quem envia pode editar o texto antes de mandar.'));
echo $fim . '</div>';

// ------------------------------------------------------------------ Acesso
echo '<div data-aba-painel="acesso"' . ($aba === 'acesso' ? '' : ' hidden') . '>';
$direitos = [];
foreach ($DB->request(['SELECT' => ['profiles_id', 'rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $C::DIREITO]]) as $r) {
    $direitos[(int) $r['profiles_id']] = (int) $r['rights'];
}
$colunas = [READ => 'Ler', CREATE => 'Gerar', UPDATE => 'Editar e assinar', DELETE => 'Lixeira', PURGE => 'Excluir'];
$linhas = '';
foreach ($DB->request(['SELECT' => ['id', 'name', 'interface'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']) as $p) {
    if ($p['interface'] === 'helpdesk') {
        continue;
    }
    $v = $direitos[(int) $p['id']] ?? 0;
    $linhas .= '<tr data-linha data-search="' . $e(mb_strtolower($p['name'])) . '"><td><a href="' . $e(Profile::getFormURLWithID((int) $p['id'])) . '&forcetab=PluginOrdemdeservicoProfile$1">' . $e($p['name']) . '</a></td>';
    foreach (array_keys($colunas) as $bit) {
        $linhas .= '<td class="text-center">' . (($v & $bit) === $bit ? '<i class="ti ti-check text-success"></i>' : '<span class="ordemdeservico-pequeno">—</span>') . '</td>';
    }
    $linhas .= '</tr>';
}
$cab = '';
foreach ($colunas as $rotulo) {
    $cab .= '<th class="text-center">' . $e($rotulo) . '</th>';
}
echo $card('ti ti-shield-lock', 'Direitos por perfil', $explicacao('Os direitos são nativos do GLPI: edite na aba <strong>Ordens de serviço</strong> de cada perfil (clique no nome). Perfis da interface simplificada não usam o plugin; o solicitante assina pelo link.')
    . '<input type="text" class="form-control form-control-sm ordemdeservico-busca" placeholder="Pesquisar perfil..." data-ordemdeservico-busca-tabela>'
    . '<div class="table-responsive ordemdeservico-tabela-caixa"><table class="table table-striped table-hover table-sm ordemdeservico-tabela mb-0"><thead class="sticky-top"><tr><th>Perfil</th>' . $cab . '</tr></thead><tbody>' . $linhas . '</tbody></table></div>');
echo '</div>';

echo '</div>';
Html::footer();
