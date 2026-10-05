# Kanban para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv3+** · Compatível com GLPI **11.0.0 a 12.x**

**Quadros Kanban** dentro do GLPI, com quantos quadros e colunas ("fileiras") você quiser. Os cards podem ser ligados a chamados, problemas e mudanças, e o que acontece num lado aparece no outro.

## O que o plugin faz

### Quadros e fileiras
- **Vários quadros**, cada um com suas fileiras, acesso por **perfis, grupos ou usuários** e tipos de item permitidos.
- Cada **fileira** define:
  - o **status** que o item ligado recebe quando o card entra nela, separado para chamado, problema e mudança;
  - se é uma fileira **final**;
  - se **pede comentário** ao entrar;
  - um **limite de cards** (WIP);
  - uma **validação nativa** opcional ao entrar (chamado ou mudança), com os validadores e as fileiras de destino para aprovado e recusado.

### Cards
- Título, descrição em texto rico, prazo e prioridade.
- **Arrastar e soltar** entre fileiras, com atualização automática do quadro.
- **Linha do tempo** com comentários, movimentações, acompanhamentos e soluções do item ligado, e respostas de validação.
- **Anexos**, guardados como documentos nativos do GLPI.
- **Quem abriu** cada card: primeira e última vez e quantas vezes.

### Integração com chamados, problemas e mudanças
- **Do quadro para o GLPI:**
  - mover o card aplica o status configurado na fileira; solucionado e fechado entram pela **solução** nativa;
  - comentários viram **acompanhamentos**, públicos ou privados;
  - quem trabalha no card pode ser atribuído como **técnico** do item;
  - fileiras podem pedir **validação**.
- Cada uma dessas sincronizações pode ser ligada ou desligada por quadro.
- **Do GLPI para o quadro:**
  - uma mudança de status **move o card** para a fileira correspondente;
  - acompanhamentos, soluções e respostas de validação aparecem na linha do tempo do card.
- **Vínculo automático** opcional: um card novo pode abrir o chamado correspondente, com tipo e categorias configurados no quadro.
- Aba **Kanban** no chamado, no problema e na mudança.
- Uma trava evita eco: o que o próprio plugin faz no item não volta para o card.

### E-mails (opcionais)
Chave geral mais os eventos escolhidos em cada quadro. Os destinatários são o autor e os responsáveis do card, exceto quem fez a ação.

## Configuração

Na página de configuração:
- quadros, fileiras (com editor), acesso e integração ITIL de cada quadro;
- opções gerais.

O quadro fica em **Ferramentas → Kanban**.

---

## Download e instalação

1. Baixe o arquivo `kanban-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/kanban/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/kanban
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install kanban -u <usuário administrador>
   php bin/console plugin:activate kanban
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/kanban` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install kanban -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v3.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).