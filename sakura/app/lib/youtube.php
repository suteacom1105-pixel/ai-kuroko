<?php
declare(strict_types=1);

// ===== YouTube（自分のチャンネルの動画一覧・分析） =====

// 複数チャンネルを管理しているアカウントでは mine=true が正しく解決されないことがあるため、
// youtube_channel_id が設定されていればそちらを優先する
function youtube_channel_filter(): array
{
    $id = (string)config('google.youtube_channel_id');
    return $id !== '' ? ['id' => $id] : ['mine' => true];
}

function list_recent_videos(int $maxResults = 5): array
{
    $channels = google_api('GET', 'https://www.googleapis.com/youtube/v3/channels', ['part' => 'contentDetails'] + youtube_channel_filter());
    $uploads = $channels['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
    if (!$uploads) {
        throw new RuntimeException('自分のチャンネルのアップロード一覧が取得できませんでした');
    }
    $items = google_api('GET', 'https://www.googleapis.com/youtube/v3/playlistItems', [
        'part' => 'snippet',
        'playlistId' => $uploads,
        'maxResults' => $maxResults,
    ]);
    return array_map(fn($item) => [
        'videoId' => $item['snippet']['resourceId']['videoId'] ?? '',
        'title' => $item['snippet']['title'] ?? '(無題)',
        'publishedAt' => $item['snippet']['publishedAt'] ?? '',
    ], $items['items'] ?? []);
}

/** 動画の公開日から今日までの累計 */
function get_video_analytics(string $videoId, string $publishedAtIso): array
{
    $id = (string)config('google.youtube_channel_id');
    $base = [
        'ids' => $id !== '' ? "channel=={$id}" : 'channel==MINE',
        'startDate' => substr($publishedAtIso, 0, 10),
        'endDate' => jst_date_string(0),
        'filters' => "video=={$videoId}",
    ];
    $summary = google_api('GET', 'https://youtubeanalytics.googleapis.com/v2/reports', $base + [
        'metrics' => 'views,averageViewPercentage,estimatedMinutesWatched',
    ]);
    $row = $summary['rows'][0] ?? [0, 0, 0];

    $traffic = google_api('GET', 'https://youtubeanalytics.googleapis.com/v2/reports', $base + [
        'metrics' => 'views',
        'dimensions' => 'insightTrafficSourceType',
        'sort' => '-views',
        'maxResults' => 5,
    ]);

    return [
        'videoId' => $videoId,
        'views' => (float)($row[0] ?? 0),
        'averageViewPercentage' => (float)($row[1] ?? 0),
        'estimatedMinutesWatched' => (float)($row[2] ?? 0),
        'topTrafficSources' => array_map(fn($r) => ['source' => (string)$r[0], 'views' => (float)$r[1]], $traffic['rows'] ?? []),
    ];
}
