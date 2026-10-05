<?php

/**
 * Plugin Kanban - anexos do card (documentos nativos do GLPI ligados ao card)
 */
class PluginKanbanAnexo extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Anexos' : 'Anexo';
    }

    public static function listar(int $cards_id): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['d.id', 'd.name', 'd.filename', 'd.filepath', 'd.mime', 'd.date_creation', 'd.users_id', 'di.id AS rel_id'],
            'FROM'   => 'glpi_documents_items AS di',
            'INNER JOIN' => ['glpi_documents AS d' => ['FKEY' => ['di' => 'documents_id', 'd' => 'id']]],
            'WHERE'  => ['di.itemtype' => PluginKanbanCard::class, 'di.items_id' => $cards_id, 'd.is_deleted' => 0],
            'ORDER'  => 'd.date_creation DESC',
        ]) as $r) {
            $caminho = GLPI_DOC_DIR . '/' . $r['filepath'];
            $lista[] = [
                'id'      => (int) $r['id'],
                'nome'    => (string) ($r['filename'] ?: $r['name']),
                'mime'    => (string) $r['mime'],
                'tamanho' => is_file($caminho) ? filesize($caminho) : 0,
                'data'    => Html::convDateTime($r['date_creation']),
                'autor'   => PluginKanbanConfig::nomeUsuario((int) $r['users_id']),
                'imagem'  => str_starts_with((string) $r['mime'], 'image/'),
            ];
        }
        return $lista;
    }

    /** Recebe os arquivos do $_FILES e cria os documentos. Devolve [quantidade, erros] */
    public static function receber(int $cards_id, array $arquivos): array
    {
        $card = PluginKanbanCard::carregar($cards_id);
        if ($card === null) {
            return [0, ['Card não encontrado.']];
        }
        $nomes = (array) ($arquivos['name'] ?? []);
        $tmps  = (array) ($arquivos['tmp_name'] ?? []);
        $erros = (array) ($arquivos['error'] ?? []);

        $total = 0;
        $falhas = [];
        foreach ($nomes as $i => $nome) {
            $nome = basename((string) $nome);
            if ($nome === '') {
                continue;
            }
            if ((int) ($erros[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $falhas[] = $nome . ': ' . (in_array((int) $erros[$i], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'maior que o permitido pelo servidor (' . ini_get('upload_max_filesize') . ')'
                    : 'falha no envio');
                continue;
            }
            // O GLPI move o arquivo de GLPI_TMP_DIR para a pasta de documentos e confere o tipo permitido
            // O prefixo é informado ao GLPI, que o retira e guarda o nome original do arquivo
            $prefixo    = uniqid('kanban', false) . '_';
            $temporario = $prefixo . preg_replace('/[^\w.\-]+/u', '_', $nome);
            if (!@move_uploaded_file((string) $tmps[$i], GLPI_TMP_DIR . '/' . $temporario)) {
                $falhas[] = $nome . ': não foi possível receber o arquivo';
                continue;
            }
            $doc = new Document();
            $docId = $doc->add([
                'entities_id'      => $card['entities_id'],
                'is_recursive'     => 1,
                'users_id'         => (int) Session::getLoginUserID(),
                'name'             => $nome,
                '_only_if_upload_succeed' => 1,
                '_filename'        => [$temporario],
                '_tag_filename'    => [uniqid('', true)],
                '_prefix_filename' => [$prefixo],
            ]);
            if (!$docId) {
                @unlink(GLPI_TMP_DIR . '/' . $temporario);
                $falhas[] = $nome . ': tipo de arquivo não permitido pelo GLPI';
                continue;
            }
            $rel = new Document_Item();
            $rel->add([
                'documents_id' => $docId,
                'itemtype'     => PluginKanbanCard::class,
                'items_id'     => $cards_id,
                'entities_id'  => $card['entities_id'],
            ]);
            $total++;
        }
        if ($total > 0) {
            PluginKanbanComentario::registrar($cards_id, 'sistema', $total . ' anexo(s) adicionado(s).', (int) Session::getLoginUserID());
        }
        return [$total, $falhas];
    }

    /** O documento pertence ao card? */
    public static function doCard(int $cards_id, int $documents_id): bool
    {
        global $DB;
        return count($DB->request([
            'FROM'  => 'glpi_documents_items',
            'WHERE' => ['itemtype' => PluginKanbanCard::class, 'items_id' => $cards_id, 'documents_id' => $documents_id],
            'LIMIT' => 1,
        ])) > 0;
    }

    public static function remover(int $cards_id, int $documents_id): string
    {
        global $DB;
        if (!self::doCard($cards_id, $documents_id)) {
            return 'Anexo não encontrado neste card.';
        }
        $DB->delete('glpi_documents_items', ['itemtype' => PluginKanbanCard::class, 'items_id' => $cards_id, 'documents_id' => $documents_id]);
        // Sem outros vínculos, o documento vai para a lixeira do GLPI
        if (count($DB->request(['FROM' => 'glpi_documents_items', 'WHERE' => ['documents_id' => $documents_id], 'LIMIT' => 1])) === 0) {
            $doc = new Document();
            if ($doc->getFromDB($documents_id)) {
                $doc->delete(['id' => $documents_id]);
            }
        }
        PluginKanbanComentario::registrar($cards_id, 'sistema', 'Anexo removido.', (int) Session::getLoginUserID());
        return '';
    }

    /** Entrega o arquivo (download ou exibição inline de imagens) */
    public static function enviarArquivo(int $cards_id, int $documents_id): void
    {
        $doc = new Document();
        if (!self::doCard($cards_id, $documents_id) || !$doc->getFromDB($documents_id)) {
            http_response_code(404);
            exit;
        }
        $caminho = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
        if (!is_file($caminho)) {
            http_response_code(404);
            exit;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        Toolbox::getFileAsResponse($caminho, (string) $doc->fields['filename'], (string) $doc->fields['mime'])->send();
        exit;
    }
}
