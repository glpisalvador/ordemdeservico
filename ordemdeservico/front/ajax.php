<?php

/**
 * Plugin Ordem de Serviço - endpoint AJAX (sempre JSON, sempre POST).
 * Ações: assinar, remover_assinatura, link, sugestao_email, enviar, anexar, regenerar, cancelar, reativar.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'mensagem' => 'Erro interno: ' . $erro['message']]);
    }
});

$C = PluginOrdemdeservicoConfig::class;
$responder = function (array $dados) use ($C): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $dados['new_token'] = $C::tokenCsrf();
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$falhar = fn(string $mensagem) => $responder(['success' => false, 'mensagem' => $mensagem]);

if ((int) Session::getLoginUserID() <= 0) {
    $falhar('Sessão expirada. Recarregue a página.');
}
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $falhar('Requisição inválida.');
}

$acao = (string) ($_POST['action'] ?? '');
$os = new PluginOrdemdeservicoOrdem();
$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0 || !$os->getFromDB($id) || !$os->can($id, READ)) {
    $falhar('Ordem de serviço não encontrada.');
}
$exigirEdicao = function () use ($os, $id, $falhar): void {
    if (!$os->can($id, UPDATE)) {
        $falhar('Você não pode alterar esta ordem de serviço.');
    }
    if ($os->fields['status'] === 'cancelada') {
        $falhar('Ordem de serviço cancelada.');
    }
};
$recarregar = fn(string $mensagem) => $responder(['success' => true, 'mensagem' => $mensagem, 'recarregar' => true]);

switch ($acao) {
    case 'assinar':
        $exigirEdicao();
        $papel = (string) ($_POST['papel'] ?? '');
        $erro = PluginOrdemdeservicoAssinatura::gravar($os, $papel, (string) ($_POST['nome'] ?? ''), (string) ($_POST['imagem'] ?? ''), 'tela');
        if ($erro !== '') {
            $falhar($erro);
        }
        Session::addMessageAfterRedirect('Assinatura registrada.', false, INFO);
        $recarregar('Assinatura registrada.');
        // no break

    case 'remover_assinatura':
        $exigirEdicao();
        $erro = PluginOrdemdeservicoAssinatura::remover($os, (string) ($_POST['papel'] ?? ''));
        if ($erro !== '') {
            $falhar($erro);
        }
        Session::addMessageAfterRedirect('Assinatura removida.', false, INFO);
        $recarregar('Assinatura removida.');
        // no break

    case 'link':
        $exigirEdicao();
        if (!PluginOrdemdeservicoAssinatura::linkAtivo()) {
            $falhar('A assinatura pelo link está desligada na configuração.');
        }
        if (!empty($os->fields['assinatura_solicitante_data'])) {
            $falhar('O solicitante já assinou.');
        }
        PluginOrdemdeservicoAssinatura::renovarToken($os);
        Log::history($id, PluginOrdemdeservicoOrdem::class, [0, '', 'Link de assinatura gerado'], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
        $responder([
            'success'  => true,
            'mensagem' => 'Link gerado.',
            'link'     => PluginOrdemdeservicoAssinatura::link($os, false),
            'validade' => 'Válido até ' . Html::convDateTime((string) $os->fields['token_validade']),
        ]);
        // no break

    case 'sugestao_email':
        $comLink = !empty($_POST['com_link']) && $os->can($id, UPDATE) && empty($os->fields['assinatura_solicitante_data']);
        $responder(['success' => true] + PluginOrdemdeservicoEnvio::sugestao($os, $comLink));
        // no break

    case 'enviar':
        if ($os->fields['status'] === 'cancelada') {
            $falhar('Ordem de serviço cancelada.');
        }
        $pdf = '';
        if (trim((string) ($_POST['pdf'] ?? '')) !== '') {
            $pdf = PluginOrdemdeservicoEnvio::decodificarPdf((string) $_POST['pdf']);
            if ($pdf === '') {
                $falhar('O PDF gerado é inválido ou passa de 6 MB. Envie sem anexar o PDF.');
            }
        }
        $comLink = !empty($_POST['com_link']) && $os->can($id, UPDATE) && empty($os->fields['assinatura_solicitante_data']);
        $r = PluginOrdemdeservicoEnvio::enviar(
            $os,
            PluginOrdemdeservicoEnvio::emails($_POST['para'] ?? ''),
            PluginOrdemdeservicoEnvio::emails($_POST['cc'] ?? ''),
            (string) ($_POST['assunto'] ?? ''),
            (string) ($_POST['mensagem'] ?? ''),
            $pdf,
            $comLink
        );
        if ($r['ok']) {
            Session::addMessageAfterRedirect($r['mensagem'], false, INFO);
        }
        $responder(['success' => $r['ok'], 'mensagem' => $r['mensagem'], 'recarregar' => $r['ok']]);
        // no break

    case 'anexar':
        $r = PluginOrdemdeservicoEnvio::anexarAoItem($os, PluginOrdemdeservicoEnvio::decodificarPdf((string) ($_POST['pdf'] ?? '')));
        $responder(['success' => $r['ok'], 'mensagem' => $r['mensagem']]);
        // no break

    case 'regenerar':
        $exigirEdicao();
        $erro = $os->regenerar();
        if ($erro !== '') {
            $falhar($erro);
        }
        Session::addMessageAfterRedirect('Conteúdo atualizado com os dados atuais do item.', false, INFO);
        $recarregar('Conteúdo atualizado.');
        // no break

    case 'cancelar':
    case 'reativar':
        if (!$os->can($id, UPDATE)) {
            $falhar('Você não pode alterar esta ordem de serviço.');
        }
        $os->cancelar($acao === 'cancelar');
        Session::addMessageAfterRedirect($acao === 'cancelar' ? 'Ordem de serviço cancelada.' : 'Ordem de serviço reativada.', false, INFO);
        $recarregar('ok');
        // no break

    default:
        $falhar('Ação desconhecida.');
}
