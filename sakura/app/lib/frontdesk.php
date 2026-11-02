<?php
declare(strict_types=1);

// スタッフの発言はそのまま GO 本人にプッシュ通知するだけ。予定への自動反映は行わない
function handle_frontdesk_message(string $staffUserId, string $text): string
{
    $name = line_display_name($staffUserId);
    line_push((string)config('line.owner_user_id'), "【伝言】{$name}さんより:\n{$text}");
    return '伝言を確認しました。GOに転送しました。';
}
