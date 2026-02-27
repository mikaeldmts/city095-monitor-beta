<?php
/**
 * DefesaCivilService — Integração com Defesa Civil de Fortaleza/CE
 * 
 * Fontes:
 * - Google News RSS — alertas de alagamento, deslizamento, chuvas fortes
 * - Open-Meteo — alertas automáticos baseados em precipitação extrema
 * - CKAN Fortaleza — dados de áreas de risco
 * - FUNCEME (Fundação Cearense de Meteorologia) — previsões severas
 * 
 * Gera alertas automaticamente quando:
 * - Chuva > 30mm/h (risco de alagamento)
 * - Vento > 60km/h
 * - Notícia da Defesa Civil detectada
 * - Áreas de risco + chuva = alerta combinado
 */

class DefesaCivilService {
    private $db;

    // Áreas de risco conhecidas de Fortaleza (sujeitas a alagamento)
    private $riskAreas = [
        [
            'nome'    => 'Rio Cocó — Área de Várzea',
            'bairros' => ['Cocó', 'Edson Queiroz', 'Sabiaguaba', 'Cidade 2000'],
            'tipo'    => 'alagamento',
            'lat'     => -3.7530,
            'lng'     => -38.4770,
            'risco_base' => 0.6,
        ],
        [
            'nome'    => 'Rio Maranguapinho — Marginal',
            'bairros' => ['Bom Jardim', 'Canindezinho', 'Granja Portugal', 'Henrique Jorge'],
            'tipo'    => 'alagamento',
            'lat'     => -3.7850,
            'lng'     => -38.5900,
            'risco_base' => 0.8,
        ],
        [
            'nome'    => 'Av. Aguanambi — Baixos',
            'bairros' => ['Fátima', 'Benfica', 'José Bonifácio'],
            'tipo'    => 'alagamento',
            'lat'     => -3.7450,
            'lng'     => -38.5280,
            'risco_base' => 0.5,
        ],
        [
            'nome'    => 'Praia de Iracema — Ressaca',
            'bairros' => ['Praia de Iracema', 'Centro'],
            'tipo'    => 'ressaca_mar',
            'lat'     => -3.7180,
            'lng'     => -38.5200,
            'risco_base' => 0.4,
        ],
        [
            'nome'    => 'Lagoa da Parangaba',
            'bairros' => ['Parangaba', 'Vila Peri', 'Serrinha'],
            'tipo'    => 'alagamento',
            'lat'     => -3.7770,
            'lng'     => -38.5620,
            'risco_base' => 0.5,
        ],
        [
            'nome'    => 'Morro Santa Terezinha',
            'bairros' => ['Vicente Pinzon', 'Cais do Porto', 'Mucuripe'],
            'tipo'    => 'deslizamento',
            'lat'     => -3.7250,
            'lng'     => -38.4910,
            'risco_base' => 0.7,
        ],
        [
            'nome'    => 'Comunidade do Lagamar',
            'bairros' => ['Aerolândia', 'Damas'],
            'tipo'    => 'alagamento',
            'lat'     => -3.7660,
            'lng'     => -38.5250,
            'risco_base' => 0.7,
        ],
        [
            'nome'    => 'Rio Ceará — Barra do Ceará',
            'bairros' => ['Barra do Ceará', 'Carlito Pamplona', 'Floresta'],
            'tipo'    => 'alagamento',
            'lat'     => -3.6960,
            'lng'     => -38.5750,
            'risco_base' => 0.6,
        ],
        [
            'nome'    => 'Pirambu — Área Costeira',
            'bairros' => ['Pirambu', 'Cristo Redentor'],
            'tipo'    => 'ressaca_mar',
            'lat'     => -3.7100,
            'lng'     => -38.5470,
            'risco_base' => 0.5,
        ],
        [
            'nome'    => 'Jangurussu — Antigo Aterro',
            'bairros' => ['Jangurussu', 'Passaré', 'Cajazeiras'],
            'tipo'    => 'contaminacao',
            'lat'     => -3.8350,
            'lng'     => -38.5120,
            'risco_base' => 0.4,
        ],
    ];

    // Tipos de alerta
    private $alertTypes = [
        'alagamento'    => ['icon' => '🌊', 'color' => '#2196F3', 'label' => 'Risco de Alagamento'],
        'deslizamento'  => ['icon' => '⛰', 'color' => '#795548', 'label' => 'Risco de Deslizamento'],
        'chuva_forte'   => ['icon' => '⛈', 'color' => '#1565C0', 'label' => 'Chuva Forte'],
        'vento_forte'   => ['icon' => '💨', 'color' => '#607D8B', 'label' => 'Vento Forte'],
        'ressaca_mar'   => ['icon' => '🌊', 'color' => '#00BCD4', 'label' => 'Ressaca Marítima'],
        'raios'         => ['icon' => '⚡', 'color' => '#FFC107', 'label' => 'Atividade Elétrica'],
        'contaminacao'  => ['icon' => '☣', 'color' => '#FF9800', 'label' => 'Risco Ambiental'],
        'geral'         => ['icon' => '🚨', 'color' => '#F44336', 'label' => 'Alerta Geral'],
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Buscar todos os alertas ativos
     */
    public function getActiveAlerts() {
        $alerts = [];

        // 1. Alertas automáticos baseados no clima atual
        $weatherAlerts = $this->generateWeatherBasedAlerts();
        $alerts = array_merge($alerts, $weatherAlerts);

        // 2. Notícias da Defesa Civil de Fortaleza
        $newsAlerts = $this->fetchDefesaCivilNews();
        $alerts = array_merge($alerts, $newsAlerts);

        // 3. Dados de alerta da FUNCEME
        $funcemeAlerts = $this->fetchFUNCEME();
        $alerts = array_merge($alerts, $funcemeAlerts);

        // Ordenar por severidade
        usort($alerts, function($a, $b) {
            $order = ['critico' => 0, 'alto' => 1, 'moderado' => 2, 'baixo' => 3];
            return ($order[$a['severity'] ?? 'baixo'] ?? 3) - ($order[$b['severity'] ?? 'baixo'] ?? 3);
        });

        return [
            'alerts'     => $alerts,
            'total'      => count($alerts),
            'risk_areas' => $this->getRiskAreasWithStatus(),
            'updated_at' => date('c'),
            'sources'    => ['Open-Meteo', 'Google News', 'FUNCEME', 'Mapeamento de risco'],
        ];
    }

    /**
     * Alertas automáticos baseados no clima
     */
    private function generateWeatherBasedAlerts() {
        $alerts = [];

        try {
            require_once __DIR__ . '/WeatherService.php';
            $weather = new WeatherService();
            $data = $weather->getCurrentWeather();
            if (!$data) return $alerts;

            $current = $data['current'] ?? [];
            $rain = $current['rain'] ?? 0;
            $wind = $current['wind_speed'] ?? 0;
            $gusts = $current['wind_gusts'] ?? 0;

            // Chuva forte > 20mm
            if ($rain > 20) {
                $severity = $rain > 50 ? 'critico' : ($rain > 30 ? 'alto' : 'moderado');
                $alerts[] = [
                    'type'         => 'chuva_forte',
                    'type_info'    => $this->alertTypes['chuva_forte'],
                    'severity'     => $severity,
                    'title'        => "Chuva forte: {$rain}mm",
                    'description'  => "Precipitação intensa detectada. Risco de alagamentos em áreas baixas. Evite deslocamentos desnecessários.",
                    'lat'          => -3.7319,
                    'lng'          => -38.5267,
                    'source'       => 'Open-Meteo (automático)',
                    'time'         => date('c'),
                ];

                // Ativar áreas de risco de alagamento
                foreach ($this->riskAreas as $area) {
                    if ($area['tipo'] === 'alagamento') {
                        $riskLevel = min(1.0, $area['risco_base'] + ($rain / 100));
                        if ($riskLevel > 0.6) {
                            $alerts[] = [
                                'type'         => 'alagamento',
                                'type_info'    => $this->alertTypes['alagamento'],
                                'severity'     => $riskLevel > 0.8 ? 'alto' : 'moderado',
                                'title'        => "Risco de alagamento: {$area['nome']}",
                                'description'  => "Com chuva de {$rain}mm, área tem risco " . round($riskLevel * 100) . "% de alagamento. Bairros: " . implode(', ', $area['bairros']),
                                'lat'          => $area['lat'],
                                'lng'          => $area['lng'],
                                'neighborhoods' => $area['bairros'],
                                'risk_level'   => $riskLevel,
                                'source'       => 'Mapeamento + Clima',
                                'time'         => date('c'),
                            ];
                        }
                    }
                }
            }

            // Vento forte > 50km/h
            if ($wind > 50 || $gusts > 70) {
                $windVal = max($wind, $gusts);
                $alerts[] = [
                    'type'         => 'vento_forte',
                    'type_info'    => $this->alertTypes['vento_forte'],
                    'severity'     => $windVal > 80 ? 'alto' : 'moderado',
                    'title'        => "Ventos de {$windVal}km/h",
                    'description'  => "Vento forte detectado. Cuidado com estruturas frágeis, placas e árvores. Evite praias.",
                    'lat'          => -3.7319,
                    'lng'          => -38.5267,
                    'source'       => 'Open-Meteo (automático)',
                    'time'         => date('c'),
                ];

                // Ressaca marítima com vento forte
                foreach ($this->riskAreas as $area) {
                    if ($area['tipo'] === 'ressaca_mar') {
                        $alerts[] = [
                            'type'         => 'ressaca_mar',
                            'type_info'    => $this->alertTypes['ressaca_mar'],
                            'severity'     => 'moderado',
                            'title'        => "Risco de ressaca: {$area['nome']}",
                            'description'  => "Ventos fortes podem causar ressaca marítima. Evite banho de mar e beira-mar.",
                            'lat'          => $area['lat'],
                            'lng'          => $area['lng'],
                            'neighborhoods' => $area['bairros'],
                            'source'       => 'Mapeamento + Clima',
                            'time'         => date('c'),
                        ];
                    }
                }
            }

            // Chuva intensa + morro = risco de deslizamento
            if ($rain > 30) {
                foreach ($this->riskAreas as $area) {
                    if ($area['tipo'] === 'deslizamento') {
                        $alerts[] = [
                            'type'         => 'deslizamento',
                            'type_info'    => $this->alertTypes['deslizamento'],
                            'severity'     => $rain > 50 ? 'critico' : 'alto',
                            'title'        => "Risco de deslizamento: {$area['nome']}",
                            'description'  => "Chuva intensa em área de encosta. Moradores devem ficar atentos a sinais de movimentação de terra.",
                            'lat'          => $area['lat'],
                            'lng'          => $area['lng'],
                            'neighborhoods' => $area['bairros'],
                            'source'       => 'Mapeamento + Clima',
                            'time'         => date('c'),
                        ];
                    }
                }
            }

        } catch (Exception $e) {
            error_log("DefesaCivil weather alerts error: " . $e->getMessage());
        }

        return $alerts;
    }

    /**
     * Buscar notícias da Defesa Civil
     */
    private function fetchDefesaCivilNews() {
        $alerts = [];

        $query = urlencode('Defesa Civil Fortaleza OR alagamento Fortaleza OR deslizamento Fortaleza OR enchente Fortaleza');
        $rssUrl = "https://news.google.com/rss/search?q={$query}&hl=pt-BR&gl=BR&ceid=BR:pt-419";

        $xml = $this->curlGet($rssUrl, true);
        if (!$xml) return $alerts;

        try {
            libxml_use_internal_errors(true);
            $feed = simplexml_load_string($xml);
            if (!$feed || !isset($feed->channel->item)) return $alerts;

            $count = 0;
            foreach ($feed->channel->item as $item) {
                if ($count >= 5) break;

                $title = (string)($item->title ?? '');
                $pubDate = (string)($item->pubDate ?? '');
                $link = (string)($item->link ?? '');

                // Verificar se é recente (últimas 24h)
                $pubTimestamp = strtotime($pubDate);
                if ($pubTimestamp && $pubTimestamp < time() - 86400) continue;

                // Detectar tipo
                $type = 'geral';
                if (preg_match('/alagamento|inundação|enchente/i', $title)) $type = 'alagamento';
                elseif (preg_match('/deslizamento|desmoronamento|barreira/i', $title)) $type = 'deslizamento';
                elseif (preg_match('/vento|ventania|tornado/i', $title)) $type = 'vento_forte';
                elseif (preg_match('/ressaca|maré|onda/i', $title)) $type = 'ressaca_mar';
                elseif (preg_match('/raio|relâmpago|descargas/i', $title)) $type = 'raios';

                // Detectar bairros mencionados
                $neighborhoods = $this->detectNeighborhoods($title);

                // Detectar severidade
                $severity = 'moderado';
                if (preg_match('/morte|fatal|grave|crítico|emergência/i', $title)) $severity = 'critico';
                elseif (preg_match('/resgate|evacuação|interdição/i', $title)) $severity = 'alto';

                $typeInfo = $this->alertTypes[$type] ?? $this->alertTypes['geral'];

                $alerts[] = [
                    'type'          => $type,
                    'type_info'     => $typeInfo,
                    'severity'      => $severity,
                    'title'         => strip_tags(html_entity_decode($title)),
                    'description'   => "Notícia detectada via Google News. Verifique fontes oficiais.",
                    'link'          => $link,
                    'neighborhoods' => $neighborhoods,
                    'lat'           => $neighborhoods ? $this->getNeighborhoodCoord($neighborhoods[0])['lat'] : -3.7319,
                    'lng'           => $neighborhoods ? $this->getNeighborhoodCoord($neighborhoods[0])['lng'] : -38.5267,
                    'source'        => 'Google News (Defesa Civil)',
                    'time'          => $pubDate,
                ];

                $count++;
            }
        } catch (Exception $e) {
            error_log("DefesaCivil news error: " . $e->getMessage());
        }

        return $alerts;
    }

    /**
     * Buscar alertas da FUNCEME
     */
    private function fetchFUNCEME() {
        $alerts = [];

        // FUNCEME RSS/API
        $urls = [
            'https://www.funceme.br/wp-json/wp/v2/posts?categories=3&per_page=5', // Avisos meteorológicos
            'https://www.funceme.br/feed/',
        ];

        foreach ($urls as $url) {
            $response = $this->curlGet($url, strpos($url, 'feed') !== false);
            if (!$response) continue;

            // Se for JSON (wp-json)
            if (is_array($response)) {
                foreach ($response as $post) {
                    $title = strip_tags($post['title']['rendered'] ?? '');
                    $content = strip_tags($post['content']['rendered'] ?? '');

                    if (stripos($title . $content, 'Fortaleza') === false &&
                        stripos($title . $content, 'Ceará') === false) continue;

                    $alerts[] = [
                        'type'        => 'geral',
                        'type_info'   => $this->alertTypes['geral'],
                        'severity'    => 'moderado',
                        'title'       => mb_substr($title, 0, 200),
                        'description' => mb_substr($content, 0, 500),
                        'link'        => $post['link'] ?? '',
                        'lat'         => -3.7319,
                        'lng'         => -38.5267,
                        'source'      => 'FUNCEME',
                        'time'        => $post['date'] ?? date('c'),
                    ];
                }
                break; // Se JSON funcionou, não tentar o RSS
            }

            // Se for XML (RSS feed)
            if (is_string($response)) {
                try {
                    libxml_use_internal_errors(true);
                    $feed = simplexml_load_string($response);
                    if (!$feed || !isset($feed->channel->item)) continue;

                    foreach ($feed->channel->item as $item) {
                        $title = (string)($item->title ?? '');
                        $desc = (string)($item->description ?? '');

                        if (stripos($title . $desc, 'Fortaleza') === false &&
                            stripos($title . $desc, 'chuva') === false &&
                            stripos($title . $desc, 'alerta') === false) continue;

                        $alerts[] = [
                            'type'        => 'geral',
                            'type_info'   => $this->alertTypes['geral'],
                            'severity'    => 'moderado',
                            'title'       => strip_tags(html_entity_decode($title)),
                            'description' => mb_substr(strip_tags(html_entity_decode($desc)), 0, 500),
                            'link'        => (string)($item->link ?? ''),
                            'lat'         => -3.7319,
                            'lng'         => -38.5267,
                            'source'      => 'FUNCEME',
                            'time'        => (string)($item->pubDate ?? date('c')),
                        ];
                    }
                } catch (Exception $e) {
                    error_log("FUNCEME RSS parse error: " . $e->getMessage());
                }
            }
        }

        return $alerts;
    }

    /**
     * Áreas de risco com status atual
     */
    private function getRiskAreasWithStatus() {
        // Buscar clima atual para ajustar risco
        $rain = 0;
        try {
            require_once __DIR__ . '/WeatherService.php';
            $weather = new WeatherService();
            $data = $weather->getCurrentWeather();
            $rain = $data['current']['rain'] ?? 0;
        } catch (Exception $e) {}

        return array_map(function($area) use ($rain) {
            $riskLevel = $area['risco_base'];

            // Ajustar risco com chuva
            if ($area['tipo'] === 'alagamento' && $rain > 10) {
                $riskLevel = min(1.0, $riskLevel + ($rain / 100));
            }
            if ($area['tipo'] === 'deslizamento' && $rain > 20) {
                $riskLevel = min(1.0, $riskLevel + ($rain / 80));
            }

            $status = 'normal';
            if ($riskLevel > 0.8) $status = 'critico';
            elseif ($riskLevel > 0.6) $status = 'alerta';
            elseif ($riskLevel > 0.4) $status = 'atenção';

            return array_merge($area, [
                'risk_level' => round($riskLevel, 2),
                'status'     => $status,
                'type_info'  => $this->alertTypes[$area['tipo']] ?? $this->alertTypes['geral'],
            ]);
        }, $this->riskAreas);
    }

    /**
     * Detectar bairros no texto
     */
    private function detectNeighborhoods($text) {
        $textLower = mb_strtolower($text);
        $found = [];

        $neighborhoods = [
            'aldeota', 'meireles', 'centro', 'benfica', 'messejana', 'papicu',
            'cocó', 'parangaba', 'edson queiroz', 'barra do ceará', 'praia do futuro',
            'bom jardim', 'mondubim', 'jangurussu', 'passaré', 'pirambu',
            'mucuripe', 'varjota', 'fátima', 'serrinha', 'damas', 'cajazeiras',
            'josé walter', 'vicente pinzon', 'aerolândia', 'henrique jorge',
        ];

        foreach ($neighborhoods as $nb) {
            if (mb_strpos($textLower, $nb) !== false) {
                $found[] = $nb;
            }
        }

        return $found;
    }

    private function getNeighborhoodCoord($neighborhood) {
        $coords = [
            'aldeota' => ['lat' => -3.7340, 'lng' => -38.5080],
            'meireles' => ['lat' => -3.7240, 'lng' => -38.5030],
            'centro' => ['lat' => -3.7230, 'lng' => -38.5280],
            'cocó' => ['lat' => -3.7460, 'lng' => -38.4820],
            'parangaba' => ['lat' => -3.7750, 'lng' => -38.5530],
            'messejana' => ['lat' => -3.8310, 'lng' => -38.4920],
            'barra do ceará' => ['lat' => -3.6960, 'lng' => -38.5540],
            'pirambu' => ['lat' => -3.7100, 'lng' => -38.5470],
            'jangurussu' => ['lat' => -3.8350, 'lng' => -38.5120],
            'bom jardim' => ['lat' => -3.7850, 'lng' => -38.5890],
            'praia do futuro' => ['lat' => -3.7480, 'lng' => -38.4550],
        ];
        return $coords[mb_strtolower($neighborhood)] ?? ['lat' => -3.7319, 'lng' => -38.5267];
    }

    private function curlGet($url, $raw = false) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: City085-Monitor/1.0'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) return null;
        return $raw ? $response : json_decode($response, true);
    }
}
