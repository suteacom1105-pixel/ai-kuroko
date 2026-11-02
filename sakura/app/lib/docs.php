<?php
declare(strict_types=1);

// ===== アイデアメモ（専用の Google ドキュメントに追記） =====

const IDEA_DOC_ID_KEY = 'google:idea_doc_id';
const IDEA_DOC_TITLE = 'AI秘書 黒子 - アイデアメモ';

function idea_doc_url(string $documentId): string
{
    return "https://docs.google.com/document/d/{$documentId}/edit";
}

/** 設定にあればそれ（旧 Vercel 版のドキュメントを引き継ぐ）、なければ保存済みのもの、それも無ければ新規作成 */
function idea_doc_id(): string
{
    $configured = (string)config('google.idea_doc_id');
    if ($configured !== '') {
        return $configured;
    }
    $saved = kv_get(IDEA_DOC_ID_KEY);
    if (is_string($saved) && $saved !== '') {
        return $saved;
    }
    $doc = google_api('POST', 'https://docs.googleapis.com/v1/documents', [], ['title' => IDEA_DOC_TITLE]);
    $id = (string)($doc['documentId'] ?? '');
    if ($id === '') {
        throw new RuntimeException('アイデアメモ用のGoogleドキュメント作成に失敗しました');
    }
    kv_set(IDEA_DOC_ID_KEY, $id);
    return $id;
}

/** 前のメモとくっつかないよう、先頭に空行を入れて末尾に追記する */
function add_idea(string $content): array
{
    $documentId = idea_doc_id();
    $line = '[' . jst_now_label() . "] {$content}";
    google_api('POST', 'https://docs.googleapis.com/v1/documents/' . rawurlencode($documentId) . ':batchUpdate', [], [
        'requests' => [['insertText' => ['endOfSegmentLocation' => new stdClass(), 'text' => "\n{$line}\n"]]],
    ]);
    return ['line' => $line, 'docUrl' => idea_doc_url($documentId)];
}

/** 新しい順（ドキュメントの末尾が最新） */
function list_ideas(int $limit = 10): array
{
    $doc = google_api('GET', 'https://docs.googleapis.com/v1/documents/' . rawurlencode(idea_doc_id()));
    $lines = [];
    foreach ($doc['body']['content'] ?? [] as $element) {
        $text = '';
        foreach ($element['paragraph']['elements'] ?? [] as $e) {
            $text .= $e['textRun']['content'] ?? '';
        }
        $text = preg_replace('/\n$/', '', $text);
        if (trim($text) !== '') {
            $lines[] = $text;
        }
    }
    return array_reverse(array_slice($lines, -$limit));
}
