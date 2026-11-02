#!/bin/sh
# さくらのサーバーに黒子の鍵（config.secrets.php）を保存する（あなたがターミナルで実行）
#   sh sakura/deploy/setup_secrets.sh
# 入力した値は画面に表示されず、このパソコンにも保存されません。もう一度実行すると全部入れ直しになります。
set -eu

SSH="ssh -i $HOME/.ssh/semic_timecard -o IdentitiesOnly=yes"
HOST="semic@semic.sakura.ne.jp"

ask() { printf "%s" "$1"; stty -echo; read REPLY_VALUE; stty echo; echo; }

echo "1/5 LINE Developers →「AI秘書 黒子」チャネル →「チャネル基本設定」タブの「チャネルシークレット」"
ask "    貼り付け（表示されません）: "; LINE_SECRET=$REPLY_VALUE
echo "2/5 同じチャネルの「Messaging API設定」タブの一番下「チャネルアクセストークン（長期）」"
echo "    ※「再発行」は押さないでください（切り替えまで動いている今の黒子が止まります）"
ask "    貼り付け（表示されません）: "; LINE_TOKEN=$REPLY_VALUE
echo "3/5 Claude Console（console.anthropic.com）→「API Keys」→「Create Key」で新しいキーを作ってコピー"
echo "    （今のキーは表示できないので新しく作ります。切り替え後に古いキーは削除できます）"
ask "    貼り付け（表示されません）: "; CLAUDE_KEY=$REPLY_VALUE
echo "4/5 Google Cloud（ai-kuroko）→ Google Auth Platform →「クライアント」→「ai-kuroko web client」→「シークレットを追加」でコピー"
echo "    （今のシークレットはそのまま残してください。切り替えまで今の黒子が使っています）"
ask "    貼り付け（表示されません）: "; GOOGLE_SECRET=$REPLY_VALUE
echo "5/5 アイデアメモの Google ドキュメントの URL（LINE の黒子の返信に付いていたリンク）"
printf "    貼り付け（空のまま Enter で、新しいドキュメントを作ります）: "; read DOC_URL
DOC_ID=$(printf "%s" "$DOC_URL" | sed -n 's#.*/document/d/\([A-Za-z0-9_-]*\).*#\1#p')

if [ ${#LINE_TOKEN} -lt 100 ] || [ ${#LINE_SECRET} -lt 20 ] || [ ${#CLAUDE_KEY} -lt 40 ] || [ ${#GOOGLE_SECRET} -lt 10 ]; then
  echo "どれかの値が短すぎます。全部コピーできているか確認して、もう一度実行してください。"; exit 1
fi

esc() { printf "%s" "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/\\\\'/g"; }
{
cat <<PHP
<?php
// 黒子の鍵（sakura/deploy/setup_secrets.sh で作成）
return [
    'claude' => ['api_key' => '$(esc "$CLAUDE_KEY")'],
    'line' => ['channel_secret' => '$(esc "$LINE_SECRET")', 'channel_access_token' => '$(esc "$LINE_TOKEN")'],
    'google' => ['client_secret' => '$(esc "$GOOGLE_SECRET")', 'idea_doc_id' => '$(esc "$DOC_ID")'],
];
PHP
} | $SSH "$HOST" "mkdir -p kuroko_app && umask 077 && cat > kuroko_app/config.secrets.php && chmod 600 kuroko_app/config.secrets.php && php -l kuroko_app/config.secrets.php >/dev/null && php kuroko_app/cli/status.php" 2>/dev/null
