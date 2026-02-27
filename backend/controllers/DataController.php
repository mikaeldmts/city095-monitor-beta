<?php
/**
 * DataController — Dados públicos de Fortaleza
 * Integra com CKAN API (dados.fortaleza.ce.gov.br)
 */

$localPath = realpath(__DIR__ . '/../../../backend');
$serverPath = realpath(__DIR__ . '/../../../api');
$portfolioRoot = $localPath ?: $serverPath;
require_once $portfolioRoot . '/config/database.php';
require_once __DIR__ . '/../services/OpenDataService.php';

class DataController {
    private $db;
    private $openData;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->openData = new OpenDataService();
    }

    /**
     * GET /api/data/overview — Visão geral de todos os datasets
     */
    public function getOverview() {
        try {
            $cached = $this->openData->getOverview();
            $sources = $this->openData->getDataSources();

            // Combinar dados disponíveis com lista de fontes
            $datasets = [];
            foreach ($sources as $key => $source) {
                $cachedItem = null;
                foreach ($cached as $c) {
                    if ($c['source_key'] === $key) {
                        $cachedItem = $c;
                        break;
                    }
                }

                $datasets[] = [
                    'key'          => $key,
                    'name'         => $source['name'],
                    'category'     => $source['category'],
                    'url'          => "https://dados.fortaleza.ce.gov.br/dataset/{$source['dataset']}",
                    'record_count' => $cachedItem ? (int)$cachedItem['record_count'] : 0,
                    'last_synced'  => $cachedItem['last_synced'] ?? null,
                    'has_data'     => $cachedItem !== null,
                ];
            }

            // Agrupar por categoria
            $byCategory = [];
            foreach ($datasets as $ds) {
                $byCategory[$ds['category']][] = $ds;
            }

            return [
                'success' => true,
                'data'    => [
                    'datasets'     => $datasets,
                    'by_category'  => $byCategory,
                    'total_sources' => count($sources),
                    'synced_count' => count($cached),
                ],
            ];
        } catch (Exception $e) {
            error_log("DataController overview error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Falha ao carregar overview dos dados'];
        }
    }

    /**
     * GET /api/data/transit — Dados de trânsito e mobilidade
     */
    public function getTransitData() {
        return $this->getDataByCategory('transito');
    }

    /**
     * GET /api/data/health — Dados de saúde
     */
    public function getHealthData() {
        return $this->getDataByCategory('saude');
    }

    /**
     * GET /api/data/social — Dados sociais e econômicos
     */
    public function getSocialData() {
        return $this->getDataByCategory('social');
    }

    /**
     * GET /api/data/geo — Dados geográficos
     */
    public function getGeoData() {
        return $this->getDataByCategory('geo');
    }

    /**
     * Helper genérico para buscar dados por categoria
     */
    private function getDataByCategory($category) {
        try {
            $results = $this->openData->getByCategory($category);

            return [
                'success' => true,
                'data'    => [
                    'category' => $category,
                    'sources'  => $results,
                    'total_records' => array_sum(array_column($results, 'record_count')),
                ],
            ];
        } catch (Exception $e) {
            error_log("DataController {$category} error: " . $e->getMessage());
            return ['success' => false, 'error' => "Falha ao carregar dados de {$category}"];
        }
    }

    /**
     * POST /api/sync — Sincronizar todos os dados públicos
     */
    public function syncAllData() {
        try {
            $result = $this->openData->syncAll();
            return [
                'success' => true,
                'data'    => $result,
            ];
        } catch (Exception $e) {
            error_log("DataController sync error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Falha na sincronização'];
        }
    }
}
