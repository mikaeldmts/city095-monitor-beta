<?php
/**
 * SSEService — Server-Sent Events para dados em tempo real
 * 
 * Envia stream de dados para o frontend sem polling:
 * - Novos eventos da cidade
 * - Atualizações de UPAs
 * - Alertas de clima
 * - Notícias recentes
 * - Alertas da Defesa Civil
 * - Alterações no trânsito (AMC)
 * 
 * Frontend se conecta uma vez e recebe push instantâneo.
 */

class SSEService {
    private $db;
    private $lastEventId = 0;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Iniciar stream SSE
     * O frontend chama GET /api/stream e fica recebendo dados
     */
    public function startStream() {
        // Headers SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');
        header('X-Accel-Buffering: no'); // nginx

        // Desabilitar output buffering
        if (ob_get_level()) ob_end_flush();
        set_time_limit(0);
        ignore_user_abort(false);

        // Last-Event-ID para reconexão
        $this->lastEventId = isset($_SERVER['HTTP_LAST_EVENT_ID'])
            ? (int) $_SERVER['HTTP_LAST_EVENT_ID']
            : (int) ($_GET['lastEventId'] ?? 0);

        // Enviar evento inicial
        $this->sendEvent('connected', [
            'status'    => 'ok',
            'server_time' => date('c'),
            'message'   => 'Stream conectado ao City085 Monitor',
        ]);

        $iteration = 0;
        $lastWeather = 0;
        $lastUPA = 0;
        $lastNews = 0;
        $lastDefesaCivil = 0;
        $lastTransit = 0;

        while (!connection_aborted()) {
            $iteration++;

            // A cada 5s: checar novos eventos do banco
            if ($iteration % 1 === 0) {
                $this->checkNewEvents();
            }

            // A cada 60s: atualizar clima  
            if (time() - $lastWeather > 60) {
                $this->pushWeatherUpdate();
                $lastWeather = time();
            }

            // A cada 120s: atualizar UPAs
            if (time() - $lastUPA > 120) {
                $this->pushUPAUpdate();
                $lastUPA = time();
            }

            // A cada 300s (5min): checar notícias
            if (time() - $lastNews > 300) {
                $this->pushNewsUpdate();
                $lastNews = time();
            }

            // A cada 180s (3min): checar Defesa Civil
            if (time() - $lastDefesaCivil > 180) {
                $this->pushDefesaCivilUpdate();
                $lastDefesaCivil = time();
            }

            // A cada 120s: checar trânsito AMC
            if (time() - $lastTransit > 120) {
                $this->pushTransitUpdate();
                $lastTransit = time();
            }

            // Heartbeat a cada 15s
            if ($iteration % 3 === 0) {
                $this->sendEvent('heartbeat', ['time' => date('c'), 'iteration' => $iteration]);
            }

            sleep(5);
            flush();
        }
    }

    /**
     * Checar novos eventos no banco desde o último ID
     */
    private function checkNewEvents() {
        try {
            $stmt = $this->db->prepare("
                SELECT id, title, description, category, source, neighborhood, latitude, longitude, created_at
                FROM city_events
                WHERE id > ? AND is_active = 1
                ORDER BY id ASC
                LIMIT 10
            ");
            $stmt->execute([$this->lastEventId]);
            $events = $stmt->fetchAll();

            foreach ($events as $ev) {
                $this->sendEvent('new_event', $ev, $ev['id']);
                $this->lastEventId = max($this->lastEventId, $ev['id']);
            }
        } catch (Exception $e) {
            error_log("SSE checkNewEvents error: " . $e->getMessage());
        }
    }

    /**
     * Push atualização do clima
     */
    private function pushWeatherUpdate() {
        try {
            require_once __DIR__ . '/WeatherService.php';
            $weather = new WeatherService();
            $data = $weather->getCurrentWeather();
            if ($data) {
                $this->sendEvent('weather_update', [
                    'current' => $data['current'] ?? null,
                    'alerts'  => $data['alerts'] ?? [],
                ]);
            }
        } catch (Exception $e) {
            error_log("SSE weather error: " . $e->getMessage());
        }
    }

    /**
     * Push atualização das UPAs
     */
    private function pushUPAUpdate() {
        try {
            require_once __DIR__ . '/UPAService.php';
            $upa = new UPAService();
            $data = $upa->getAllUPAs();
            if ($data) {
                $this->sendEvent('upa_update', $data);
            }
        } catch (Exception $e) {
            error_log("SSE UPA error: " . $e->getMessage());
        }
    }

    /**
     * Push novas notícias
     */
    private function pushNewsUpdate() {
        try {
            require_once __DIR__ . '/NewsService.php';
            $news = new NewsService();
            $data = $news->getNews(null, 5);
            if ($data) {
                $this->sendEvent('news_update', $data);
            }
        } catch (Exception $e) {
            error_log("SSE news error: " . $e->getMessage());
        }
    }

    /**
     * Push alertas da Defesa Civil
     */
    private function pushDefesaCivilUpdate() {
        try {
            require_once __DIR__ . '/DefesaCivilService.php';
            $dc = new DefesaCivilService();
            $alerts = $dc->getActiveAlerts();
            if (!empty($alerts)) {
                $this->sendEvent('defesa_civil_alert', $alerts);
            }
        } catch (Exception $e) {
            error_log("SSE defesa civil error: " . $e->getMessage());
        }
    }

    /**
     * Push dados de trânsito AMC
     */
    private function pushTransitUpdate() {
        try {
            require_once __DIR__ . '/AMCTransitService.php';
            $amc = new AMCTransitService();
            $data = $amc->getCurrentIncidents();
            if ($data) {
                $this->sendEvent('transit_update', $data);
            }
        } catch (Exception $e) {
            error_log("SSE transit error: " . $e->getMessage());
        }
    }

    /**
     * Enviar evento SSE formatado
     */
    private function sendEvent($type, $data, $id = null) {
        if ($id) {
            echo "id: {$id}\n";
        }
        echo "event: {$type}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        
        if (ob_get_level()) ob_flush();
        flush();
    }
}
