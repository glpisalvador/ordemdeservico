# Ordem de Serviço para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x**

Gera **ordens de serviço (OS)** a partir de chamados, problemas e mudanças. A OS é um documento formal, com o conteúdo do item congelado no momento da geração e com as **assinaturas** do técnico e do solicitante.

## O que o plugin faz

### Gerar a OS
- No item, a aba **Ordens de serviço** lista as OS dele e gera uma nova. Na geração você escolhe:
  - o título;
  - as **seções** do item que entram: informações do atendimento, atores, descrição, acompanhamentos, tarefas, validações, solução e imagens anexas;
  - se os itens **privados** entram;
  - o solicitante, o técnico e as observações.
- Número automático no formato `prefixo-AAAA-NNNNN`.
- O conteúdo é **congelado** na geração, com as imagens embutidas para funcionar no PDF, no e-mail e na página pública. Ele pode ser atualizado enquanto ninguém assinou.

### Assinaturas
- **Na tela**, desenhando com o mouse ou com o dedo: técnico e solicitante.
- **Por link público:** o solicitante recebe um endereço, válido pelo número de dias configurado, e assina sem precisar de login.
- Situação da OS: emitida, parcialmente assinada, assinada ou cancelada.

### Documento
- Aba **Documento** com a OS formatada: cabeçalho com logo e dados da empresa, seções, observações, **termo** de aceite, assinaturas e rodapé.
- **Imprimir** ou **baixar em PDF**. O PDF é gerado no navegador.
- **Anexar o PDF** ao chamado, problema ou mudança como documento nativo.
- **Enviar por e-mail**, com o PDF anexado e assunto e mensagem a partir de modelos. Cada envio fica registrado na aba **Envios**.

### Lista e controle
- Todas as OS ficam numa **lista com a busca nativa**, com filtros, colunas, exportação e ações em massa, em *Assistência → Ordens de serviço*.
- Abas **Documentos** e **Histórico** em cada OS.
- **Cancelar** e **reativar** uma OS.

## Configuração e direitos

- **Direitos nativos** na aba **Ordens de serviço** do perfil.
- Opções da página de configuração:
  - em quais itens a aba aparece;
  - dados da empresa, cabeçalho, rodapé e **logo**;
  - prefixo da numeração e modelo do título;
  - seções padrão e se os itens privados entram;
  - texto do **termo**;
  - assinatura remota e validade do link;
  - remetente, assunto e mensagem do e-mail.

---

## Download e instalação

1. Baixe o arquivo `ordemdeservico-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/ordemdeservico/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/ordemdeservico
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install ordemdeservico -u <usuário administrador>
   php bin/console plugin:activate ordemdeservico
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/ordemdeservico` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install ordemdeservico -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).