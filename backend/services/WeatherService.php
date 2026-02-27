<?php
/**
 * WeatherService — Dados climáticos em tempo real de Fortaleza
 * Fonte: Open-Meteo API (100% gratuita, sem key)
 * + Qualidade do ar
 */

class WeatherService {
    private $baseUrl = 'https://api.open-meteo.com/v1';
    private $lat = -3.7319;
    private $lng = -38.5267;

    /**
     * Clima atual de Fortaleza
     */
    public function getCurrentWeather() {
        $params = http_build_query([
            'latitude'        => $this->lat,
            'longitude'       => $this->lng,
            'current'         => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,rain,weather_code,wind_speed_10m,wind_direction_10m,wind_gusts_10m,surface_pressure,uv_index',
            'hourly'          => 'temperature_2m,precipitation_probability,weather_code,wind_speed_10m,uv_index',
            'daily'           => 'temperature_2m_max,temperature_2m_min,sunrise,sunset,precipitation_sum,precipitation_probability_max,weather_code,uv_index_max',
            'timezone'        => 'America/Fortaleza',
            'forecast_days'   => 3,
        ]);

        $data = $this->curlGet("{$this->baseUrl}/forecast?{$params}");
        if (!$data) return null;

        $current = $data['current'] ?? [];
        $hourly  = $data['hourly'] ?? [];
        $daily   = $data['daily'] ?? [];

        // Traduzir weather code
        $weatherDesc = $this->translateWeatherCode($current['weather_code'] ?? 0);

        return [
            'current' => [
                'temperature'      => $current['temperature_2m'] ?? null,
                'feels_like'       => $current['apparent_temperature'] ?? null,
                'humidity'         => $current['relative_humidity_2m'] ?? null,
                'precipitation'    => $current['precipitation'] ?? 0,
                'rain'             => $current['rain'] ?? 0,
                'weather_code'     => $current['weather_code'] ?? 0,
                'weather_desc'     => $weatherDesc['desc'],
                'weather_icon'     => $weatherDesc['icon'],
                'weather_severity' => $weatherDesc['severity'],
                'wind_speed'       => $current['wind_speed_10m'] ?? null,
                'wind_direction'   => $current['wind_direction_10m'] ?? null,
                'wind_gusts'       => $current['wind_gusts_10m'] ?? null,
                'pressure'         => $current['surface_pressure'] ?? null,
                'uv_index'         => $current['uv_index'] ?? null,
            ],
            'hourly_forecast' => $this->formatHourlyForecast($hourly),
            'daily_forecast'  => $this->formatDailyForecast($daily),
            'alerts'          => $this->detectWeatherAlerts($current, $daily),
            'updated_at'      => date('c'),
            'source'          => 'Open-Meteo',
        ];
    }

    /**
     * Qualidade do ar
     */
    public function getAirQuality() {
        $params = http_build_query([
            'latitude'  => $this->lat,
            'longitude' => $this->lng,
            'current'   => 'pm10,pm2_5,carbon_monoxide,nitrogen_dioxide,sulphur_dioxide,ozone,us_aqi',
            'timezone'  => 'America/Fortaleza',
        ]);

        // Endpoint correto: subdomínio separado (air-quality-api.open-meteo.com)
        $data = $this->curlGet("https://air-quality-api.open-meteo.com/v1/air-quality?{$params}");
        if (!$data) return null;

        $current = $data['current'] ?? [];
        $aqi = $current['us_aqi'] ?? 0;

        return [
            'aqi'           => $aqi,
            'aqi_label'     => $this->aqiLabel($aqi),
            'aqi_color'     => $this->aqiColor($aqi),
            'pm2_5'         => $current['pm2_5'] ?? null,
            'pm10'          => $current['pm10'] ?? null,
            'co'            => $current['carbon_monoxide'] ?? null,
            'no2'           => $current['nitrogen_dioxide'] ?? null,
            'so2'           => $current['sulphur_dioxide'] ?? null,
            'o3'            => $current['ozone'] ?? null,
            'updated_at'    => date('c'),
        ];
    }

    /**
     * Detectar alertas climáticos que viram eventos no mapa
     */
    public function detectWeatherAlerts($current, $daily) {
        $alerts = [];

        // Chuva forte
        $rain = $current['rain'] ?? 0;
        if ($rain > 10) {
            $alerts[] = [
                'type'     => 'heavy_rain',
                'severity' => $rain > 30 ? 'alto' : 'moderado',
                'title'    => 'Chuva forte em Fortaleza',
                'desc'     => "Precipitação de {$rain}mm detectada. Risco de alagamentos.",
                'icon'     => '🌧',
                'category' => 'clima',
            ];
        }

        // Temperatura extrema
        $temp = $current['temperature_2m'] ?? 28;
        if ($temp > 35) {
            $alerts[] = [
                'type'     => 'extreme_heat',
                'severity' => $temp > 38 ? 'alto' : 'moderado',
                'title'    => "Calor extremo: {$temp}°C",
                'desc'     => 'Temperatura elevada. Hidrate-se e evite exposição solar.',
                'icon'     => '🔥',
                'category' => 'clima',
            ];
        }

        // Vento forte
        $wind = $current['wind_speed_10m'] ?? 0;
        if ($wind > 40) {
            $alerts[] = [
                'type'     => 'strong_wind',
                'severity' => $wind > 60 ? 'alto' : 'moderado',
                'title'    => "Ventos fortes: {$wind}km/h",
                'desc'     => 'Cuidado com estruturas frágeis e na praia.',
                'icon'     => '💨',
                'category' => 'clima',
            ];
        }

        // UV alto
        $uv = $current['uv_index'] ?? 0;
        if ($uv > 10) {
            $alerts[] = [
                'type'     => 'high_uv',
                'severity' => $uv > 12 ? 'alto' : 'moderado',
                'title'    => "Índice UV extremo: {$uv}",
                'desc'     => 'Use protetor solar e evite exposição entre 10h-16h.',
                'icon'     => '☀️',
                'category' => 'clima',
            ];
        }

        return $alerts;
    }

    private function formatHourlyForecast($hourly) {
        if (empty($hourly['time'])) return [];

        $forecast = [];
        $now = time();

        for ($i = 0; $i < min(24, count($hourly['time'])); $i++) {
            $time = strtotime($hourly['time'][$i]);
            if ($time < $now) continue;

            $code = $hourly['weather_code'][$i] ?? 0;
            $desc = $this->translateWeatherCode($code);

            $forecast[] = [
                'time'         => $hourly['time'][$i],
                'hour'         => date('H:i', $time),
                'temperature'  => $hourly['temperature_2m'][$i] ?? null,
                'precip_prob'  => $hourly['precipitation_probability'][$i] ?? 0,
                'wind_speed'   => $hourly['wind_speed_10m'][$i] ?? null,
                'uv_index'     => $hourly['uv_index'][$i] ?? null,
                'weather_icon' => $desc['icon'],
                'weather_desc' => $desc['desc'],
            ];

            if (count($forecast) >= 12) break;
        }

        return $forecast;
    }

    private function formatDailyForecast($daily) {
        if (empty($daily['time'])) return [];

        $forecast = [];
        for ($i = 0; $i < count($daily['time']); $i++) {
            $code = $daily['weather_code'][$i] ?? 0;
            $desc = $this->translateWeatherCode($code);

            $forecast[] = [
                'date'          => $daily['time'][$i],
                'weekday'       => $this->weekdayPt(date('N', strtotime($daily['time'][$i]))),
                'temp_max'      => $daily['temperature_2m_max'][$i] ?? null,
                'temp_min'      => $daily['temperature_2m_min'][$i] ?? null,
                'sunrise'       => $daily['sunrise'][$i] ?? null,
                'sunset'        => $daily['sunset'][$i] ?? null,
                'precip_sum'    => $daily['precipitation_sum'][$i] ?? 0,
                'precip_prob'   => $daily['precipitation_probability_max'][$i] ?? 0,
                'uv_max'        => $daily['uv_index_max'][$i] ?? null,
                'weather_icon'  => $desc['icon'],
                'weather_desc'  => $desc['desc'],
            ];
        }

        return $forecast;
    }

    private function translateWeatherCode($code) {
        $codes = [
            0  => ['desc' => 'Céu limpo',           'icon' => '☀️', 'severity' => 'ok'],
            1  => ['desc' => 'Poucas nuvens',        'icon' => '🌤', 'severity' => 'ok'],
            2  => ['desc' => 'Parcialmente nublado',  'icon' => '⛅', 'severity' => 'ok'],
            3  => ['desc' => 'Nublado',              'icon' => '☁️', 'severity' => 'ok'],
            45 => ['desc' => 'Nevoeiro',             'icon' => '🌫', 'severity' => 'moderado'],
            48 => ['desc' => 'Nevoeiro com geada',   'icon' => '🌫', 'severity' => 'moderado'],
            51 => ['desc' => 'Garoa leve',           'icon' => '🌦', 'severity' => 'ok'],
            53 => ['desc' => 'Garoa moderada',       'icon' => '🌦', 'severity' => 'ok'],
            55 => ['desc' => 'Garoa intensa',        'icon' => '🌧', 'severity' => 'moderado'],
            61 => ['desc' => 'Chuva leve',           'icon' => '🌧', 'severity' => 'ok'],
            63 => ['desc' => 'Chuva moderada',       'icon' => '🌧', 'severity' => 'moderado'],
            65 => ['desc' => 'Chuva forte',          'icon' => '🌧', 'severity' => 'alto'],
            80 => ['desc' => 'Pancadas leves',       'icon' => '🌦', 'severity' => 'ok'],
            81 => ['desc' => 'Pancadas moderadas',   'icon' => '🌧', 'severity' => 'moderado'],
            82 => ['desc' => 'Pancadas fortes',      'icon' => '⛈', 'severity' => 'alto'],
            95 => ['desc' => 'Trovoada',             'icon' => '⛈', 'severity' => 'alto'],
            96 => ['desc' => 'Trovoada com granizo', 'icon' => '⛈', 'severity' => 'alto'],
            99 => ['desc' => 'Trovoada forte',       'icon' => '⛈', 'severity' => 'alto'],
        ];
        return $codes[$code] ?? ['desc' => 'Indisponível', 'icon' => '❓', 'severity' => 'ok'];
    }

    private function aqiLabel($aqi) {
        if ($aqi <= 50) return 'Boa';
        if ($aqi <= 100) return 'Moderada';
        if ($aqi <= 150) return 'Insalubre p/ sensíveis';
        if ($aqi <= 200) return 'Insalubre';
        if ($aqi <= 300) return 'Muito insalubre';
        return 'Perigosa';
    }

    private function aqiColor($aqi) {
        if ($aqi <= 50) return '#00d4aa';
        if ($aqi <= 100) return '#ffeb3b';
        if ($aqi <= 150) return '#ff8c00';
        if ($aqi <= 200) return '#ff4444';
        if ($aqi <= 300) return '#9966ff';
        return '#800000';
    }

    private function weekdayPt($n) {
        $days = [1 => 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
        return $days[(int)$n] ?? '';
    }

    private function curlGet($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("WeatherService error ({$httpCode}): {$url}");
            return null;
        }
        return json_decode($response, true);
    }
}
