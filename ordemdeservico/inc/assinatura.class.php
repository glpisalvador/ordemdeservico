<?php

/**
 * Plugin Ordem de Serviço - assinaturas (PNG do canvas) e link público de assinatura.
 * Os arquivos ficam em files/_plugins/ordemdeservico/assinaturas/{id da OS}/{papel}.png (fora da web).
 */
class PluginOrdemdeservicoAssinatura
{
    public const PAPEIS = ['tecnico' => 'Técnico responsável', 'solicitante' => 'Solicitante'];
    public const LIMITE = 400 * 1024;

    /** Caminho do PNG (a pasta só é criada ao gravar) */
    public static function arquivo(int $os_id, string $papel): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/ordemdeservico/assinaturas/' . $os_id . '/' . $papel . '.png';
    }

    public static function existe(PluginOrdemdeservicoOrdem $os, string $papel): bool
    {
        return isset(self::PAPEIS[$papel]) && !empty($os->fields['assinatura_' . $papel . '_data']) && is_file(self::arquivo((int) $os->getID(), $papel));
    }

    public static function dataUri(PluginOrdemdeservicoOrdem $os, string $papel): string
    {
        if (!self::existe($os, $papel)) {
            return '';
        }
        return 'data:image/png;base64,' . base64_encode((string) file_get_contents(self::arquivo((int) $os->getID(), $papel)));
    }

    /** Status calculado pelas assinaturas (cancelada não muda) */
    public static function statusPorAssinaturas(array $f): string
    {
        if (($f['status'] ?? '') === 'cancelada') {
            return 'cancelada';
        }
        $n = (int) !empty($f['assinatura_tecnico_data']) + (int) !empty($f['assinatura_solicitante_data']);
        return $n === 2 ? 'assinada' : ($n === 1 ? 'parcial' : 'emitida');
    }

    /**
     * Grava a assinatura. $imagem é o dataURL do canvas. $origem: 'tela' ou 'link'. Retorna o erro ou ''.
     */
    public static function gravar(PluginOrdemdeservicoOrdem $os, string $papel, string $nome, string $imagem, string $origem = 'tela'): string
    {
        global $DB;
        if (!isset(self::PAPEIS[$papel])) {
            return 'Assinatura inválida.';
        }
        if ($os->fields['status'] === 'cancelada' || (int) $os->fields['is_deleted'] === 1) {
            return 'Esta ordem de serviço não aceita mais assinaturas.';
        }
        if (self::existe($os, $papel)) {
            return 'Esta assinatura já foi registrada.';
        }
        $nome = trim((string) preg_replace('/\s+/u', ' ', strip_tags($nome)));
        if (mb_strlen($nome) < 3) {
            return 'Informe o nome de quem assina.';
        }
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $imagem, $m)) {
            return 'Assinatura inválida.';
        }
        $binario = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        if ($binario === false || strlen($binario) > self::LIMITE) {
            return 'A imagem da assinatura é inválida ou grande demais.';
        }
        $info = @getimagesizefromstring($binario);
        if ($info === false || ($info['mime'] ?? '') !== 'image/png' || $info[0] > 2000 || $info[1] > 1000) {
            return 'A imagem da assinatura é inválida.';
        }
        PluginOrdemdeservicoConfig::pasta('assinaturas/' . (int) $os->getID());
        if (@file_put_contents(self::arquivo((int) $os->getID(), $papel), $binario) === false) {
            return 'Não foi possível gravar a assinatura.';
        }
        $campos = [
            'assinatura_' . $papel . '_nome' => mb_substr($nome, 0, 255),
            'assinatura_' . $papel . '_data' => date('Y-m-d H:i:s'),
        ];
        if ($papel === 'solicitante') {
            $campos['assinatura_solicitante_origem'] = $origem === 'link' ? 'link' : 'tela';
        }
        $campos['status'] = self::statusPorAssinaturas(array_merge($os->fields, $campos));
        $DB->update(PluginOrdemdeservicoOrdem::getTable(), $campos, ['id' => (int) $os->getID()]);
        $os->getFromDB((int) $os->getID());

        $texto = self::PAPEIS[$papel] . ' assinou: ' . $nome . ($origem === 'link' ? ' (pelo link público)' : '');
        Log::history((int) $os->getID(), PluginOrdemdeservicoOrdem::class, [0, '', $texto], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
        if ($campos['status'] === 'assinada') {
            Log::history((int) $os->fields['items_id'], (string) $os->fields['itemtype'], [0, '', 'Ordem de serviço ' . $os->fields['numero'] . ' assinada'], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
        }
        return '';
    }

    /** Apaga uma assinatura (só enquanto a OS não está cancelada) */
    public static function remover(PluginOrdemdeservicoOrdem $os, string $papel): string
    {
        global $DB;
        if (!isset(self::PAPEIS[$papel]) || $os->fields['status'] === 'cancelada') {
            return 'Não é possível remover esta assinatura.';
        }
        $arquivo = self::arquivo((int) $os->getID(), $papel);
        if (is_file($arquivo)) {
            @unlink($arquivo);
        }
        $campos = ['assinatura_' . $papel . '_nome' => '', 'assinatura_' . $papel . '_data' => null];
        if ($papel === 'solicitante') {
            $campos['assinatura_solicitante_origem'] = '';
        }
        $campos['status'] = self::statusPorAssinaturas(array_merge($os->fields, $campos));
        $DB->update(PluginOrdemdeservicoOrdem::getTable(), $campos, ['id' => (int) $os->getID()]);
        $os->getFromDB((int) $os->getID());
        Log::history((int) $os->getID(), PluginOrdemdeservicoOrdem::class, [0, '', 'Assinatura removida: ' . self::PAPEIS[$papel]], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
        return '';
    }

    /** Apaga os arquivos de assinatura de uma OS (ao excluir definitivamente) */
    public static function removerArquivos(int $os_id): void
    {
        foreach (array_keys(self::PAPEIS) as $papel) {
            $arquivo = self::arquivo($os_id, $papel);
            if (is_file($arquivo)) {
                @unlink($arquivo);
            }
        }
        $pasta = dirname(self::arquivo($os_id, 'tecnico'));
        if (is_dir($pasta)) {
            @rmdir($pasta);
        }
    }

    // =====================================================================
    // Link público
    // =====================================================================

    public static function linkAtivo(): bool
    {
        return (string) PluginOrdemdeservicoConfig::getConfig('assinatura_remota') === '1';
    }

    /** Gera (ou renova) o token do link de assinatura do solicitante */
    public static function renovarToken(PluginOrdemdeservicoOrdem $os): string
    {
        global $DB;
        $dias = max(1, min(90, (int) PluginOrdemdeservicoConfig::getConfig('validade_dias')));
        $token = bin2hex(random_bytes(24));
        $DB->update(PluginOrdemdeservicoOrdem::getTable(), [
            'token'          => $token,
            'token_validade' => date('Y-m-d H:i:s', time() + $dias * 86400),
        ], ['id' => (int) $os->getID()]);
        $os->getFromDB((int) $os->getID());
        return $token;
    }

    /** Link vigente (gera um novo se não houver ou se venceu); '' se o recurso está desligado */
    public static function link(PluginOrdemdeservicoOrdem $os, bool $gerar = true): string
    {
        if (!self::linkAtivo() || $os->fields['status'] === 'cancelada') {
            return '';
        }
        $valido = !empty($os->fields['token']) && !empty($os->fields['token_validade']) && strtotime((string) $os->fields['token_validade']) > time();
        if (!$valido) {
            if (!$gerar) {
                return '';
            }
            self::renovarToken($os);
        }
        return PluginOrdemdeservicoConfig::urlAbsoluta('assinar.php', ['t' => (string) $os->fields['token']]);
    }

    /** OS pelo token do link público (válido, não vencido, não cancelada, não excluída) */
    public static function porToken(string $token): ?PluginOrdemdeservicoOrdem
    {
        global $DB;
        if (!self::linkAtivo() || !preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => PluginOrdemdeservicoOrdem::getTable(),
            'WHERE'  => ['token' => $token, 'is_deleted' => 0, 'status' => ['<>', 'cancelada'], 'token_validade' => ['>', date('Y-m-d H:i:s')]],
            'LIMIT'  => 1,
        ]) as $r) {
            $os = new PluginOrdemdeservicoOrdem();
            if ($os->getFromDB((int) $r['id'])) {
                return $os;
            }
        }
        return null;
    }
}
