<?php
/**
 * OpenDataService — Integração com dados públicos de Fortaleza
 * Fontes: dados.fortaleza.ce.gov.br (CKAN API) + Portal da Transparência
 */

class OpenDataService {
    private $ckanBase = 'https://dados.fortaleza.ce.gov.br/api/3/action';
    private $db;

    // Mapeamento de datasets CKAN
    private $dataSources = [
        // Trânsito e Mobilidade
        'sinistros_transito' => [
            'name'     => 'Sinistros de Trânsito',
            'dataset'  => 'sinistros-de-transito',
            'category' => 'transito',
        ],
        'obitos_transito' => [
            'name'     => 'Óbitos no Trânsito',
            'dataset'  => 'obitos-no-transito',
            'category' => 'transito',
        ],
        'volume_trafego' => [
            'name'     => 'Volume de Tráfego',
            'dataset'  => 'volume-de-trafego',
            'category' => 'transito',
        ],
        'semaforos' => [
            'name'     => 'Semáforos',
            'dataset'  => 'semaforos',
            'category' => 'transito',
        ],
        'fiscalizacao_eletronica' => [
            'name'     => 'Fiscalização Eletrônica',
            'dataset'  => 'fiscalizacao-eletronica',
            'category' => 'transito',
        ],
        'transporte_gtfs' => [
            'name'     => 'Transporte Público (GTFS)',
            'dataset'  => 'transporte-publico-gtfs',
            'category' => 'transito',
        ],
        'terminais_onibus' => [
            'name'     => 'Terminais de Ônibus',
            'dataset'  => 'terminais-de-onibus',
            'category' => 'transito',
        ],
        'paradas_onibus' => [
            'name'     => 'Paradas de Ônibus',
            'dataset'  => 'paradas-de-onibus',
            'category' => 'transito',
        ],

        // Dados Sociais e Econômicos
        'idh_bairro' => [
            'name'     => 'IDH por Bairro',
            'dataset'  => 'idh-por-bairro',
            'category' => 'social',
        ],
        'empregos_bairro' => [
            'name'     => 'Empregos por Bairro',
            'dataset'  => 'estoque-de-empregos-por-bairro',
            'category' => 'social',
        ],
        'indicadores_demograficos' => [
            'name'     => 'Indicadores Demográficos',
            'dataset'  => 'indicadores-demograficos',
            'category' => 'social',
        ],

        // Saúde
        'unidades_saude' => [
            'name'     => 'Unidades de Saúde',
            'dataset'  => 'unidades-de-saude',
            'category' => 'saude',
        ],

        // Geografia e Meio Ambiente
        'limites_bairros' => [
            'name'     => 'Limites dos Bairros',
            'dataset'  => 'limites-dos-bairros',
            'category' => 'geo',
        ],
        'regioes_administrativas' => [
            'name'     => 'Regiões Administrativas',
            'dataset'  => 'regioes-administrativas',
            'category' => 'geo',
        ],
        'parques_areas_verdes' => [
            'name'     => 'Parques e Áreas Verdes',
            'dataset'  => 'parques-e-areas-verdes',
            'category' => 'geo',
        ],
        'bacias_hidrograficas' => [
            'name'     => 'Bacias Hidrográficas',
            'dataset'  => 'bacias-hidrograficas',
            'category' => 'geo',
        ],
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Buscar metadados de um dataset no CKAN
     */
    private function fetchDatasetMeta($datasetId) {
        $url = "{$this->ckanBase}/package_show?id={$datasetId}";
        return $this->curlGet($url);
    }

    /**
     * Buscar recursos (arquivos) de um dataset
     */
    private function fetchDatasetResources($datasetId) {
        $meta = $this->fetchDatasetMeta($datasetId);
        if (!$meta || !isset($meta['result']['resources'])) return [];

        $resources = [];
        foreach ($meta['result']['resources'] as $res) {
            $resources[] = [
                'id'          => $res['id'],
                'name'        => $res['name'] ?? $res['description'] ?? 'Recurso',
                'format'      => strtolower($res['format'] ?? ''),
                'url'         => $res['url'] ?? '',
                'created'     => $res['created'] ?? '',
                'last_modified' => $res['last_modified'] ?? '',
                'size'        => $res['size'] ?? 0,
            ];
        }

        return $resources;
    }

    /**
     * Buscar dados de um recurso CKAN (datastore_search)
     */
    private function fetchResourceData($resourceId, $limit = 100, $offset = 0) {
        $url = "{$this->ckanBase}/datastore_search?resource_id={$resourceId}&limit={$limit}&offset={$offset}";
        $response = $this->curlGet($url);

        if (!$response || !isset($response['result'])) return null;

        return [
            'records' => $response['result']['records'] ?? [],
            'total'   => $response['result']['total'] ?? 0,
            'fields'  => $response['result']['fields'] ?? [],
        ];
    }

    /**
     * Sincronizar todos os datasets
     */
    public function syncAll() {
        $results = [];
        $startTime = microtime(true);

        foreach ($this->dataSources as $key => $source) {
            try {
                $result = $this->syncDataset($key, $source);
                $results[$key] = $result;
            } catch (Exception $e) {
                error_log("OpenData sync error [{$key}]: " . $e->getMessage());
                $results[$key] = [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000);

        // Log da sincronização
        $this->logSync('all_datasets', 'completed', count($results), "Sync completo em {$duration}ms", $duration);

        return [
            'success'  => true,
            'results'  => $results,
            'duration' => $duration,
        ];
    }

    /**
     * Sincronizar um dataset específico
     */
    public function syncDataset($key, $source = null) {
        if (!$source && isset($this->dataSources[$key])) {
            $source = $this->dataSources[$key];
        }

        if (!$source) {
            return ['success' => false, 'error' => 'Dataset não encontrado'];
        }

        $startTime = microtime(true);

        // Verificar se já temos cache válido (6 horas)
        $cached = $this->getCachedData($key);
        if ($cached && $this->isCacheValid($cached, 6 * 3600)) {
            return [
                'success' => true,
                'source'  => 'cache',
                'records' => $cached['record_count'],
            ];
        }

        // Buscar recursos do dataset
        $resources = $this->fetchDatasetResources($source['dataset']);

        if (empty($resources)) {
            // Salvar metadata mesmo sem dados de recurso
            $this->saveToCache($key, $source['name'], $source['category'], [
                'status'   => 'no_resources',
                'dataset'  => $source['dataset'],
                'fetched'  => date('c'),
            ], 0, "https://dados.fortaleza.ce.gov.br/dataset/{$source['dataset']}");

            return ['success' => true, 'source' => 'api', 'records' => 0, 'note' => 'Sem recursos disponíveis'];
        }

        // Buscar dados do primeiro recurso com formato suportado
        $allRecords = [];
        $resourceMeta = [];

        foreach ($resources as $resource) {
            if (in_array($resource['format'], ['csv', 'json', 'geojson', 'xlsx'])) {
                // Tentar buscar via datastore
                $data = $this->fetchResourceData($resource['id'], 500);

                if ($data && !empty($data['records'])) {
                    $allRecords = array_merge($allRecords, $data['records']);
                    $resourceMeta[] = [
                        'id'     => $resource['id'],
                        'name'   => $resource['name'],
                        'format' => $resource['format'],
                        'total'  => $data['total'],
                    ];
                }

                // Limitar a 1000 registros por dataset
                if (count($allRecords) >= 1000) {
                    $allRecords = array_slice($allRecords, 0, 1000);
                    break;
                }
            }
        }

        // Salvar no cache
        $dataToSave = [
            'resources' => $resourceMeta,
            'records'   => $allRecords,
            'dataset'   => $source['dataset'],
            'fetched'   => date('c'),
        ];

        $this->saveToCache(
            $key,
            $source['name'],
            $source['category'],
            $dataToSave,
            count($allRecords),
            "https://dados.fortaleza.ce.gov.br/dataset/{$source['dataset']}"
        );

        $duration = round((microtime(true) - $startTime) * 1000);
        $this->logSync($key, 'completed', count($allRecords), '', $duration);

        return [
            'success' => true,
            'source'  => 'api',
            'records' => count($allRecords),
            'resources' => count($resourceMeta),
        ];
    }

    /**
     * Obter dados cacheados
     */
    public function getCachedData($sourceKey) {
        $stmt = $this->db->prepare(
            "SELECT * FROM city_open_data WHERE source_key = ? LIMIT 1"
        );
        $stmt->execute([$sourceKey]);
        return $stmt->fetch();
    }

    /**
     * Verificar se cache é válido
     */
    private function isCacheValid($cached, $ttl = 21600) {
        if (!$cached || !isset($cached['last_synced'])) return false;
        $lastSync = strtotime($cached['last_synced']);
        return (time() - $lastSync) < $ttl;
    }

    /**
     * Salvar dados no cache
     */
    private function saveToCache($key, $name, $category, $data, $recordCount, $sourceUrl = '') {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $expiresAt = date('Y-m-d H:i:s', time() + 6 * 3600);

        $stmt = $this->db->prepare(
            "INSERT INTO city_open_data (source_key, source_name, source_url, category, data_json, record_count, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
             source_name = VALUES(source_name),
             source_url = VALUES(source_url),
             category = VALUES(category),
             data_json = VALUES(data_json),
             record_count = VALUES(record_count),
             expires_at = VALUES(expires_at),
             last_synced = CURRENT_TIMESTAMP"
        );

        $stmt->execute([$key, $name, $sourceUrl, $category, $json, $recordCount, $expiresAt]);
    }

    /**
     * Obter todos os dados de uma categoria
     */
    public function getByCategory($category) {
        $stmt = $this->db->prepare(
            "SELECT source_key, source_name, source_url, category, record_count, last_synced, data_json
             FROM city_open_data WHERE category = ? ORDER BY source_name"
        );
        $stmt->execute([$category]);
        $results = $stmt->fetchAll();

        foreach ($results as &$row) {
            $row['data'] = json_decode($row['data_json'], true);
            unset($row['data_json']);
        }

        return $results;
    }

    /**
     * Obter overview de todos os dados
     */
    public function getOverview() {
        $stmt = $this->db->query(
            "SELECT source_key, source_name, source_url, category, record_count, last_synced
             FROM city_open_data ORDER BY category, source_name"
        );
        return $stmt->fetchAll();
    }

    /**
     * Listar datasets disponíveis
     */
    public function getDataSources() {
        return $this->dataSources;
    }

    /**
     * Log de sincronização
     */
    private function logSync($source, $status, $records, $message, $duration) {
        $stmt = $this->db->prepare(
            "INSERT INTO city_sync_log (source, status, records_fetched, message, duration_ms)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$source, $status, $records, $message, $duration]);
    }

    /**
     * cURL GET helper
     */
    private function curlGet($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: City085-Monitor-Beta/0.9.5',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            error_log("OpenData API error [{$url}] ({$httpCode}): $error");
            return null;
        }

        return json_decode($response, true);
    }
}
