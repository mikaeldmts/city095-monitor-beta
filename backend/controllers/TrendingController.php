<?php
/**
 * TrendingController — Trending Topics de Fortaleza
 * 
 * Agrega tópicos de TODAS as fontes:
 * - Chat conversations (city_topics)
 * - Notícias de jornais (city_events source=news)
 * - Eventos auto-detectados (city_events source=weather_api, etc)
 * - AMC/Trânsito, Defesa Civil, UPAs
 */

$localPath = realpath(__DIR__ . '/../../../backend');
$serverPath = realpath(__DIR__ . '/../../../api');
$portfolioRoot = $localPath ?: $serverPath;
require_once $portfolioRoot . '/config/database.php';
require_once __DIR__ . '/../services/GroqCityService.php';

class TrendingController {
    private $db;

    // Categorias → sentimento inferido
    private $negativeCats = ['seguranca', 'transito'];
    private $positiveCats = ['cultura', 'eventos', 'esporte', 'educacao'];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * GET /api/trending — Top trending topics (multi-fonte)
     */
    public function getTrending() {
        $hours = isset($_GET['hours']) ? min((int)$_GET['hours'], 168) : 24;
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 15;

        try {
            // === 1. Tópicos do chat (city_topics) ===
            $stmt = $this->db->prepare("
                SELECT 
                    topic,
                    category,
                    COUNT(*) as mention_count,
                    AVG(CASE 
                        WHEN sentiment = 'positivo' THEN 1 
                        WHEN sentiment = 'negativo' THEN -1 
                        ELSE 0 
                    END) as avg_sentiment,
                    GROUP_CONCAT(DISTINCT neighborhood ORDER BY neighborhood SEPARATOR ', ') as neighborhoods,
                    MIN(created_at) as first_mention,
                    MAX(created_at) as last_mention,
                    'chat' as source_type
                FROM city_topics
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                AND relevance >= 0.3
                GROUP BY topic, category
                ORDER BY mention_count DESC
                LIMIT ?
            ");
            $stmt->execute([$hours, $limit]);
            $chatTopics = $stmt->fetchAll();

            // === 2. Eventos de notícias + auto (city_events, exceto seed) ===
            $stmt = $this->db->prepare("
                SELECT 
                    title as topic,
                    category,
                    mention_count,
                    neighborhood as neighborhoods,
                    created_at as first_mention,
                    updated_at as last_mention,
                    source as source_type
                FROM city_events
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                AND is_active = 1
                AND source NOT IN ('seed')
                ORDER BY mention_count DESC, created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$hours, $limit * 2]);
            $newsTopics = $stmt->fetchAll();

            // === 3. Merge e deduplicar ===
            $merged = [];
            $seen = [];

            // Chat vem primeiro (maior relevância)
            foreach ($chatTopics as $t) {
                $key = mb_strtolower(mb_substr($t['topic'], 0, 50));
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $t['mention_count'] = (int)$t['mention_count'];
                $t['avg_sentiment'] = round((float)$t['avg_sentiment'], 2);
                $t['sentiment_label'] = $this->sentimentLabel($t['avg_sentiment']);
                $t['neighborhoods'] = $t['neighborhoods'] ? explode(', ', $t['neighborhoods']) : [];
                $t['source_label'] = '💬 Chat';
                $merged[] = $t;
            }

            // News e eventos (com sentimento inferido da categoria)
            foreach ($newsTopics as $t) {
                $key = mb_strtolower(mb_substr($t['topic'], 0, 50));
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $t['mention_count'] = max(1, (int)$t['mention_count']);
                $t['avg_sentiment'] = $this->inferSentiment($t['category']);
                $t['sentiment_label'] = $this->sentimentLabel($t['avg_sentiment']);
                $t['neighborhoods'] = $t['neighborhoods'] ? [$t['neighborhoods']] : [];
                $t['source_label'] = $this->sourceLabel($t['source_type']);
                $merged[] = $t;
            }

            // Ordenar por menções
            usort($merged, fn($a, $b) => $b['mention_count'] - $a['mention_count']);
            $topics = array_slice($merged, 0, $limit);

            // Stats gerais
            $totalMsgs = $this->getTotalMessages($hours);
            $totalTopics = count($merged);
            $totalEvents = $this->getTotalEvents($hours);

            // Gerar resumo com IA (se tiver tópicos)
            $summary = null;
            if (!empty($topics)) {
                try {
                    $groq = new GroqCityService();
                    $summary = $groq->generateTrendingSummary($topics);
                } catch (Exception $e) {
                    error_log("TrendingController summary error: " . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'data'    => [
                    'topics'   => $topics,
                    'summary'  => $summary,
                    'stats'    => [
                        'total_messages' => $totalMsgs,
                        'total_topics'   => $totalTopics,
                        'total_events'   => $totalEvents,
                        'period_hours'   => $hours,
                    ],
                    'updated_at' => date('c'),
                ],
            ];
        } catch (Exception $e) {
            error_log("TrendingController error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Falha ao buscar trending topics'];
        }
    }

    /**
     * GET /api/trending/categories — Trending por categoria (multi-fonte)
     */
    public function getByCategory() {
        $hours = isset($_GET['hours']) ? min((int)$_GET['hours'], 168) : 24;

        try {
            // Categorias do chat
            $stmt = $this->db->prepare("
                SELECT 
                    category,
                    COUNT(*) as total_mentions,
                    COUNT(DISTINCT topic) as unique_topics,
                    AVG(CASE 
                        WHEN sentiment = 'positivo' THEN 1 
                        WHEN sentiment = 'negativo' THEN -1 
                        ELSE 0 
                    END) as avg_sentiment,
                    GROUP_CONCAT(DISTINCT neighborhood ORDER BY neighborhood SEPARATOR ', ') as neighborhoods
                FROM city_topics
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                AND relevance >= 0.3
                GROUP BY category
            ");
            $stmt->execute([$hours]);
            $chatCats = $stmt->fetchAll();

            // Categorias dos eventos (multi-fonte)
            $stmt = $this->db->prepare("
                SELECT 
                    category,
                    COUNT(*) as total_mentions,
                    COUNT(DISTINCT title) as unique_topics,
                    GROUP_CONCAT(DISTINCT neighborhood SEPARATOR ', ') as neighborhoods
                FROM city_events
                WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                AND is_active = 1
                AND source NOT IN ('seed')
                GROUP BY category
            ");
            $stmt->execute([$hours]);
            $eventCats = $stmt->fetchAll();

            // Merge
            $merged = [];
            foreach ($chatCats as $c) {
                $cat = $c['category'];
                $merged[$cat] = [
                    'category' => $cat,
                    'total_mentions' => (int)$c['total_mentions'],
                    'unique_topics' => (int)$c['unique_topics'],
                    'avg_sentiment' => round((float)$c['avg_sentiment'], 2),
                    'neighborhoods_raw' => $c['neighborhoods'] ?: '',
                ];
            }
            foreach ($eventCats as $c) {
                $cat = $c['category'];
                if (isset($merged[$cat])) {
                    $merged[$cat]['total_mentions'] += (int)$c['total_mentions'];
                    $merged[$cat]['unique_topics'] += (int)$c['unique_topics'];
                    if ($c['neighborhoods']) {
                        $merged[$cat]['neighborhoods_raw'] .= ', ' . $c['neighborhoods'];
                    }
                } else {
                    $merged[$cat] = [
                        'category' => $cat,
                        'total_mentions' => (int)$c['total_mentions'],
                        'unique_topics' => (int)$c['unique_topics'],
                        'avg_sentiment' => $this->inferSentiment($cat),
                        'neighborhoods_raw' => $c['neighborhoods'] ?: '',
                    ];
                }
            }

            // Formatar
            $categoryIcons = [
                'seguranca'         => '🔒',
                'transito'          => '🚦',
                'saude'             => '🏥',
                'educacao'          => '📚',
                'eventos'           => '🎉',
                'politica'          => '🏛',
                'clima'             => '🌤',
                'infraestrutura'    => '🏗',
                'cultura'           => '🎭',
                'economia'          => '💰',
                'meio_ambiente'     => '🌿',
                'esporte'           => '⚽',
                'tecnologia'        => '💻',
                'servicos_publicos' => '🏢',
                'outros'            => '📌',
            ];

            $categories = [];
            foreach ($merged as $cat => &$data) {
                $neighborhoods = array_unique(array_filter(array_map('trim', explode(',', $data['neighborhoods_raw']))));
                $data['icon'] = $categoryIcons[$cat] ?? '📌';
                $data['neighborhoods'] = array_values($neighborhoods);
                unset($data['neighborhoods_raw']);
                $categories[] = $data;
            }

            usort($categories, fn($a, $b) => $b['total_mentions'] - $a['total_mentions']);

            return [
                'success' => true,
                'data'    => $categories,
            ];
        } catch (Exception $e) {
            error_log("TrendingController categories error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Falha ao buscar categorias'];
        }
    }

    private function getTotalMessages($hours) {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as cnt FROM city_messages WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR) AND role = 'user'"
        );
        $stmt->execute([$hours]);
        return (int)$stmt->fetch()['cnt'];
    }

    private function getTotalEvents($hours) {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as cnt FROM city_events WHERE is_active = 1 AND source != 'seed' AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)"
        );
        $stmt->execute([$hours]);
        return (int)$stmt->fetch()['cnt'];
    }

    private function getTotalTopics($hours) {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT topic) as cnt FROM city_topics WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)"
        );
        $stmt->execute([$hours]);
        return (int)$stmt->fetch()['cnt'];
    }

    private function sentimentLabel($value) {
        if ($value > 0.3) return 'positivo';
        if ($value < -0.3) return 'negativo';
        return 'neutro';
    }

    /**
     * Inferir sentimento a partir da categoria
     */
    private function inferSentiment($category) {
        if (in_array($category, $this->negativeCats)) return -0.5;
        if (in_array($category, $this->positiveCats)) return 0.5;
        return 0.0;
    }

    /**
     * Label legível da fonte
     */
    private function sourceLabel($source) {
        $labels = [
            'news'            => '📰 Notícia',
            'weather_api'     => '🌤 Clima',
            'air_quality_api' => '🏭 Qualidade do Ar',
            'holidays_api'    => '🎉 Feriado',
            'transit'         => '🚦 Trânsito',
            'defesa_civil'    => '🚨 Defesa Civil',
            'chat'            => '💬 Chat',
            'auto'            => '🤖 Auto-detectado',
        ];
        return $labels[$source] ?? '📌 ' . ucfirst($source);
    }
}
