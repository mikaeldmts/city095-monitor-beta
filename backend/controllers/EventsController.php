<?php
/**
 * EventsController — Eventos e acontecimentos na cidade
 * Mistura eventos detectados no chat + dados públicos
 */

$localPath = realpath(__DIR__ . '/../../../backend');
$serverPath = realpath(__DIR__ . '/../../../api');
$portfolioRoot = $localPath ?: $serverPath;
require_once $portfolioRoot . '/config/database.php';

class EventsController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * GET /api/events — Lista de eventos recentes
     */
    public function getEvents() {
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 20;
        $category = $_GET['category'] ?? null;

        try {
            $sql = "
                SELECT id, title, description, category, source, neighborhood,
                       latitude, longitude, event_date, mention_count, is_active,
                       created_at, updated_at
                FROM city_events
                WHERE is_active = 1
            ";
            $params = [];

            if ($category) {
                $sql .= " AND category = ?";
                $params[] = $category;
            }

            $sql .= " ORDER BY mention_count DESC, created_at DESC LIMIT ?";
            $params[] = $limit;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $events = $stmt->fetchAll();

            // Formatar
            foreach ($events as &$event) {
                $event['mention_count'] = (int)$event['mention_count'];
                $event['is_active'] = (bool)$event['is_active'];
                $event['latitude'] = $event['latitude'] ? (float)$event['latitude'] : null;
                $event['longitude'] = $event['longitude'] ? (float)$event['longitude'] : null;
            }

            return [
                'success' => true,
                'data'    => $events,
            ];
        } catch (Exception $e) {
            error_log("EventsController error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Falha ao carregar eventos'];
        }
    }

    /**
     * GET /api/events/map — Eventos com coordenadas para o mapa
     */
    public function getMapEvents() {
        try {
            // Eventos com coordenadas
            $stmt = $this->db->prepare("
                SELECT id, title, category, neighborhood, latitude, longitude,
                       mention_count, created_at
                FROM city_events
                WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL
                ORDER BY mention_count DESC
                LIMIT 100
            ");
            $stmt->execute();
            $events = $stmt->fetchAll();

            // Também buscar tópicos com coordenadas (últimas 48h)
            $stmt2 = $this->db->prepare("
                SELECT 
                    topic as title,
                    category,
                    neighborhood,
                    latitude,
                    longitude,
                    COUNT(*) as mention_count,
                    MAX(created_at) as created_at
                FROM city_topics
                WHERE latitude IS NOT NULL 
                AND longitude IS NOT NULL
                AND created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR)
                GROUP BY topic, category, neighborhood, latitude, longitude
                ORDER BY mention_count DESC
                LIMIT 100
            ");
            $stmt2->execute();
            $topicPoints = $stmt2->fetchAll();

            // Categorias com ícones para o mapa
            $categoryColors = [
                'seguranca'         => '#ff4444',
                'transito'          => '#ff8c00',
                'saude'             => '#00cc88',
                'educacao'          => '#4a9eff',
                'eventos'           => '#ff69b4',
                'politica'          => '#9966ff',
                'clima'             => '#00bcd4',
                'infraestrutura'    => '#ffeb3b',
                'cultura'           => '#e91e63',
                'economia'          => '#4caf50',
                'meio_ambiente'     => '#2e7d32',
                'esporte'           => '#ff5722',
                'tecnologia'        => '#00e5ff',
                'servicos_publicos' => '#607d8b',
                'outros'            => '#9e9e9e',
            ];

            // Merge e formatar
            $mapPoints = [];

            foreach ($events as $e) {
                $mapPoints[] = [
                    'type'          => 'event',
                    'id'            => 'evt_' . $e['id'],
                    'title'         => $e['title'],
                    'category'      => $e['category'],
                    'neighborhood'  => $e['neighborhood'],
                    'lat'           => (float)$e['latitude'],
                    'lng'           => (float)$e['longitude'],
                    'mentions'      => (int)$e['mention_count'],
                    'color'         => $categoryColors[$e['category']] ?? '#9e9e9e',
                    'date'          => $e['created_at'],
                ];
            }

            foreach ($topicPoints as $tp) {
                $mapPoints[] = [
                    'type'          => 'topic',
                    'id'            => 'top_' . md5($tp['title'] . $tp['neighborhood']),
                    'title'         => $tp['title'],
                    'category'      => $tp['category'],
                    'neighborhood'  => $tp['neighborhood'],
                    'lat'           => (float)$tp['latitude'],
                    'lng'           => (float)$tp['longitude'],
                    'mentions'      => (int)$tp['mention_count'],
                    'color'         => $categoryColors[$tp['category']] ?? '#9e9e9e',
                    'date'          => $tp['created_at'],
                ];
            }

            return [
                'success' => true,
                'data'    => [
                    'points'         => $mapPoints,
                    'total_points'   => count($mapPoints),
                    'category_colors' => $categoryColors,
                    'center'         => ['lat' => -3.7319, 'lng' => -38.5267], // Centro de Fortaleza
                ],
            ];
        } catch (Exception $e) {
            error_log("EventsController mapEvents error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Falha ao carregar dados do mapa'];
        }
    }
}
