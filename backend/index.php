<?php
/**
 * City085 Monitor Beta — API Router
 * Front controller para todas as requisições da API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Preflight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Carregar configurações (reutiliza do portfolio principal)
// Local: city085-monitor-beta/backend/ -> ../../backend/
// Servidor: city085/api/ -> ../../api/
$localPath = realpath(__DIR__ . '/../../backend');
$serverPath = realpath(__DIR__ . '/../../api');
$portfolioRoot = $localPath ?: $serverPath;

if (!$portfolioRoot) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Portfolio root not found']);
    exit;
}

require_once $portfolioRoot . '/config/env.php';
require_once $portfolioRoot . '/config/database.php';

// Obter rota
$route = isset($_GET['route']) ? trim($_GET['route'], '/') : '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($route) {
        // ===== Chat Público =====
        case 'chat':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                break;
            }
            require_once __DIR__ . '/controllers/ChatController.php';
            $controller = new CityChatController();
            echo json_encode($controller->chat());
            break;

        // ===== Trending Topics =====
        case 'trending':
            require_once __DIR__ . '/controllers/TrendingController.php';
            $controller = new TrendingController();
            echo json_encode($controller->getTrending());
            break;

        case 'trending/categories':
            require_once __DIR__ . '/controllers/TrendingController.php';
            $controller = new TrendingController();
            echo json_encode($controller->getByCategory());
            break;

        // ===== Dados Públicos =====
        case 'data/overview':
            require_once __DIR__ . '/controllers/DataController.php';
            $controller = new DataController();
            echo json_encode($controller->getOverview());
            break;

        case 'data/transit':
            require_once __DIR__ . '/controllers/DataController.php';
            $controller = new DataController();
            echo json_encode($controller->getTransitData());
            break;

        case 'data/health':
            require_once __DIR__ . '/controllers/DataController.php';
            $controller = new DataController();
            echo json_encode($controller->getHealthData());
            break;

        case 'data/social':
            require_once __DIR__ . '/controllers/DataController.php';
            $controller = new DataController();
            echo json_encode($controller->getSocialData());
            break;

        case 'data/geo':
            require_once __DIR__ . '/controllers/DataController.php';
            $controller = new DataController();
            echo json_encode($controller->getGeoData());
            break;

        // ===== Eventos =====
        case 'events':
            require_once __DIR__ . '/controllers/EventsController.php';
            $controller = new EventsController();
            echo json_encode($controller->getEvents());
            break;

        case 'events/map':
            require_once __DIR__ . '/controllers/EventsController.php';
            $controller = new EventsController();
            echo json_encode($controller->getMapEvents());
            break;

        // ===== Dados em Tempo Real (clima, qualidade do ar, feriados, feeds) =====
        case 'realtime':
            require_once __DIR__ . '/services/RealTimeDataService.php';
            $service = new RealTimeDataService();
            echo json_encode(['success' => true, 'data' => $service->getAllRealTimeData()]);
            break;

        case 'weather':
            require_once __DIR__ . '/services/WeatherService.php';
            $service = new WeatherService();
            echo json_encode(['success' => true, 'data' => $service->getCurrentWeather()]);
            break;

        case 'weather/air':
            require_once __DIR__ . '/services/WeatherService.php';
            $service = new WeatherService();
            echo json_encode(['success' => true, 'data' => $service->getAirQuality()]);
            break;

        // ===== UPAs =====
        case 'upas':
            require_once __DIR__ . '/services/UPAService.php';
            $service = new UPAService();
            echo json_encode(['success' => true, 'data' => $service->getAllUPAs()]);
            break;

        case 'upas/bairro':
            require_once __DIR__ . '/services/UPAService.php';
            $service = new UPAService();
            $bairro = $_GET['name'] ?? '';
            echo json_encode(['success' => true, 'data' => $service->getUPAByBairro($bairro)]);
            break;

        // ===== Notícias =====
        case 'news':
            require_once __DIR__ . '/controllers/NewsController.php';
            $controller = new NewsController();
            echo json_encode($controller->getNews());
            break;

        case 'news/neighborhood':
            require_once __DIR__ . '/controllers/NewsController.php';
            $controller = new NewsController();
            echo json_encode($controller->getNeighborhoodNews());
            break;

        // ===== Segurança (heatmap) =====
        case 'safety':
            require_once __DIR__ . '/controllers/NewsController.php';
            $controller = new NewsController();
            echo json_encode($controller->getSafetyIndex());
            break;

        // ===== SSE Stream (Server-Sent Events) =====
        case 'stream':
            require_once __DIR__ . '/services/SSEService.php';
            $sse = new SSEService();
            $sse->startStream();
            break;

        // ===== Alertas Personalizados =====
        case 'alerts':
            require_once __DIR__ . '/services/AlertService.php';
            $alert = new AlertService();
            $sessionId = $_GET['session'] ?? $_POST['session'] ?? 'anon';
            if ($method === 'GET') {
                echo json_encode($alert->getAlerts($sessionId));
            } elseif ($method === 'POST') {
                $body = json_decode(file_get_contents('php://input'), true);
                echo json_encode($alert->createAlert(
                    $sessionId,
                    $body['type'] ?? '',
                    $body['neighborhood'] ?? null,
                    $body['threshold'] ?? null
                ));
            }
            break;

        case 'alerts/delete':
            if ($method === 'POST') {
                require_once __DIR__ . '/services/AlertService.php';
                $alert = new AlertService();
                $body = json_decode(file_get_contents('php://input'), true);
                echo json_encode($alert->deleteAlert($body['session'] ?? '', $body['alert_id'] ?? 0));
            }
            break;

        case 'alerts/check':
            require_once __DIR__ . '/services/AlertService.php';
            $alert = new AlertService();
            $sessionId = $_GET['session'] ?? 'anon';

            // Coletar dados atuais para checar triggers
            $currentData = [];
            try {
                require_once __DIR__ . '/services/RealTimeDataService.php';
                $rt = new RealTimeDataService();
                $currentData = $rt->getAllRealTimeData();
            } catch (Exception $e) {}

            echo json_encode([
                'success'   => true,
                'triggered' => $alert->checkTriggers($sessionId, $currentData),
            ]);
            break;

        // ===== Trânsito AMC/CTAFOR =====
        case 'transit':
            require_once __DIR__ . '/services/AMCTransitService.php';
            $amc = new AMCTransitService();
            echo json_encode(['success' => true, 'data' => $amc->getCurrentIncidents()]);
            break;

        // ===== Defesa Civil =====
        case 'defesa-civil':
            require_once __DIR__ . '/services/DefesaCivilService.php';
            $dc = new DefesaCivilService();
            echo json_encode(['success' => true, 'data' => $dc->getActiveAlerts()]);
            break;

        // ===== Histórico / Tendências Temporais =====
        case 'history':
            require_once __DIR__ . '/services/HistoryService.php';
            $history = new HistoryService();
            $days = (int) ($_GET['days'] ?? 7);
            echo json_encode(['success' => true, 'data' => $history->getDashboardHistory($days)]);
            break;

        case 'history/neighborhood':
            require_once __DIR__ . '/services/HistoryService.php';
            $history = new HistoryService();
            $name = $_GET['name'] ?? '';
            $type = $_GET['type'] ?? 'safety';
            $days = (int) ($_GET['days'] ?? 7);
            echo json_encode(['success' => true, 'data' => $history->getNeighborhoodHistory($name, $type, $days)]);
            break;

        case 'history/snapshot':
            if ($method === 'POST') {
                require_once __DIR__ . '/services/HistoryService.php';
                $history = new HistoryService();

                // Coletar dados e salvar snapshot
                $snapshotData = [];
                try {
                    require_once __DIR__ . '/services/RealTimeDataService.php';
                    $rt = new RealTimeDataService();
                    $allData = $rt->getAllRealTimeData();
                    $snapshotData['weather'] = $allData['weather'] ?? null;
                    $snapshotData['upas'] = $allData['upas'] ?? [];
                } catch (Exception $e) {}

                try {
                    require_once __DIR__ . '/controllers/NewsController.php';
                    $nc = new NewsController();
                    $safetyRes = $nc->getSafetyIndex();
                    $snapshotData['safety'] = $safetyRes['data'] ?? [];
                } catch (Exception $e) {}

                try {
                    require_once __DIR__ . '/controllers/StatsController.php';
                    $sc = new StatsController();
                    $statsData = $sc->getStats();
                    $snapshotData['neighborhoods'] = $statsData['data']['top_neighborhoods'] ?? [];
                    $snapshotData['categories'] = $statsData['data']['categories'] ?? [];
                } catch (Exception $e) {}

                $history->saveAllSnapshots($snapshotData);
                echo json_encode(['success' => true, 'message' => 'Snapshot salvo']);
            }
            break;

        // ===== Gamificação =====
        case 'ranking':
            require_once __DIR__ . '/services/GamificationService.php';
            $game = new GamificationService();
            $type = $_GET['type'] ?? 'monthly';
            $limit = min(50, (int) ($_GET['limit'] ?? 20));
            $data = $type === 'alltime' ? $game->getAllTimeRanking($limit) : $game->getMonthlyRanking($limit);
            echo json_encode(['success' => true, 'data' => $data, 'type' => $type]);
            break;

        case 'ranking/profile':
            require_once __DIR__ . '/services/GamificationService.php';
            $game = new GamificationService();
            $sessionId = $_GET['session'] ?? '';
            echo json_encode(['success' => true, 'data' => $game->getProfile($sessionId)]);
            break;

        case 'ranking/name':
            if ($method === 'POST') {
                require_once __DIR__ . '/services/GamificationService.php';
                $game = new GamificationService();
                $body = json_decode(file_get_contents('php://input'), true);
                echo json_encode($game->setDisplayName($body['session'] ?? '', $body['name'] ?? ''));
            }
            break;

        case 'ranking/neighborhood':
            require_once __DIR__ . '/services/GamificationService.php';
            $game = new GamificationService();
            $neighborhood = $_GET['name'] ?? '';
            echo json_encode(['success' => true, 'data' => $game->getNeighborhoodTopContributors($neighborhood)]);
            break;

        // ===== Stats Gerais =====
        case 'stats':
            require_once __DIR__ . '/controllers/StatsController.php';
            $controller = new StatsController();
            echo json_encode($controller->getStats());
            break;

        // ===== Sync de dados públicos =====
        case 'sync':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                break;
            }
            require_once __DIR__ . '/controllers/DataController.php';
            $controller = new DataController();
            echo json_encode($controller->syncAllData());
            break;

        // ===== Health Check =====
        case 'health':
            echo json_encode([
                'success'   => true,
                'status'    => 'ok',
                'project'   => 'City085 Monitor Beta',
                'city'      => 'Fortaleza/CE',
                'timestamp' => date('c'),
                'version'   => '2.0.0',
                'features'  => [
                    'chat_with_realdata', 'upas', 'upas_mais_saude', 'news', 'safety_heatmap',
                    'weather', 'traffic', 'sse_stream', 'alerts_push', 'amc_transit',
                    'defesa_civil', 'history_trends', 'gamification', 'ranking',
                ],
            ]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Route not found: ' . $route]);
            break;
    }
} catch (Exception $e) {
    error_log("City085 API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Internal server error',
    ]);
}
