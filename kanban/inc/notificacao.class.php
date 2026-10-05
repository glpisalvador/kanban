<?php

use Symfony\Component\Mime\Address;

/**
 * Plugin Kanban - e-mails (opcionais: chave geral + eventos escolhidos em cada quadro).
 * Destinatários: autor e responsáveis do card, exceto quem fez a ação.
 */
class PluginKanbanNotificacao extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Notificações do Kanban';
    }

    public static function enviar(int $cards_id, string $evento, array $extras = []): void
    {
        global $CFG_GLPI;

        if (!PluginKanbanConfig::emailAtivo() || !($CFG_GLPI['use_notifications'] ?? false)) {
            return;
        }
        $card = PluginKanbanCard::carregar($cards_id);
        $quadro = $card ? PluginKanbanQuadro::carregar($card['plugin_kanban_quadros_id']) : null;
        if ($card === null || $quadro === null || empty($quadro['notificacoes'][$evento])) {
            return;
        }

        $eu = (int) Session::getLoginUserID();
        $destinos = $evento === 'responsavel'
            ? PluginKanbanConfig::jsonParaIds($extras['usuarios'] ?? [])
            : array_unique(array_merge([$card['users_id']], PluginKanbanCard::responsaveisIds($cards_id)));
        $destinos = array_values(array_filter($destinos, fn($u) => $u > 0 && $u !== $eu));
        if (!$destinos) {
            return;
        }

        $coluna = PluginKanbanColuna::carregar($card['plugin_kanban_colunas_id']);
        $autorAcao = PluginKanbanConfig::nomeUsuario($eu) ?: 'Alguém';
        $e = [PluginKanbanConfig::class, 'e'];

        switch ($evento) {
            case 'criacao':
                $assunto = 'Novo card: ' . $card['titulo'];
                $resumo = $e($autorAcao) . ' criou o card na fileira <b>' . $e($coluna['name'] ?? '') . '</b>.';
                break;
            case 'movimentacao':
                $assunto = 'Card movido: ' . $card['titulo'];
                $resumo = $e($autorAcao) . ' moveu o card de <b>' . $e($extras['de'] ?? '') . '</b> para <b>' . $e($extras['para'] ?? '') . '</b>.';
                if (!PluginKanbanConfig::richtextVazio($extras['comentario'] ?? '')) {
                    $resumo .= '<div style="margin-top:8px;padding:8px 12px;border-left:3px solid #dee2e6;background:#f8f9fa;">'
                        . PluginKanbanComentario::htmlSeguro((string) $extras['comentario']) . '</div>';
                }
                break;
            case 'comentario':
                $assunto = 'Novo comentário: ' . $card['titulo'];
                $resumo = $e($autorAcao) . ' comentou:<div style="margin-top:8px;padding:8px 12px;border-left:3px solid #dee2e6;background:#f8f9fa;">'
                    . PluginKanbanComentario::htmlSeguro((string) ($extras['comentario'] ?? '')) . '</div>';
                break;
            case 'responsavel':
                $assunto = 'Você é responsável pelo card: ' . $card['titulo'];
                $resumo = $e($autorAcao) . ' adicionou você como responsável.';
                break;
            default:
                return;
        }
        $assunto = '[Kanban - ' . $quadro['name'] . '] ' . $assunto;

        $url = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/kanban/front/kanban.php?quadro=' . $quadro['id'] . '&card=' . $cards_id;
        $prioridade = PluginKanbanConfig::prioridades()[$card['prioridade']] ?? '';

        $html  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#1d273b;max-width:620px;">';
        $html .= '<div style="font-size:12px;color:#667382;margin-bottom:6px;">' . $e($quadro['name']) . '</div>';
        $html .= '<h2 style="font-size:16px;margin:0 0 10px;">#' . $cards_id . ' ' . $e($card['titulo']) . '</h2>';
        $html .= '<p style="margin:0 0 12px;">' . $resumo . '</p>';
        $html .= '<table style="border-collapse:collapse;font-size:12px;margin-bottom:14px;">';
        $html .= '<tr><td style="padding:4px 12px 4px 0;color:#667382;">Fileira</td><td>' . $e($coluna['name'] ?? '—') . '</td></tr>';
        $html .= '<tr><td style="padding:4px 12px 4px 0;color:#667382;">Prioridade</td><td>' . $e($prioridade) . '</td></tr>';
        if ($card['prazo']) {
            $html .= '<tr><td style="padding:4px 12px 4px 0;color:#667382;">Prazo</td><td>' . $e(Html::convDate($card['prazo'])) . '</td></tr>';
        }
        if ($card['items_id'] > 0) {
            $html .= '<tr><td style="padding:4px 12px 4px 0;color:#667382;">Vínculo</td><td>' . $e(PluginKanbanConfig::nomeItemtype($card['itemtype']) . ' #' . $card['items_id']) . '</td></tr>';
        }
        $html .= '</table>';
        $html .= '<a href="' . $e($url) . '" style="display:inline-block;padding:7px 14px;background:#206bc4;color:#fff;text-decoration:none;border-radius:4px;">Abrir o card</a>';
        $html .= '<p style="font-size:11px;color:#929dab;margin-top:16px;">Mensagem automática do Kanban do GLPI.</p>';
        $html .= '</div>';

        $remetente = (string) ($CFG_GLPI['from_email'] ?: $CFG_GLPI['admin_email']);
        $nomeRemetente = (string) ($CFG_GLPI['from_email_name'] ?: ($CFG_GLPI['admin_email_name'] ?: 'GLPI'));

        foreach ($destinos as $uid) {
            $email = PluginKanbanConfig::emailUsuario($uid);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            try {
                $mailer = new GLPIMailer();
                $mensagem = $mailer->getEmail();
                if ($remetente !== '') {
                    $mensagem->from(new Address($remetente, $nomeRemetente));
                }
                $mensagem->to(new Address($email, PluginKanbanConfig::nomeUsuario($uid)));
                $mensagem->subject($assunto);
                $mensagem->html($html);
                $mensagem->text(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</div>', '</tr>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
                $mensagem->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
                $mensagem->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
                if (!$mailer->send()) {
                    error_log('Plugin kanban: falha ao enviar e-mail para ' . $email . ': ' . (string) $mailer->getError());
                }
            } catch (\Throwable $e2) {
                error_log('Plugin kanban: falha ao enviar e-mail: ' . $e2->getMessage());
            }
        }
    }
}
