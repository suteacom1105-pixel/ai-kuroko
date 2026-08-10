// 気象庁(JMA)の防災情報XML/JSON配信エンドポイントを利用。APIキー不要。
// 参考エンドポイント形式: https://www.jma.go.jp/bosai/forecast/data/forecast/{予報区コード}.json
import { jstDatePart, jstDateString } from './date';

type JmaArea = { area: { name: string; code: string } } & Record<string, unknown>;
type JmaTimeSeries = { timeDefines: string[]; areas: JmaArea[] };
type JmaForecast = { publishingOffice: string; reportDatetime: string; timeSeries: JmaTimeSeries[] };

export type TodayWeather = {
  weatherText: string;
  maxTemp: string | null;
  minTemp: string | null;
};

function forecastUrl(officeCode: string): string {
  return `https://www.jma.go.jp/bosai/forecast/data/forecast/${officeCode}.json`;
}

function findByDate<T>(timeDefines: string[], values: T[], targetDate: string): T | undefined {
  const idx = timeDefines.findIndex((td) => jstDatePart(td) === targetDate);
  return idx >= 0 ? values[idx] : undefined;
}

export async function getSendaiWeatherToday(): Promise<TodayWeather> {
  const officeCode = process.env.JMA_OFFICE_CODE ?? '040000';
  const areaCode = process.env.JMA_AREA_CODE ?? '040010';
  const amedasCode = process.env.JMA_AMEDAS_CODE ?? '34392';

  const res = await fetch(forecastUrl(officeCode));
  if (!res.ok) {
    throw new Error(`JMA予報の取得に失敗しました (status: ${res.status})`);
  }
  const data = (await res.json()) as [JmaForecast, JmaForecast];
  const todayDate = jstDateString(0);

  const [shortTerm, weekly] = data;

  const weatherSeries = shortTerm.timeSeries[0];
  const weatherArea = weatherSeries.areas.find((a) => a.area.code === areaCode);
  const weathers = (weatherArea?.weathers as string[] | undefined) ?? [];
  const weatherRaw = findByDate(weatherSeries.timeDefines, weathers, todayDate) ?? weathers[0];
  const weatherText = (weatherRaw ?? '不明').replace(/\s+/g, '').replace(/　/g, '');

  const weeklyTempSeries = weekly.timeSeries[1];
  const tempArea = weeklyTempSeries.areas.find((a) => a.area.code === amedasCode);
  const tempsMax = (tempArea?.tempsMax as string[] | undefined) ?? [];
  const tempsMin = (tempArea?.tempsMin as string[] | undefined) ?? [];

  let maxTemp = findByDate(weeklyTempSeries.timeDefines, tempsMax, todayDate) ?? null;
  let minTemp = findByDate(weeklyTempSeries.timeDefines, tempsMin, todayDate) ?? null;
  if (maxTemp === '') maxTemp = null;
  if (minTemp === '') minTemp = null;

  return { weatherText, maxTemp, minTemp };
}

export function formatWeatherLine(weather: TodayWeather): string {
  const max = weather.maxTemp ? `最高${weather.maxTemp}℃` : '最高気温不明';
  const min = weather.minTemp ? `最低${weather.minTemp}℃` : '最低気温不明';
  return `${weather.weatherText} ${max}/${min}`;
}
