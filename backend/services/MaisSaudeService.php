<?php
/**
 * MaisSaudeService — Scraping do app Mais Saúde Fortaleza
 * 
 * O app "Mais Saúde Fortaleza" usa uma API REST que pode ser consultada.
 * Endpoints conhecidos:
 * - https://maissaude.fortaleza.ce.gov.br/api/...
 * - https://saude.fortaleza.ce.gov.br/...
 * 
 * Também busca dados de:
 * - Portal IntegraSUS (CE) — painel de indicadores de saúde
 * - DataSUS / CKAN Fortaleza — indicadores de produção
 * 
 * Quando API real não responde, usa fallback de estimativa.
 */

class MaisSaudeService {
    private $db;
    private $cacheKey = 'mais_saude_data';
    private $cacheTTL = 300; // 5 minutos

    // Endpoints conhecidos do sistema de saúde de Fortaleza
    private $endpoints = [
        // Portal Mais Saúde Fortaleza
        'mais_saude'   => 'https://maissaude.fortaleza.ce.gov.br/api/unidades',
        // IntegraSUS — Ceará (indicadores)
        'integrasus'   => 'https://integrasus.saude.ce.gov.br/api/saude/indicadores-unidades',
        // CKAN Fortaleza — datasets de saúde/UPA
        'ckan_saude'   => 'https://dados.fortaleza.ce.gov.br/api/3/action/package_search?q=upa+saude+atendimento&rows=5',
        // Fila Nacional de Saúde (SISREG)
        'sisreg_proxy' => 'https://dados.fortaleza.ce.gov.br/api/3/action/package_search?q=fila+regulacao+saude&rows=3',
    ];

    // CNES das UPAs para match
    private $upaCNES = [
        '6847609'  => 'UPA Messejana',
        '7017979'  => 'UPA Jangurussu',
        '7017987'  => 'UPA Canindezinho',
        '6847617'  => 'UPA Cristo Redentor',
        '7017995'  => 'UPA Bom Jardim',
        '7592582'  => 'UPA Praia do Futuro',
        '2497654'  => 'Gonzaguinha (Barra do Ceará)',
        '7584903'  => 'UPA Itaperi',
        '7584911'  => 'UPA José Walter',
        '7523866'  => 'UPA Edson Queiroz',
        '7584920'  => 'UPA Autran Nunes',
        '6451896'  => 'UPA Conjunto Ceará',
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Buscar dados reais de lotação — tenta múltiplas fontes
     * Retorna array indexado por CNES com dados reais ou null
     */
    public function getRealTimeOccupancy() {
        // 1. Cache
        $cached = $this->getCache();
        if ($cached) return $cached;

        $data = [];

        // 2. Tentar API Mais Saúde Fortaleza (principal)
        $maisData = $this->fetchMaisSaude();
        if ($maisData) {
            $data = array_merge($data, $maisData);
        }

        // 3. Tentar IntegraSUS
        $integraData = $this->fetchIntegraSUS();
        if ($integraData) {
            $data = array_merge($data, $integraData);
        }

        // 4. Tentar CKAN — datasets de produção com atendimentos
        $ckanData = $this->fetchCKANProduction();
        if ($ckanData) {
            // Mesclar sem sobrescrever dados reais
            foreach ($ckanData as $cnes => $d) {
                if (!isset($data[$cnes])) {
                    $data[$cnes] = $d;
                }
            }
        }

        // 5. Cache resultado
        if (!empty($data)) {
            $this->setCache($data);
        }

        return !empty($data) ? $data : null;
    }

    /**
     * Scraping do portal Mais Saúde Fortaleza
     */
    private function fetchMaisSaude() {
        // Tentar endpoint principal de unidades
        $response = $this->curlGet($this->endpoints['mais_saude']);
        if (!$response) {
            // Tentar variantes do endpoint
            $alternatives = [
                'https://maissaude.fortaleza.ce.gov.br/api/v1/unidades',
                'https://maissaude.fortaleza.ce.gov.br/api/unidades/upas',
                'https://saude.fortaleza.ce.gov.br/api/unidades/upa',
            ];
            foreach ($alternatives as $url) {
                $response = $this->curlGet($url);
                if ($response) break;
            }
        }

        if (!$response) return null;

        // Parse resposta — formato pode variar
        $data = [];

        // Se for array de unidades
        if (is_array($response)) {
            foreach ($response as $unit) {
                $cnes = $unit['cnes'] ?? $unit['cod_cnes'] ?? $unit['CNES'] ?? null;
                if (!$cnes || !isset($this->upaCNES[$cnes])) continue;

                $data[$cnes] = [
                    'nome'               => $unit['nome'] ?? $this->upaCNES[$cnes],
                    'lotacao_pct'        => $unit['lotacao'] ?? $unit['occupancy'] ?? $unit['taxa_ocupacao'] ?? null,
                    'pacientes'          => $unit['pacientes'] ?? $unit['patients'] ?? null,
                    'tempo_espera'       => $unit['tempo_espera'] ?? $unit['wait_time'] ?? null,
                    'medicos_servico'    => $unit['medicos'] ?? $unit['doctors'] ?? null,
                    'leitos_ocupados'    => $unit['leitos_ocupados'] ?? null,
                    'leitos_total'       => $unit['leitos_total'] ?? $unit['leitos'] ?? null,
                    'atendimentos_hoje'  => $unit['atendimentos_hoje'] ?? $unit['today_count'] ?? null,
                    'fonte'              => 'Mais Saúde Fortaleza (API)',
                    'atualizado_em'      => date('c'),
                    'real_data'          => true,
                ];
            }
        }

        return !empty($data) ? $data : null;
    }

    /**
     * Buscar dados do IntegraSUS — painel de saúde do Ceará
     */
    private function fetchIntegraSUS() {
        $response = $this->curlGet($this->endpoints['integrasus']);
        if (!$response) {
            // Tentar endpoint alternativo
            $response = $this->curlGet('https://integrasus.saude.ce.gov.br/api/saude/atendimentos-urgencia');
        }
        if (!$response) return null;

        $data = [];

        // Parse — IntegraSUS tem formato próprio
        if (is_array($response)) {
            foreach ($response as $record) {
                $cnes = $record['cnes'] ?? $record['co_cnes'] ?? null;
                $municipio = $record['municipio'] ?? $record['no_municipio'] ?? '';

                // Filtrar apenas Fortaleza
                if ($cnes && isset($this->upaCNES[$cnes]) && stripos($municipio, 'Fortaleza') !== false) {
                    $data[$cnes] = [
                        'nome'              => $this->upaCNES[$cnes],
                        'atendimentos_mes'  => $record['qt_atendimentos'] ?? $record['total'] ?? null,
                        'internacoes'       => $record['qt_internacoes'] ?? null,
                        'fonte'             => 'IntegraSUS (CE)',
                        'atualizado_em'     => date('c'),
                        'real_data'         => true,
                    ];
                }
            }
        }

        return !empty($data) ? $data : null;
    }

    /**
     * Buscar dados de produção do CKAN Fortaleza
     */
    private function fetchCKANProduction() {
        $response = $this->curlGet($this->endpoints['ckan_saude']);
        if (!$response || !isset($response['success']) || !$response['success']) return null;

        $data = [];

        foreach ($response['result']['results'] ?? [] as $dataset) {
            foreach ($dataset['resources'] ?? [] as $resource) {
                $format = strtolower($resource['format'] ?? '');
                if ($format !== 'csv' && $format !== 'json') continue;

                $resourceData = $this->curlGet($resource['url'], $format !== 'json');
                if (!$resourceData) continue;

                if ($format === 'csv' && is_string($resourceData)) {
                    $parsed = $this->parseCSV($resourceData);
                    foreach ($parsed as $cnes => $record) {
                        $data[$cnes] = $record;
                    }
                } elseif (is_array($resourceData)) {
                    foreach ($resourceData as $record) {
                        $cnes = $record['cnes'] ?? $record['CNES'] ?? null;
                        if ($cnes && isset($this->upaCNES[$cnes])) {
                            $data[$cnes] = [
                                'nome'           => $this->upaCNES[$cnes],
                                'atendimentos'   => $record['atendimentos'] ?? $record['total'] ?? null,
                                'fonte'          => 'CKAN Fortaleza',
                                'atualizado_em'  => date('c'),
                            ];
                        }
                    }
                }
                break; // Um resource por dataset é suficiente
            }
        }

        return !empty($data) ? $data : null;
    }

    /**
     * Dados de filas de regulação (SISREG) via CKAN
     */
    public function getRegulationQueue() {
        $response = $this->curlGet($this->endpoints['sisreg_proxy']);
        if (!$response || !isset($response['success']) || !$response['success']) return null;

        $queueData = [];
        foreach ($response['result']['results'] ?? [] as $dataset) {
            $queueData[] = [
                'title'       => $dataset['title'] ?? '',
                'description' => $dataset['notes'] ?? '',
                'url'         => $dataset['url'] ?? '',
                'updated'     => $dataset['metadata_modified'] ?? '',
            ];
        }

        return $queueData;
    }

    /**
     * Parse CSV de produção
     */
    private function parseCSV($content) {
        $lines = explode("\n", $content);
        if (count($lines) < 2) return [];

        $headers = str_getcsv(array_shift($lines));
        $data = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            $row = str_getcsv($line);
            if (count($row) !== count($headers)) continue;

            $record = array_combine($headers, $row);
            $cnes = $record['CNES'] ?? $record['cnes'] ?? $record['cod_cnes'] ?? null;

            if ($cnes && isset($this->upaCNES[$cnes])) {
                $atendimentos = $record['atendimentos'] ?? $record['total_atendimentos']
                    ?? $record['qtd_atendimentos'] ?? $record['quantidade'] ?? 0;

                $data[$cnes] = [
                    'nome'          => $this->upaCNES[$cnes],
                    'atendimentos'  => (int) $atendimentos,
                    'fonte'         => 'CKAN Fortaleza (CSV)',
                    'atualizado_em' => date('c'),
                ];
            }
        }

        return $data;
    }

    /**
     * Cache simples no banco
     */
    private function getCache() {
        try {
            $stmt = $this->db->prepare("
                SELECT data_json FROM city_open_data 
                WHERE source_key = ? AND last_synced > DATE_SUB(NOW(), INTERVAL ? SECOND)
                LIMIT 1
            ");
            $stmt->execute([$this->cacheKey, $this->cacheTTL]);
            $row = $stmt->fetch();
            return $row ? json_decode($row['data_json'], true) : null;
        } catch (Exception $e) { return null; }
    }

    private function setCache($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO city_open_data (source_key, source_name, source_url, category, data_json, record_count)
                VALUES (?, 'Mais Saúde Fortaleza', '', 'saude', ?, ?)
                ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), record_count = VALUES(record_count), last_synced = NOW()
            ");
            $stmt->execute([$this->cacheKey, json_encode($data), count($data)]);
        } catch (Exception $e) {
            error_log("MaisSaude cache error: " . $e->getMessage());
        }
    }

    private function curlGet($url, $raw = false) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: City085-Monitor/1.0 (Fortaleza Urban Monitor)',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("MaisSaude API error ({$httpCode}): {$url}");
            return null;
        }

        return $raw ? $response : json_decode($response, true);
    }
}
