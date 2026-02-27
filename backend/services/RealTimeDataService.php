<?php
/**
 * RealTimeDataService — Agregador de dados em tempo real de Fortaleza
 * 
 * Fontes 100% gratuitas (sem key):
 * - Open-Meteo: Clima + qualidade do ar
 * - Brasil API: Feriados, IBGE, FIPE
 * - IBGE: Dados demográficos e estatísticos
 * - Fortaleza CKAN: Dados públicos da prefeitura
 * 
 * Fonte com key do usuário:
 * - Google Maps JS API: Traffic layer (frontend-only, key pública)
 */

require_once __DIR__ . '/WeatherService.php';
require_once __DIR__ . '/UPAService.php';
require_once __DIR__ . '/NewsService.php';
require_once __DIR__ . '/AMCTransitService.php';
require_once __DIR__ . '/DefesaCivilService.php';
require_once __DIR__ . '/HistoryService.php';

class RealTimeDataService {
    private $db;
    private $weather;

    // APIs públicas brasileiras (sem key)
    private $brasilApiBase  = 'https://brasilapi.com.br/api';
    private $ibgeApiBase    = 'https://servicodados.ibge.gov.br/api/v3';

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->weather = new WeatherService();
    }

    /**
     * Buscar TODOS os dados em tempo real de uma vez
     * Chamado pelo frontend na inicialização e a cada refresh
     */
    public function getAllRealTimeData() {
        $startTime = microtime(true);

        // Executar buscas em paralelo (via sequential aqui, mas rápidas)
        $weather    = $this->weather->getCurrentWeather();
        $airQuality = $this->weather->getAirQuality();
        $holidays   = $this->getUpcomingHolidays();
        $ibgeNews   = $this->getIBGELatestNews();

        // UPAs e Notícias
        $upaService = new UPAService();
        $upas = $upaService->getAllUPAs();

        $newsService = new NewsService();
        // Buscar notícias frescas (com fallback para cache)
        $news = [];
        try {
            $freshNews = $newsService->getNews(null, 15);
            if (!empty($freshNews)) {
                $news = $freshNews;
            } else {
                $news = $newsService->getCachedNews(15);
            }
        } catch (Exception $e) {
            error_log("RealTime news error: " . $e->getMessage());
            $news = $newsService->getCachedNews(15);
        }

        // Trânsito AMC e Defesa Civil
        $transitData = null;
        $defesaCivilData = null;
        try {
            $amc = new AMCTransitService();
            $transitData = $amc->getCurrentIncidents();
        } catch (Exception $e) {
            error_log("RealTime AMC error: " . $e->getMessage());
        }

        try {
            $dc = new DefesaCivilService();
            $defesaCivilData = $dc->getActiveAlerts();
        } catch (Exception $e) {
            error_log("RealTime DefesaCivil error: " . $e->getMessage());
        }

        $duration = round((microtime(true) - $startTime) * 1000);

        // Gerar eventos automáticos baseados nos dados
        $autoEvents = $this->generateAutoEvents($weather, $airQuality, $holidays);

        // Salvar snapshot para histórico (throttled - a cada 1h)
        try {
            $history = new HistoryService();
            $history->saveAllSnapshots([
                'weather' => $weather,
                'upas'    => $upas,
            ]);
        } catch (Exception $e) {
            error_log("RealTime history snapshot error: " . $e->getMessage());
        }

        return [
            'weather'       => $weather,
            'air_quality'   => $airQuality,
            'holidays'      => $holidays,
            'ibge_news'     => $ibgeNews,
            'upas'          => $upas,
            'news'          => $news,
            'transit'       => $transitData,
            'defesa_civil'  => $defesaCivilData,
            'auto_events'   => $autoEvents,
            'feeds_status'  => $this->getFeedsStatus($weather, $airQuality, $upas),
            'fetched_in'    => $duration,
            'updated_at'    => date('c'),
        ];
    }

    /**
     * Feriados próximos (BR + Ceará)
     */
    public function getUpcomingHolidays() {
        $year = date('Y');
        $data = $this->curlGet("{$this->brasilApiBase}/feriados/v1/{$year}");
        if (!$data) return [];

        $today = date('Y-m-d');
        $upcoming = [];

        foreach ($data as $h) {
            if ($h['date'] >= $today) {
                $upcoming[] = [
                    'date' => $h['date'],
                    'name' => $h['name'],
                    'type' => $h['type'] ?? 'national',
                ];
            }
            if (count($upcoming) >= 5) break;
        }

        // Adicionar feriados locais de Fortaleza (fixos)
        $localHolidays = [
            ['date' => "{$year}-03-13", 'name' => 'Dia de Fortaleza (Aniversário)',     'type' => 'municipal'],
            ['date' => "{$year}-03-19", 'name' => 'São José (Padroeiro do Ceará)',      'type' => 'estadual'],
            ['date' => "{$year}-03-25", 'name' => 'Abolição da escravidão no Ceará',     'type' => 'estadual'],
            ['date' => "{$year}-08-15", 'name' => 'Nossa Senhora da Assunção (Fortaleza)', 'type' => 'municipal'],
        ];

        foreach ($localHolidays as $lh) {
            if ($lh['date'] >= $today) {
                $upcoming[] = $lh;
            }
        }

        // Ordenar por data
        usort($upcoming, fn($a, $b) => strcmp($a['date'], $b['date']));

        return array_slice($upcoming, 0, 5);
    }

    /**
     * Últimas notícias/releases do IBGE sobre Ceará
     */
    public function getIBGELatestNews() {
        // IBGE Aggregates API — buscar notícias/releases do Ceará
        $data = $this->curlGet("https://servicodados.ibge.gov.br/api/v3/noticias/?qtd=5&busca=Fortaleza%20Ceara");

        if (!$data || !isset($data['items'])) return [];

        $news = [];
        foreach ($data['items'] as $item) {
            $news[] = [
                'id'         => $item['id'] ?? null,
                'title'      => $item['titulo'] ?? '',
                'intro'      => mb_substr(strip_tags($item['introducao'] ?? ''), 0, 200),
                'date'       => $item['data_publicacao'] ?? '',
                'link'       => $item['link'] ?? '',
                'source'     => 'IBGE',
            ];
        }

        return $news;
    }

    /**
     * Dados demográficos de Fortaleza (IBGE)
     */
    public function getFortalezaDemographics() {
        // IBGE código do município de Fortaleza: 2304400
        $data = $this->curlGet("https://servicodados.ibge.gov.br/api/v1/pesquisas/indicadores/0/resultados/2304400");
        return $data;
    }

    /**
     * Gerar eventos automáticos baseados nos dados em tempo real
     * Estes eventos aparecem no mapa sem depender do chat
     */
    public function generateAutoEvents($weather, $airQuality, $holidays) {
        $events = [];

        // === Eventos de clima ===
        if ($weather && !empty($weather['alerts'])) {
            foreach ($weather['alerts'] as $alert) {
                $events[] = [
                    'title'        => $alert['title'],
                    'description'  => $alert['desc'],
                    'category'     => 'clima',
                    'source'       => 'weather_api',
                    'severity'     => $alert['severity'],
                    'icon'         => $alert['icon'],
                    'neighborhood' => 'Fortaleza (geral)',
                    'lat'          => -3.7319,
                    'lng'          => -38.5267,
                    'auto'         => true,
                ];
            }
        }

        // Evento de clima atual (sempre)
        if ($weather && isset($weather['current'])) {
            $cur = $weather['current'];
            $events[] = [
                'title'        => "{$cur['weather_icon']} {$cur['weather_desc']} — {$cur['temperature']}°C",
                'description'  => "Sensação: {$cur['feels_like']}°C | Umidade: {$cur['humidity']}% | Vento: {$cur['wind_speed']}km/h | UV: {$cur['uv_index']}",
                'category'     => 'clima',
                'source'       => 'weather_api',
                'severity'     => $cur['weather_severity'],
                'icon'         => $cur['weather_icon'],
                'neighborhood' => 'Fortaleza',
                'lat'          => -3.7319,
                'lng'          => -38.5267,
                'auto'         => true,
            ];
        }

        // === Qualidade do ar ===
        if ($airQuality && $airQuality['aqi'] > 100) {
            $events[] = [
                'title'        => "⚠️ Qualidade do ar: {$airQuality['aqi_label']}",
                'description'  => "AQI: {$airQuality['aqi']} | PM2.5: {$airQuality['pm2_5']}µg/m³ | PM10: {$airQuality['pm10']}µg/m³",
                'category'     => 'meio_ambiente',
                'source'       => 'air_quality_api',
                'severity'     => $airQuality['aqi'] > 150 ? 'alto' : 'moderado',
                'icon'         => '🏭',
                'neighborhood' => 'Fortaleza (geral)',
                'lat'          => -3.7319,
                'lng'          => -38.5267,
                'auto'         => true,
            ];
        }

        // === Feriado hoje ===
        $today = date('Y-m-d');
        if ($holidays) {
            foreach ($holidays as $h) {
                if ($h['date'] === $today) {
                    $events[] = [
                        'title'        => "🎉 Feriado: {$h['name']}",
                        'description'  => "Hoje é {$h['name']}. Comércio e serviços públicos podem ter horário alterado.",
                        'category'     => 'eventos',
                        'source'       => 'holidays_api',
                        'severity'     => 'ok',
                        'icon'         => '🎉',
                        'neighborhood' => 'Fortaleza',
                        'lat'          => -3.7319,
                        'lng'          => -38.5267,
                        'auto'         => true,
                    ];
                }
            }
        }

        // === Pontos fixos urbanos (seed data para mapa não ficar vazio) ===
        $events = array_merge($events, $this->getUrbanSeedEvents());

        // Salvar auto-events no banco
        $this->saveAutoEvents($events);

        return $events;
    }

    /**
     * Pontos fixos de interesse — sempre visíveis no mapa
     */
    private function getUrbanSeedEvents() {
        return [
            // Trânsito — pontos de congestionamento frequente
            ['title' => '🚦 Av. Washington Soares — Fluxo intenso', 'description' => 'Via com alto volume de tráfego, especialmente horários de pico.', 'category' => 'transito', 'source' => 'seed', 'severity' => 'moderado', 'icon' => '🚦', 'neighborhood' => 'edson queiroz', 'lat' => -3.7710, 'lng' => -38.4780, 'auto' => true],
            ['title' => '🚦 Av. Bezerra de Menezes — Corredor de ônibus', 'description' => 'Corredor exclusivo de transporte público. Fluxo constante.', 'category' => 'transito', 'source' => 'seed', 'severity' => 'ok', 'icon' => '🚌', 'neighborhood' => 'São Gerardo', 'lat' => -3.7290, 'lng' => -38.5510, 'auto' => true],
            ['title' => '🚦 BR-116 / Av. Aguanambi — Tráfego pesado', 'description' => 'Ponto de convergência de fluxo rodoviário e urbano.', 'category' => 'transito', 'source' => 'seed', 'severity' => 'moderado', 'icon' => '🚛', 'neighborhood' => 'Aeroporto', 'lat' => -3.7760, 'lng' => -38.5310, 'auto' => true],
            ['title' => '🚦 Rotatória da Parangaba — Nó viário', 'description' => 'Rotatória central com integração metrô/ônibus.', 'category' => 'transito', 'source' => 'seed', 'severity' => 'moderado', 'icon' => '🔄', 'neighborhood' => 'Parangaba', 'lat' => -3.7750, 'lng' => -38.5530, 'auto' => true],

            // Saúde — hospitais principais
            ['title' => '🏥 Hospital IJF — Emergência 24h', 'description' => 'Instituto Dr. José Frota. Maior emergência pública de Fortaleza.', 'category' => 'saude', 'source' => 'seed', 'severity' => 'ok', 'icon' => '🏥', 'neighborhood' => 'Centro', 'lat' => -3.7244, 'lng' => -38.5253, 'auto' => true],
            ['title' => '🏥 Hospital Geral de Fortaleza', 'description' => 'HGF — Hospital referência em cirurgias e emergências.', 'category' => 'saude', 'source' => 'seed', 'severity' => 'ok', 'icon' => '🏥', 'neighborhood' => 'Papicu', 'lat' => -3.7380, 'lng' => -38.4940, 'auto' => true],
            ['title' => '🏥 Hospital Waldemar Alcântara', 'description' => 'Emergência e internação. Referência em Messejana.', 'category' => 'saude', 'source' => 'seed', 'severity' => 'ok', 'icon' => '🏥', 'neighborhood' => 'Messejana', 'lat' => -3.8310, 'lng' => -38.4920, 'auto' => true],

            // Cultura/Lazer
            ['title' => '🏖 Praia de Iracema — Ponto turístico', 'description' => 'Região turística com bares, restaurantes e a Ponte dos Ingleses.', 'category' => 'cultura', 'source' => 'seed', 'severity' => 'ok', 'icon' => '🏖', 'neighborhood' => 'Praia de Iracema', 'lat' => -3.7210, 'lng' => -38.5140, 'auto' => true],
            ['title' => '🏖 Beira Mar — Calçadão', 'description' => 'Av. Beira Mar. Feirinha noturna, área de lazer e esportes.', 'category' => 'cultura', 'source' => 'seed', 'severity' => 'ok', 'icon' => '🌊', 'neighborhood' => 'Meireles', 'lat' => -3.7240, 'lng' => -38.5030, 'auto' => true],
            ['title' => '🎭 Centro Dragão do Mar de Arte e Cultura', 'description' => 'Complexo cultural: cinema, teatro, museus, planetário.', 'category' => 'cultura', 'source' => 'seed', 'severity' => 'ok', 'icon' => '🎭', 'neighborhood' => 'Praia de Iracema', 'lat' => -3.7228, 'lng' => -38.5133, 'auto' => true],
            ['title' => '🛍 Shopping Iguatemi — Comércio', 'description' => 'Principal shopping de Fortaleza. Edson Queiroz.', 'category' => 'economia', 'source' => 'seed', 'severity' => 'ok', 'icon' => '🛍', 'neighborhood' => 'Edson Queiroz', 'lat' => -3.7670, 'lng' => -38.4790, 'auto' => true],

            // Educação
            ['title' => '🎓 UFC — Universidade Federal do Ceará', 'description' => 'Campus do Benfica e Pici. Principal universidade do estado.', 'category' => 'educacao', 'source' => 'seed', 'severity' => 'ok', 'icon' => '🎓', 'neighborhood' => 'Benfica', 'lat' => -3.7410, 'lng' => -38.5380, 'auto' => true],
            ['title' => '🎓 UNIFOR — Universidade de Fortaleza', 'description' => 'Campus na Washington Soares. Maior universidade privada do Norte/Nordeste.', 'category' => 'educacao', 'source' => 'seed', 'severity' => 'ok', 'icon' => '🎓', 'neighborhood' => 'Edson Queiroz', 'lat' => -3.7690, 'lng' => -38.4770, 'auto' => true],

            // Segurança / Serviços
            ['title' => '🏛 Prefeitura de Fortaleza — Paço Municipal', 'description' => 'Sede da administração municipal.', 'category' => 'servicos_publicos', 'source' => 'seed', 'severity' => 'ok', 'icon' => '🏛', 'neighborhood' => 'Centro', 'lat' => -3.7260, 'lng' => -38.5265, 'auto' => true],

            // Infraestrutura
            ['title' => '✈️ Aeroporto Pinto Martins', 'description' => 'Aeroporto Internacional de Fortaleza.', 'category' => 'infraestrutura', 'source' => 'seed', 'severity' => 'ok', 'icon' => '✈️', 'neighborhood' => 'Aeroporto', 'lat' => -3.7763, 'lng' => -38.5326, 'auto' => true],
            ['title' => '🚇 Estação Parangaba - Metrô/VLT', 'description' => 'Estação de integração metrô + VLT + ônibus.', 'category' => 'transito', 'source' => 'seed', 'severity' => 'ok', 'icon' => '🚇', 'neighborhood' => 'Parangaba', 'lat' => -3.7743, 'lng' => -38.5546, 'auto' => true],

            // Esporte
            ['title' => '⚽ Arena Castelão', 'description' => 'Estádio Governador Plácido Castelo. Capacidade: 63.903. Casa de Ceará e Fortaleza.', 'category' => 'esporte', 'source' => 'seed', 'severity' => 'ok', 'icon' => '⚽', 'neighborhood' => 'Passaré', 'lat' => -3.8070, 'lng' => -38.5222, 'auto' => true],
        ];
    }

    /**
     * Salvar auto-events no banco
     */
    private function saveAutoEvents($events) {
        try {
            // Limpar auto-events antigos (> 6h, exceto seeds que duram 24h)
            $this->db->exec("
                DELETE FROM city_events 
                WHERE source IN ('weather_api', 'air_quality_api', 'holidays_api')
                AND created_at < DATE_SUB(NOW(), INTERVAL 6 HOUR)
            ");

            // Seeds: atualizar em vez de duplicar
            $this->db->exec("
                DELETE FROM city_events 
                WHERE source = 'seed'
                AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");

            foreach ($events as $ev) {
                // Verificar se já existe evento similar recente
                $stmt = $this->db->prepare("
                    SELECT id FROM city_events 
                    WHERE title = ? AND source = ? 
                    AND created_at > DATE_SUB(NOW(), INTERVAL 3 HOUR)
                    LIMIT 1
                ");
                $stmt->execute([$ev['title'], $ev['source'] ?? 'auto']);
                
                if (!$stmt->fetch()) {
                    $stmt2 = $this->db->prepare("
                        INSERT INTO city_events (title, description, category, source, neighborhood, latitude, longitude, mention_count, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)
                    ");
                    $stmt2->execute([
                        $ev['title'],
                        $ev['description'] ?? '',
                        $ev['category'] ?? 'outros',
                        $ev['source'] ?? 'auto',
                        $ev['neighborhood'] ?? null,
                        $ev['lat'] ?? null,
                        $ev['lng'] ?? null,
                    ]);
                }
            }
        } catch (Exception $e) {
            error_log("AutoEvent save error: " . $e->getMessage());
        }
    }

    /**
     * Status dos feeds de dados
     */
    private function getFeedsStatus($weather, $airQuality, $upas = null) {
        return [
            ['name' => 'Open-Meteo (Clima)',       'status' => $weather ? 'online' : 'offline',    'icon' => '🌤', 'free' => true],
            ['name' => 'Open-Meteo (Ar)',           'status' => $airQuality ? 'online' : 'offline', 'icon' => '🏭', 'free' => true],
            ['name' => 'UPAs Fortaleza (CKAN)',    'status' => !empty($upas) ? 'online' : 'offline', 'icon' => '🏥', 'free' => true],
            ['name' => 'Google News (Notícias)',    'status' => 'online',                            'icon' => '📰', 'free' => true],
            ['name' => 'Brasil API',                'status' => 'online',                            'icon' => '🇧🇷', 'free' => true],
            ['name' => 'IBGE Notícias',             'status' => 'online',                            'icon' => '📊', 'free' => true],
            ['name' => 'Fortaleza Open Data (CKAN)', 'status' => 'online',                           'icon' => '🏛', 'free' => true],
            ['name' => 'Google Maps Traffic',       'status' => 'online',                            'icon' => '🗺', 'free' => false],
            ['name' => 'Groq AI (LLaMA)',           'status' => 'online',                            'icon' => '🤖', 'free' => true],
            ['name' => 'SSPDS/CE (Segurança)',      'status' => 'online',                            'icon' => '🔒', 'free' => true],
        ];
    }

    private function curlGet($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: City085-Monitor/1.0',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("RealTimeDataService error ({$httpCode}): {$url}");
            return null;
        }
        return json_decode($response, true);
    }
}
