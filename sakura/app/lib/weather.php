<?php
declare(strict_types=1);

// ===== 仙台の今日の天気（気象庁の予報 JSON。API キー不要） =====

/** @return array{weatherText: string, maxTemp: ?string, minTemp: ?string} */
function sendai_weather_today(): array
{
    $office = (string)config('weather.office');
    $area = (string)config('weather.area');
    $amedas = (string)config('weather.amedas');
    [$shortTerm, $weekly] = http_json('GET', "https://www.jma.go.jp/bosai/forecast/data/forecast/{$office}.json");
    $today = jst_date_string(0);

    $weatherSeries = $shortTerm['timeSeries'][0];
    $weathers = area_values($weatherSeries, $area, 'weathers');
    $weatherRaw = value_on_date($weatherSeries['timeDefines'], $weathers, $today) ?? ($weathers[0] ?? null);
    $weatherText = preg_replace('/[\s　]+/u', '', $weatherRaw ?? '不明');

    // 週間予報（$weekly）の最高/最低は当日分が空欄のことが多いため、当日分は短期予報の temps を優先する。
    // 各時刻が 00 時のものが最低気温、それ以外（9時等）が最高気温
    $maxTemp = null;
    $minTemp = null;
    $tempSeries = $shortTerm['timeSeries'][2] ?? null;
    if ($tempSeries) {
        $temps = area_values($tempSeries, $amedas, 'temps');
        foreach ($tempSeries['timeDefines'] as $i => $td) {
            if (substr($td, 0, 10) !== $today) {
                continue;
            }
            $value = ($temps[$i] ?? '') !== '' ? $temps[$i] : null;
            if ((int)substr($td, 11, 2) === 0) {
                $minTemp = $value;
            } else {
                $maxTemp = $value;
            }
        }
    }
    if ($maxTemp === null && $minTemp === null) {
        $weeklyTemps = $weekly['timeSeries'][1];
        $maxTemp = value_on_date($weeklyTemps['timeDefines'], area_values($weeklyTemps, $amedas, 'tempsMax'), $today) ?: null;
        $minTemp = value_on_date($weeklyTemps['timeDefines'], area_values($weeklyTemps, $amedas, 'tempsMin'), $today) ?: null;
    }
    return ['weatherText' => $weatherText, 'maxTemp' => $maxTemp, 'minTemp' => $minTemp];
}

function area_values(array $series, string $code, string $field): array
{
    foreach ($series['areas'] as $a) {
        if (($a['area']['code'] ?? null) === $code) {
            return $a[$field] ?? [];
        }
    }
    return [];
}

/** timeDefines（+09:00 付き）のうち日付が一致する位置の値 */
function value_on_date(array $timeDefines, array $values, string $date): ?string
{
    foreach ($timeDefines as $i => $td) {
        if (substr($td, 0, 10) === $date) {
            return $values[$i] ?? null;
        }
    }
    return null;
}

function format_weather_line(array $w): string
{
    $max = $w['maxTemp'] !== null ? "最高{$w['maxTemp']}℃" : '最高気温不明';
    $min = $w['minTemp'] !== null ? "最低{$w['minTemp']}℃" : '最低気温不明';
    return "{$w['weatherText']} {$max}/{$min}";
}
