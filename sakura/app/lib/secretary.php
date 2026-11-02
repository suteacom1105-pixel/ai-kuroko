<?php
declare(strict_types=1);

// ===== GO 本人向け：Claude のツール呼び出しで秘書の仕事をする会話ループ =====

const MAX_TOOL_TURNS = 6;

function secretary_system_prompt(): string
{
    return implode("\n", [
        'あなたはGO(接骨院経営、フリースクール「revision」運営、YouTube運用)専属のAI秘書です。',
        '現在の日時(JST)は ' . jst_now_label() . ' です。',
        '予定の確認・追加・変更・削除、タスク管理、アイデアメモ、YouTube動画分析、ひざスポ接骨院の予約管理の依頼には用意されたツールを使って対応してください。',
        '患者さんの予約・予約不可(販売不可)・予約のキャンセルや日時変更など、ひざスポ接骨院の予約表に関する依頼はclinic_*ツールを使ってください。GOの個人の予定(list_events等)とは別物です。' .
            '予約システムの担当は 伊藤(施術1=treatment)、榊(施術2=treatment2)、トレーニング(training) の3つです。',
        '予約を追加する際、メニューの指定がなく判断できない場合は、clinic_list_menusの一覧から候補を示して短く確認してください。' .
            '予約不可にする際、どの担当を止めるかの指定がない場合は、伊藤・榊・トレーニング・全部のどれにするか短く確認してください。',
        'キャンセル・日時変更・予約不可の解除は、clinic_get_dayで対象を確認してから行い、該当が複数ある・特定できない場合は実行せずに確認してください。',
        'アイデアメモはGoogleドキュメントに保存されます。add_ideaの実行結果にdocUrlが含まれる場合は、保存完了の返信にそのリンクも添えてください。',
        '新幹線・飛行機・コンサート等のチケットを予定に登録する際は、descriptionに列車・便名(号数)と座席情報(号車・番号など)を1行ずつ改行して分けて書いてください。eチケット利用方法などの不要な情報は書かないでください。当日朝の通知にもその内容が表示されます。',
        '「予定」(list_events/create_event等)と「タスク」(list_tasks/create_task等)は別物です。' .
            '開始・終了時刻が決まっている/終日の日付が決まっている用件は予定、' .
            '「〇〇までに××する」「××しないと」のような特定時刻を伴わないやることはタスクとして扱ってください。',
        '「明日」「来週」「さっきの予定」等の相対的な表現は、現在日時と直前の会話履歴から具体的な日時に変換してください。',
        'どの予定を指しているか曖昧な場合は、list_eventsで一覧を取得したうえで確認質問をしてください。',
        '返信は簡潔で丁寧な日本語のチャットメッセージにしてください。箇条書きが読みやすい場合は活用してください。',
        'LINEのトーク画面はMarkdown記法を装飾表示できません。**太字**や見出し(#)、項目名(「予定:」「日時:」等のラベル)は使わず、プレーンテキストで書いてください。',
    ]);
}

const TICKET_IMAGE_PROMPT =
    'これはチケットのスクリーンショットです。新幹線・飛行機・コンサート等の日時、座席番号、号車、搭乗券番号など予定管理に必要な情報を読み取り、create_eventツールで予定に登録してください。' .
    '登録後の返信は、項目名のラベルや説明文を付けず、次の3つを1行ずつ、間に必ず空行を1行入れて書いてください: ①列車・便名・催し物名など ②日付と時刻 ③座席・号車などの情報。備考は書かないでください。' .
    '情報が読み取れない場合は、その旨を伝えてください。';

const FALLBACK_REPLY = 'すみません、うまく処理できませんでした。もう一度お願いします。';

function handle_secretary_message(string $userId, string $text): string
{
    $messages = get_conversation($userId);
    $messages[] = ['role' => 'user', 'content' => $text];
    $reply = run_conversation($messages);
    append_conversation($userId, [['role' => 'user', 'content' => $text], ['role' => 'assistant', 'content' => $reply]]);
    return $reply;
}

/** チケットのスクショ等の画像から予定を登録する。履歴には画像そのものは残さない */
function handle_secretary_image(string $userId, array $image): string
{
    $messages = get_conversation($userId);
    $messages[] = ['role' => 'user', 'content' => [
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['mediaType'], 'data' => $image['base64']]],
        ['type' => 'text', 'text' => TICKET_IMAGE_PROMPT],
    ]];
    $reply = run_conversation($messages);
    append_conversation($userId, [['role' => 'user', 'content' => '[チケット画像を送信]'], ['role' => 'assistant', 'content' => $reply]]);
    return $reply;
}

function run_conversation(array $messages): string
{
    $finalText = '';
    for ($turn = 0; $turn < MAX_TOOL_TURNS; $turn++) {
        $response = claude_create([
            'model' => claude_model(),
            'max_tokens' => 1024,
            'system' => secretary_system_prompt(),
            'tools' => claude_tools(),
            'messages' => $messages,
        ]);
        $finalText = claude_text_of($response);
        if (($response['stop_reason'] ?? '') !== 'tool_use') {
            break;
        }

        $content = claude_content_for_resend($response['content']);
        $messages[] = ['role' => 'assistant', 'content' => $content];

        $results = [];
        foreach ($response['content'] as $block) {
            if (($block['type'] ?? '') !== 'tool_use') {
                continue;
            }
            try {
                $result = execute_tool($block['name'], (array)($block['input'] ?? []));
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            } catch (Throwable $e) {
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'], 'is_error' => true, 'content' => 'エラー: ' . $e->getMessage()];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $results];
    }
    return $finalText !== '' ? $finalText : FALLBACK_REPLY;
}
