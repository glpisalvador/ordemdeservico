<?php

/**
 * Plugin Ordem de Serviço - página pública de assinatura do solicitante (sem login, sem sessão).
 * ?t=TOKEN mostra a OS; o POST (formulário simples) grava a assinatura enquanto o link for válido.
 */

// Carregado pelo GLPI 11/12 sem autenticação (Firewall NO_CHECK + caminho stateless no setup.php)

global $CFG_GLPI;
$C = PluginOrdemdeservicoConfig::class;
$e = [$C, 'e'];

// Sem sessão (caminho stateless)
if (!isset($_SESSION) || !is_array($_SESSION)) {
    $_SESSION = [];
}

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$pagina = function (string $titulo, string $corpo) use ($C, $e, $CFG_GLPI): void {
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . $e($titulo) . '</title>'
        . '<link rel="stylesheet" href="' . $e($CFG_GLPI['root_doc'] . '/lib/tabler.min.css') . '">'
        . $C::assets() . '</head><body class="ordemdeservico-publico"><main class="ordemdeservico-publico-conteudo">'
        . $corpo . '</main></body></html>';
    exit;
};

$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$os = PluginOrdemdeservicoAssinatura::porToken($token);
if ($os === null) {
    http_response_code(404);
    $pagina('Link indisponível', '<div class="ordemdeservico-publico-aviso"><i class="ti ti-link-off"></i><div><strong>Link indisponível</strong>'
        . '<p>Este link de assinatura não existe, venceu ou a ordem de serviço foi cancelada. Peça um novo link a quem enviou.</p></div></div>');
}

$mensagem = '';
$erro = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (empty($_POST['ciente'])) {
        $erro = 'Marque que você conferiu a ordem de serviço.';
    } else {
        // O histórico do GLPI usa um glpiID não numérico como nome do autor (como nas tarefas automáticas)
        $_SESSION['glpiID'] = 'Link público';
        $erro = PluginOrdemdeservicoAssinatura::gravar($os, 'solicitante', (string) ($_POST['nome'] ?? ''), (string) ($_POST['imagem'] ?? ''), 'link');
        unset($_SESSION['glpiID']);
        if ($erro === '') {
            $mensagem = 'Assinatura registrada. Obrigado!';
        }
    }
}
$assinada = !empty($os->fields['assinatura_solicitante_data']);

$corpo = '<div class="ordemdeservico-publico-barra">'
    . '<span><strong>' . $e($os->fields['numero']) . '</strong> · ' . $e($os->fields['name']) . '</span>'
    . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-ordemdeservico-imprimir-publico><i class="ti ti-printer"></i><span>Imprimir</span></button></div>';
if ($mensagem !== '') {
    $corpo .= '<div class="ordemdeservico-alerta ordemdeservico-alerta-ok"><i class="ti ti-circle-check"></i><span>' . $e($mensagem) . '</span></div>';
} elseif ($assinada) {
    $corpo .= '<div class="ordemdeservico-alerta ordemdeservico-alerta-info"><i class="ti ti-info-circle"></i><span>Esta ordem de serviço já foi assinada por '
        . $e($os->fields['assinatura_solicitante_nome']) . ' em ' . $e(Html::convDateTime((string) $os->fields['assinatura_solicitante_data'])) . '.</span></div>';
}
$corpo .= '<div class="ordemdeservico-papel" data-ordemdeservico-doc>' . PluginOrdemdeservicoDocumento::renderizar($os) . '</div>';

if (!$assinada) {
    $corpo .= '<form method="post" class="card ordemdeservico-card ordemdeservico-publico-form" data-ordemdeservico-publico-form>'
        . '<div class="card-header"><h5><i class="ti ti-writing-sign"></i> Sua assinatura</h5></div><div class="card-body">'
        . '<input type="hidden" name="t" value="' . $e($token) . '"><input type="hidden" name="imagem" value="" data-ordemdeservico-publico-imagem>'
        . ($erro !== '' ? '<div class="ordemdeservico-alerta ordemdeservico-alerta-erro"><i class="ti ti-alert-triangle"></i><span>' . $e($erro) . '</span></div>' : '')
        . '<div class="ordemdeservico-campo"><label for="ordemdeservico-publico-nome">Seu nome completo</label>'
        . '<input type="text" class="form-control" id="ordemdeservico-publico-nome" name="nome" maxlength="255" required value="' . $e($_POST['nome'] ?? $os->fields['solicitante_nome']) . '"></div>'
        . '<div class="ordemdeservico-canvas-caixa"><canvas width="400" height="150" data-ordemdeservico-canvas></canvas><span class="ordemdeservico-canvas-dica">Assine aqui</span></div>'
        . '<div class="ordemdeservico-publico-linha"><span class="ordemdeservico-pequeno"><i class="ti ti-info-circle"></i> Use o mouse, a caneta ou o dedo.</span>'
        . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-ordemdeservico-canvas-limpar><i class="ti ti-eraser"></i><span>Limpar</span></button></div>'
        . '<label class="ordemdeservico-opcao mt-2"><input type="checkbox" class="ordemdeservico-check" name="ciente" value="1" required> Conferi a ordem de serviço acima e concordo com o termo.</label>'
        . '<div class="ordemdeservico-erro" data-ordemdeservico-publico-erro hidden></div>'
        . '<div class="ordemdeservico-rodape-form"><span class="ordemdeservico-pequeno">Link válido até ' . $e(Html::convDateTime((string) $os->fields['token_validade'])) . '</span>'
        . '<button type="submit" class="btn ordemdeservico-btn-principal"><i class="ti ti-check"></i><span>Assinar</span></button></div>'
        . '</div></form>';
}

$pagina('Ordem de serviço ' . $os->fields['numero'], $corpo);
