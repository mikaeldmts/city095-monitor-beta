<?php
/**
 * AMCTransitService — Integração com AMC/CTAFOR (Trânsito de Fortaleza)
 * 
 * Fontes:
 * - CKAN Fortaleza (dados abertos de trânsito, semáforos, acidentes)
 * - Google News RSS — notícias de trânsito em Fortaleza
 * - Dados de interdições e obras programados
 * 
 * A AMC/CTAFOR publica dados de:
 * - Interdições de vias
 * - Semáforos com defeito
 * - Acidentes de trânsito
 * - Blitz programadas
 * - Obras que impactam vias
 */

class AMCTransitService {
    private $ckanUrl = 'https://dados.fortaleza.ce.gov.br/api/3/action';

    // Datasets CKAN conhecidos de trânsito
    private $datasets = [
        'acidentes'     => 'package_search?q=acidentes+transito+fortaleza&rows=3',
        'interdicoes'   => 'package_search?q=interdicao+vias+obras+fortaleza&rows=3',
        'semaforos'     => 'package_search?q=semaforos+sinalizacao+fortaleza&rows=3',
        'mobilidade'    => 'package_search?q=mobilidade+urbana+fortaleza+transporte&rows=3',
    ];

    // Vias principais com coordenadas para mapeamento
    private $mainRoads = [
        'av. washington soares'     => ['lat' => -3.7710, 'lng' => -38.4780, 'bairro' => 'Edson Queiroz'],
        'av. santos dumont'         => ['lat' => -3.7300, 'lng' => -38.5050, 'bairro' => 'Aldeota'],
        'av. bezerra de menezes'    => ['lat' => -3.7380, 'lng' => -38.5580, 'bairro' => 'São Gerardo'],
        'av. aguanambi'             => ['lat' => -3.7450, 'lng' => -38.5280, 'bairro' => 'Fátima'],
        'av. borges de melo'        => ['lat' => -3.7450, 'lng' => -38.5100, 'bairro' => 'Joaquim Távora'],
        'av. domingos olímpio'      => ['lat' => -3.7380, 'lng' => -38.5350, 'bairro' => 'Benfica'],
        'av. 13 de maio'            => ['lat' => -3.7320, 'lng' => -38.5220, 'bairro' => 'Benfica'],
        'av. leste-oeste'           => ['lat' => -3.7160, 'lng' => -38.5400, 'bairro' => 'Pirambu'],
        'av. silas munguba'         => ['lat' => -3.7800, 'lng' => -38.5560, 'bairro' => 'Parangaba'],
        'av. godofredo maciel'      => ['lat' => -3.7950, 'lng' => -38.5610, 'bairro' => 'Maraponga'],
        'br-116'                    => ['lat' => -3.8100, 'lng' => -38.5300, 'bairro' => 'Aeroporto'],
        'ce-040'                    => ['lat' => -3.7600, 'lng' => -38.4600, 'bairro' => 'Praia do Futuro'],
        'av. rui barbosa'           => ['lat' => -3.7250, 'lng' => -38.5150, 'bairro' => 'Meireles'],
        'av. abolição'              => ['lat' => -3.7250, 'lng' => -38.5060, 'bairro' => 'Meireles'],
        'av. beira-mar'             => ['lat' => -3.7230, 'lng' => -38.5090, 'bairro' => 'Meireles'],
        'av. desembargador moreira' => ['lat' => -3.7350, 'lng' => -38.5060, 'bairro' => 'Aldeota'],
        'av. barão de studart'      => ['lat' => -3.7370, 'lng' => -38.5130, 'bairro' => 'Aldeota'],
        'av. senador virgílio távora' => ['lat' => -3.7400, 'lng' => -38.5050, 'bairro' => 'Dionísio Torres'],
        'av. engenheiro santana jr'  => ['lat' => -3.7480, 'lng' => -38.4950, 'bairro' => 'Cocó'],
        'av. sebastião de abreu'     => ['lat' => -3.7620, 'lng' => -38.4840, 'bairro' => 'Guararapes'],
        'av. antônio sales'          => ['lat' => -3.7390, 'lng' => -38.5180, 'bairro' => 'Joaquim Távora'],
        'av. pontes vieira'          => ['lat' => -3.7510, 'lng' => -38.5140, 'bairro' => 'São João do Tauape'],
        'av. jovita feitosa'         => ['lat' => -3.7500, 'lng' => -38.5650, 'bairro' => 'Henrique Jorge'],
        'av. mister hull'            => ['lat' => -3.7400, 'lng' => -38.5700, 'bairro' => 'Antônio Bezerra'],
        'av. josé bastos'            => ['lat' => -3.7550, 'lng' => -38.5420, 'bairro' => 'Damas'],
        'av. augusto dos anjos'      => ['lat' => -3.8050, 'lng' => -38.5350, 'bairro' => 'Passaré'],
    ];

    /**
     * Buscar incidentes de trânsito atuais
     * Combina dados do CKAN + notícias em tempo real
     */
    public function getCurrentIncidents() {
        $incidents = [];

        // 1. Notícias de trânsito via Google News RSS
        $newsIncidents = $this->fetchTransitNews();
        if ($newsIncidents) {
            $incidents = array_merge($incidents, $newsIncidents);
        }

        // 2. Dados CKAN (interdições, acidentes)
        $ckanIncidents = $this->fetchCKANTransitData();
        if ($ckanIncidents) {
            $incidents = array_merge($incidents, $ckanIncidents);
        }

        // 3. Interdições programadas (horários conhecidos)
        $scheduled = $this->getScheduledInterruptions();
        $incidents = array_merge($incidents, $scheduled);

        // Ordenar por prioridade/recência
        usort($incidents, function($a, $b) {
            $severityOrder = ['alto' => 0, 'moderado' => 1, 'baixo' => 2];
            $sa = $severityOrder[$a['severity'] ?? 'baixo'] ?? 2;
            $sb = $severityOrder[$b['severity'] ?? 'baixo'] ?? 2;
            return $sa - $sb;
        });

        return [
            'incidents'     => array_slice($incidents, 0, 20),
            'total'         => count($incidents),
            'main_roads'    => $this->mainRoads,
            'updated_at'    => date('c'),
            'sources'       => ['Google News RSS', 'CKAN Fortaleza', 'Horários conhecidos'],
        ];
    }

    /**
     * Notícias de trânsito em Fortaleza
     */
    private function fetchTransitNews() {
        $query = urlencode('trânsito Fortaleza interdição acidente blitz');
        $rssUrl = "https://news.google.com/rss/search?q={$query}&hl=pt-BR&gl=BR&ceid=BR:pt-419";

        $xml = $this->curlGet($rssUrl, true);
        if (!$xml) return null;

        $incidents = [];

        try {
            libxml_use_internal_errors(true);
            $feed = simplexml_load_string($xml);
            if (!$feed || !isset($feed->channel->item)) return null;

            $count = 0;
            foreach ($feed->channel->item as $item) {
                if ($count >= 10) break;
                $title = (string)($item->title ?? '');
                $desc = (string)($item->description ?? '');
                $pubDate = (string)($item->pubDate ?? '');
                $link = (string)($item->link ?? '');

                // Detectar via/bairro mencionado
                $location = $this->detectRoadOrNeighborhood($title . ' ' . $desc);

                // Classificar severidade
                $severity = 'baixo';
                if (preg_match('/\b(acidente|grave|morte|fatal|capotamento|atropelamento)\b/i', $title)) {
                    $severity = 'alto';
                } elseif (preg_match('/\b(interdição|bloqueio|desvio|blitz|alagamento|semáforo)\b/i', $title)) {
                    $severity = 'moderado';
                }

                // Classificar tipo
                $type = 'geral';
                if (preg_match('/acidente|colisão|capotamento|atropelamento/i', $title)) $type = 'acidente';
                elseif (preg_match('/interdição|bloqueio|desvio/i', $title)) $type = 'interdicao';
                elseif (preg_match('/blitz|fiscalização/i', $title)) $type = 'blitz';
                elseif (preg_match('/alagamento|inundação/i', $title)) $type = 'alagamento';
                elseif (preg_match('/semáforo|sinal/i', $title)) $type = 'semaforo';
                elseif (preg_match('/obra|construção|pavimentação/i', $title)) $type = 'obra';

                $typeIcons = [
                    'acidente' => '💥', 'interdicao' => '🚧', 'blitz' => '🚔',
                    'alagamento' => '🌊', 'semaforo' => '🚦', 'obra' => '🏗',
                    'geral' => '🚗',
                ];

                $incidents[] = [
                    'title'        => strip_tags(html_entity_decode($title)),
                    'description'  => strip_tags(html_entity_decode($desc)),
                    'type'         => $type,
                    'type_icon'    => $typeIcons[$type] ?? '🚗',
                    'severity'     => $severity,
                    'neighborhood' => $location['bairro'] ?? null,
                    'road'         => $location['road'] ?? null,
                    'lat'          => $location['lat'] ?? null,
                    'lng'          => $location['lng'] ?? null,
                    'link'         => $link,
                    'pubDate'      => $pubDate,
                    'source'       => 'Google News',
                ];

                $count++;
            }
        } catch (Exception $e) {
            error_log("AMC transit news error: " . $e->getMessage());
        }

        return $incidents;
    }

    /**
     * Dados de trânsito do CKAN Fortaleza
     */
    private function fetchCKANTransitData() {
        $incidents = [];

        foreach (['acidentes', 'interdicoes'] as $key) {
            $url = "{$this->ckanUrl}/{$this->datasets[$key]}";
            $response = $this->curlGet($url);
            if (!$response || !($response['success'] ?? false)) continue;

            foreach ($response['result']['results'] ?? [] as $dataset) {
                // Extrair metadados como incidente genérico
                $incidents[] = [
                    'title'        => $dataset['title'] ?? "Dataset {$key}",
                    'description'  => mb_substr($dataset['notes'] ?? '', 0, 300),
                    'type'         => $key === 'acidentes' ? 'acidente' : 'interdicao',
                    'type_icon'    => $key === 'acidentes' ? '💥' : '🚧',
                    'severity'     => 'baixo',
                    'neighborhood' => null,
                    'road'         => null,
                    'lat'          => null,
                    'lng'          => null,
                    'link'         => "https://dados.fortaleza.ce.gov.br/dataset/" . ($dataset['name'] ?? ''),
                    'pubDate'      => $dataset['metadata_modified'] ?? '',
                    'source'       => 'CKAN Fortaleza',
                ];
            }
        }

        return $incidents;
    }

    /**
     * Interdições programadas — horários conhecidos de blitz/desvios
     */
    private function getScheduledInterruptions() {
        $now = new DateTime('now', new DateTimeZone('America/Fortaleza'));
        $hour = (int) $now->format('H');
        $dayOfWeek = (int) $now->format('N'); // 1=seg, 7=dom

        $interruptions = [];

        // Ciclofaixa de lazer — domingos 6h-12h
        if ($dayOfWeek === 7 && $hour >= 6 && $hour < 12) {
            $interruptions[] = [
                'title'        => 'Ciclofaixa de Lazer — Av. Beira-Mar e entorno',
                'description'  => 'Vias parcialmente interditadas para ciclofaixa de lazer. Domingos das 6h às 12h.',
                'type'         => 'interdicao',
                'type_icon'    => '🚲',
                'severity'     => 'baixo',
                'neighborhood' => 'Meireles',
                'road'         => 'Av. Beira-Mar',
                'lat'          => -3.7230,
                'lng'          => -38.5090,
                'source'       => 'AMC (programado)',
            ];
        }

        // Horário de pico manhã — 6h30-8h30
        if ($hour >= 6 && $hour < 9 && $dayOfWeek <= 5) { // dias úteis
            $interruptions[] = [
                'title'        => 'Horário de pico da manhã',
                'description'  => 'Trânsito intenso esperado nas principais vias: Washington Soares, Aguanambi, BR-116, Santos Dumont.',
                'type'         => 'geral',
                'type_icon'    => '⏰',
                'severity'     => 'moderado',
                'neighborhood' => null,
                'road'         => 'Vias principais',
                'lat'          => -3.7319,
                'lng'          => -38.5267,
                'source'       => 'Padrão horário',
            ];
        }

        // Horário de pico tarde — 17h-19h
        if ($hour >= 17 && $hour < 19 && $dayOfWeek <= 5) {
            $interruptions[] = [
                'title'        => 'Horário de pico da tarde',
                'description'  => 'Retorno do trabalho. Trânsito intenso esperado nas vias Washington Soares, BR-116, Bezerra de Menezes.',
                'type'         => 'geral',
                'type_icon'    => '⏰',
                'severity'     => 'moderado',
                'neighborhood' => null,
                'road'         => 'Vias principais',
                'lat'          => -3.7319,
                'lng'          => -38.5267,
                'source'       => 'Padrão horário',
            ];
        }

        // Feira noturna — sexta-feira à noite na Varjota
        if ($dayOfWeek === 5 && $hour >= 18) {
            $interruptions[] = [
                'title'        => 'Feira noturna da Varjota',
                'description'  => 'Rua Ana Bilhar parcialmente interditada para a feira gastronômica. Sextas à noite.',
                'type'         => 'interdicao',
                'type_icon'    => '🍽',
                'severity'     => 'baixo',
                'neighborhood' => 'Varjota',
                'road'         => 'Rua Ana Bilhar',
                'lat'          => -3.7310,
                'lng'          => -38.4960,
                'source'       => 'AMC (programado)',
            ];
        }

        return $interruptions;
    }

    /**
     * Detectar via ou bairro mencionado no texto
     */
    private function detectRoadOrNeighborhood($text) {
        $textLower = mb_strtolower($text);

        // Checar vias
        foreach ($this->mainRoads as $road => $info) {
            if (mb_strpos($textLower, $road) !== false) {
                return [
                    'road'   => $road,
                    'bairro' => $info['bairro'],
                    'lat'    => $info['lat'],
                    'lng'    => $info['lng'],
                ];
            }
        }

        // Checar bairros (usar lista do GroqCityService)
        $neighborhoods = [
            'aldeota' => [-3.7340, -38.5080], 'meireles' => [-3.7240, -38.5030],
            'centro' => [-3.7230, -38.5280], 'benfica' => [-3.7410, -38.5380],
            'messejana' => [-3.8310, -38.4920], 'papicu' => [-3.7360, -38.4930],
            'cocó' => [-3.7460, -38.4820], 'parangaba' => [-3.7750, -38.5530],
            'montese' => [-3.7590, -38.5350], 'edson queiroz' => [-3.7710, -38.4780],
            'barra do ceará' => [-3.6960, -38.5540], 'josé walter' => [-3.8210, -38.5470],
            'praia do futuro' => [-3.7480, -38.4550], 'fatima' => [-3.7450, -38.5280],
            'passaré' => [-3.8020, -38.5230], 'cajazeiras' => [-3.8110, -38.5110],
            'bom jardim' => [-3.7850, -38.5890], 'mondubim' => [-3.7940, -38.5780],
        ];

        foreach ($neighborhoods as $nb => $coords) {
            if (mb_strpos($textLower, $nb) !== false) {
                return ['bairro' => $nb, 'lat' => $coords[0], 'lng' => $coords[1], 'road' => null];
            }
        }

        return [];
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
