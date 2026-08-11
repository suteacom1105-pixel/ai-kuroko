import { google } from 'googleapis';
import { getAuthorizedClient } from './auth';

async function youtubeClient() {
  const auth = await getAuthorizedClient();
  return google.youtube({ version: 'v3', auth });
}

async function youtubeAnalyticsClient() {
  const auth = await getAuthorizedClient();
  return google.youtubeAnalytics({ version: 'v2', auth });
}

export type RecentVideo = {
  videoId: string;
  title: string;
  publishedAt: string;
};

// 複数チャンネルを管理しているアカウントでは mine:true が正しく解決されないことがあるため、
// YOUTUBE_CHANNEL_ID が設定されていればそちらを優先する(未設定なら mine:true にフォールバック)
function channelIdFilter(): { mine: true } | { id: string[] } {
  const channelId = process.env.YOUTUBE_CHANNEL_ID;
  return channelId ? { id: [channelId] } : { mine: true };
}

// 自分のチャンネルのアップロード動画のみ取得可能(他チャンネル分析は不可)
export async function listRecentVideos(maxResults = 5): Promise<RecentVideo[]> {
  const yt = await youtubeClient();
  const channelsRes = await yt.channels.list({ part: ['contentDetails'], ...channelIdFilter() });
  const uploadsPlaylistId = channelsRes.data.items?.[0]?.contentDetails?.relatedPlaylists?.uploads;
  if (!uploadsPlaylistId) {
    throw new Error('自分のチャンネルのアップロード一覧が取得できませんでした');
  }

  const itemsRes = await yt.playlistItems.list({
    part: ['snippet'],
    playlistId: uploadsPlaylistId,
    maxResults,
  });

  return (itemsRes.data.items ?? []).map((item) => ({
    videoId: item.snippet?.resourceId?.videoId ?? '',
    title: item.snippet?.title ?? '(無題)',
    publishedAt: item.snippet?.publishedAt ?? '',
  }));
}

export type VideoAnalytics = {
  videoId: string;
  views: number;
  averageViewPercentage: number;
  estimatedMinutesWatched: number;
  topTrafficSources: { source: string; views: number }[];
};

function toDateOnly(iso: string): string {
  return iso.slice(0, 10);
}

function analyticsIds(): string {
  const channelId = process.env.YOUTUBE_CHANNEL_ID;
  return channelId ? `channel==${channelId}` : 'channel==MINE';
}

// videoの公開日から今日までの累計データを取得する
export async function getVideoAnalytics(videoId: string, publishedAtISO: string): Promise<VideoAnalytics> {
  const analytics = await youtubeAnalyticsClient();
  const startDate = toDateOnly(publishedAtISO);
  const endDate = toDateOnly(new Date().toISOString());
  const ids = analyticsIds();

  const summaryRes = await analytics.reports.query({
    ids,
    startDate,
    endDate,
    metrics: 'views,averageViewPercentage,estimatedMinutesWatched',
    filters: `video==${videoId}`,
  });
  const row = summaryRes.data.rows?.[0] ?? [0, 0, 0];

  const trafficRes = await analytics.reports.query({
    ids,
    startDate,
    endDate,
    metrics: 'views',
    dimensions: 'insightTrafficSourceType',
    filters: `video==${videoId}`,
    sort: '-views',
    maxResults: 5,
  });
  const topTrafficSources = (trafficRes.data.rows ?? []).map((r) => ({
    source: String(r[0]),
    views: Number(r[1]),
  }));

  return {
    videoId,
    views: Number(row[0] ?? 0),
    averageViewPercentage: Number(row[1] ?? 0),
    estimatedMinutesWatched: Number(row[2] ?? 0),
    topTrafficSources,
  };
}
