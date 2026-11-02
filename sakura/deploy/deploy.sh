#!/bin/sh
# さくらへ設置・更新する: sh sakura/deploy/deploy.sh
# - app/    → ~/kuroko_app/（Web から見えない場所）
# - public/ → ~/www/kuroko.hizasupo.com/（公開フォルダ）
# サーバーの config.secrets.php・data/（会話履歴・Google 連携）・live（本番切り替えの印）は上書き・削除しない
set -eu
cd "$(dirname "$0")/.."

SSH="ssh -i $HOME/.ssh/semic_timecard -o IdentitiesOnly=yes"
HOST="semic@semic.sakura.ne.jp"

for f in $(find app public -name '*.php'); do
  php -l "$f" >/dev/null || { echo "構文エラー: $f"; exit 1; }
done
php tests/run.php >/dev/null || { echo "テストが失敗したので中止します（php sakura/tests/run.php で確認）"; exit 1; }

rsync -rlptz --delete -e "$SSH" \
  --exclude 'config.secrets.php' --exclude 'config.local.php' --exclude 'data/' --exclude 'live' --exclude '.DS_Store' \
  app/ "$HOST:kuroko_app/" 2>/dev/null

rsync -rlptz --delete -e "$SSH" --exclude '.DS_Store' \
  public/ "$HOST:www/kuroko.hizasupo.com/" 2>/dev/null

$SSH "$HOST" "chmod 700 kuroko_app && mkdir -p kuroko_app/data && chmod 700 kuroko_app/data" 2>/dev/null
echo "設置が終わりました: https://kuroko.hizasupo.com/"
