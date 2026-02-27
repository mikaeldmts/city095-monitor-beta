<?php
/**
 * NewsController — Endpoint de notícias locais de Fortaleza
 */

$localPath = realpath(__DIR__ . '/../../../backend');
$serverPath = realpath(__DIR__ . '/../../../api');
$portfolioRoot = $localPath ?: $serverPath;
require_once $portfolioRoot . '/config/database.php';
require_once __DIR__ . '/../services/NewsService.php';

class NewsController {
    private $newsService;

    public function __construct() {
        $this->newsService = new NewsService();
    }

    /**
     * GET /api/news — Notícias gerais de Fortaleza
     */
    public function getNews() {
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 30) : 20;
        $neighborhood = $_GET['neighborhood'] ?? null;

        try {
            $news = $this->newsService->getNews($neighborhood, $limit);
            return [
                'success' => true,
                'data'    => $news,
                'total'   => count($news),
            ];
        } catch (Exception $e) {
            error_log("NewsController error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Falha ao buscar notícias'];
        }
    }

    /**
     * GET /api/news/neighborhood?name=messejana — Notícias de um bairro
     */
    public function getNeighborhoodNews() {
        $name = $_GET['name'] ?? null;
        if (!$name) {
            return ['success' => false, 'error' => 'Parâmetro "name" é obrigatório'];
        }

        try {
            $news = $this->newsService->getNeighborhoodNews($name);
            return [
                'success'      => true,
                'data'         => $news,
                'neighborhood' => $name,
                'total'        => count($news),
            ];
        } catch (Exception $e) {
            error_log("NewsController neighborhood error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Falha ao buscar notícias do bairro'];
        }
    }

    /**
     * GET /api/safety — Índice de segurança por bairro (para heatmap)
     */
    public function getSafetyIndex() {
        try {
            $safety = $this->newsService->getNeighborhoodSafetyIndex();
            return [
                'success' => true,
                'data'    => $safety,
                'total'   => count($safety),
                'legend'  => [
                    ['level' => 'Seguro',     'color' => '#00d4aa', 'range' => '0.75 - 1.0'],
                    ['level' => 'Moderado',   'color' => '#ffeb3b', 'range' => '0.55 - 0.74'],
                    ['level' => 'Atenção',    'color' => '#ff8c00', 'range' => '0.40 - 0.54'],
                    ['level' => 'Alto risco', 'color' => '#ff4444', 'range' => '0.0 - 0.39'],
                ],
            ];
        } catch (Exception $e) {
            error_log("NewsController safety error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Falha ao gerar índice de segurança'];
        }
    }
}
